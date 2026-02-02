import argparse
import json
import logging
import math
import os
import random
from collections import namedtuple
from typing import Union

import cv2
import pandas as pd
import numpy as np
from PIL import Image
from segment_anything import SamPredictor, sam_model_registry

PointAnnotation = namedtuple(
    "Annotation",
    ["x", "y", "label", "annotation_id", "image_id"],
)


def shift_contour(arr: list[float], x_off: float, y_off: float) -> list:
    """Shift the contour on x and y to move the prediction

    Args:
        arr: array whose coordinates to shift
        x_off: how much should the coordinates be shifted on the x axis
        y_off: how much should the coordinates be shifted on the y axis

    Returns:
        shifted contour

    """
    # contours are of type [x1, y1, x2, y2...]
    return [c + x_off if idx % 2 == 0 else c + y_off for idx, c in enumerate(arr)]


def super_zoom_sam(
    ann_point: np.ndarray,
    sam: SamPredictor,
    expected_area: float,
    x_off: float,
    y_off: float,
    image_area: float,
) -> tuple[list, float] | tuple[None, None]:
    """
    Apply the Segment anything model after zooming in
    Args:
       ann_point: Coordinates of the point from which to create the polygon
       sam: Segment anything model predictor
       image_area:
       expected_area:
       x_off: offset for x coordinates
       y_off: offset for y coordinates
       image_area:

    Returns:
        tuple containing the contour and contour area, or tuple of None if no contour is found
    """
    cropped_masks, cropped_scores, _ = sam.predict(
        point_coords=ann_point,
        point_labels=np.array([1]),
        multimask_output=True,
    )
    contour, contour_area = mask_to_contour(
        cropped_masks, image_area, ann_point, cropped_scores, expected_area
    )
    if contour is None or contour_area is None:
        return None, None
    contour = shift_contour(contour, x_off, y_off)
    return contour, contour_area


def inaccurate_annotation_sam(
    crop_ann_point: np.ndarray,
    x_off: int,
    y_off: int,
    croppedSAM: SamPredictor,
    image_area: int,
    expected_area: int,
) -> tuple[list, float] | tuple[None, None]:
    """
    Try to generate a somewhat inaccurate prediction by moving the mask off for some values
    Args:
        crop_ann_point: Annotation point array
        x_off: by how much we are off in terms of X coordinates
        y_off: by how much we are off in terms of Y coordinates
        croppedSAM: Predictor that will execute on the cropped image
        image_area: global image area
        expected_area: expected area of the new annotation

    Returns:
        Tuple containing the contour and its area, tuple of None if no contour was found
    """
    sam_label = np.array([1])

    allcontours = []
    allareas = []

    contour, contour_area = None, None

    # generate all the possible variations of the annotation point (5 pixels in each direction)
    posvariation = [(x, y) for x in [5, 0, -5] for y in [5, 0, -5] if x != 0 or y != 0]
    for xvar, yvar in posvariation:
        # create a variation of the original point
        offpoint = np.array([[crop_ann_point[0] + xvar, crop_ann_point[1] + yvar]])
        # get the results from SAM
        masks, scores, _ = croppedSAM.predict(
            point_coords=offpoint, point_labels=sam_label, multimask_output=True
        )
        contour, contour_area = mask_to_contour(
            masks, image_area, [crop_ann_point], scores, expected_area
        )
        if (
            contour is not None
            and contour_area is not None
            and contour_area / image_area <= 0.05
        ):
            allcontours.append(contour)
            allareas.append(contour_area)

    if len(allcontours) == 0:
        return None, None

    elif len(allcontours) == 1:
        contour = allcontours[0]
        contour_area = allareas[0]

    elif len(allcontours) > 1:
        idx = np.argmin(np.abs(np.array(allareas) - expected_area))
        contour = allcontours[idx]
        contour_area = allareas[idx]

    contour = shift_contour(contour, x_off, y_off)
    return contour, contour_area


def multipoint_sam(
    crop_ann_point: np.ndarray,
    x_off: float,
    y_off: float,
    croppedSAM: SamPredictor,
    expected_area: float,
    image_area: float,
) -> tuple[list, float] | tuple[None, None]:
    """
     Generate SAM prediction by adding two random points around the point annotation
     Args:
         crop_ann_point: Annotation point array
         x_off: by how much we are off in terms of X coordinates
         y_off: by how much we are off in terms of Y coordinates
         croppedSAM: Predictor that will execute on the cropped image
         expected_area: expected area of the new annotation
         image_area: global image area

    Returns:
         Tuple containing the contour and the area. If unable to find a contour, None
    """
    crop_size = 512

    include_point1 = generate_random_circle_point(
        crop_ann_point, crop_size, np.random.randint(1, 5)
    )
    include_point2 = generate_random_circle_point(
        crop_ann_point, crop_size, np.random.randint(1, 5)
    )

    pospoints = np.array([crop_ann_point, include_point1, include_point2])
    sam_label = np.array([1, 1, 1])

    masks, scores, _ = croppedSAM.predict(
        point_coords=pospoints, point_labels=sam_label, multimask_output=True
    )

    contour, contour_area = mask_to_contour(
        masks, image_area, [crop_ann_point], scores, expected_area
    )

    if contour is None or contour_area is None:
        return None, None

    contour = shift_contour(contour, x_off, y_off)
    return contour, contour_area


def generate_random_circle_point(
    center: np.ndarray, crop_size: int, radius: int
) -> list:
    """
    Generate random circle point near the given center
    Args:
        center: Coordinates of the center of the circle
        radius: Radius from the given center

    Returns:
        List containg the X and Y coordinates of the point in the circle
    """
    x = -1
    y = -1
    while x < 0 or x > crop_size or y < 0 or y > crop_size:
        # random angle
        alpha = random.random() * math.pi * 2
        x, y = math.ceil(center[0] + radius * math.cos(alpha)), math.ceil(
            center[1] + radius * math.sin(alpha)
        )
    return [x, y]


def negative_point_sam(
    crop_ann_point: np.ndarray,
    x_off: float,
    y_off: float,
    croppedSAM: SamPredictor,
    expected_area: float,
    image_area: float,
) -> tuple[list, float] | tuple[None, None]:
    """
     Apply the Segment Anything Model by estimating points that should not be part of the annotation

     Args:
         crop_ann_point: Annotation point array
         x_off: by how much we are off in terms of X coordinates
         y_off: by how much we are off in terms of Y coordinates
         croppedSAM: Predictor that will execute on the cropped image
         expected_area: expected area of the new annotation
         image_area: global image area

    Returns:
         tuple containing contour and contour area or of None if unable to find one
    """
    dist = np.sqrt(expected_area * 2 / np.pi)

    crop_size = 512
    radius = min(dist + random.randint(5, 10), crop_size // 2)

    # generate 2 random points on the circle around the annotation
    exclude_point1 = generate_random_circle_point(crop_ann_point, crop_size, radius)
    exclude_point2 = generate_random_circle_point(crop_ann_point, crop_size, radius)
    exclude_point3 = generate_random_circle_point(crop_ann_point, crop_size, radius)
    exclude_point4 = generate_random_circle_point(crop_ann_point, crop_size, radius)
    exclude_point5 = generate_random_circle_point(crop_ann_point, crop_size, radius)
    # generate the SAM annotation points array with 1 positive and 2 negative points
    pos_neg_points = np.array(
        [
            crop_ann_point,
            exclude_point1,
            exclude_point2,
            exclude_point3,
            exclude_point4,
            exclude_point5,
        ]
    )
    # the respective label 1 -> positive, 0 -> negative
    sam_label = np.array([1, 0, 0, 0, 0, 0])
    # get the results from SAM
    masks, scores, _ = croppedSAM.predict(
        point_coords=pos_neg_points, point_labels=sam_label, multimask_output=True
    )
    # convert the masks to contours
    contour, contour_area = mask_to_contour(
        masks, image_area, crop_ann_point, scores, expected_area
    )
    # if there is no contour return None
    if contour is None or contour_area is None:
        return None, None

    contour = shift_contour(contour, x_off, y_off)
    return contour, contour_area


def zoom_sam(
    ann_point: np.ndarray,
    sam: SamPredictor,
    expected_area: float,
    x_off: float,
    y_off: float,
    image_area: float,
) -> tuple[list, float] | tuple[None, None]:
    """
    Apply the Segment Anything Model while zooming in the annotation

    Args:
        ann_point: Annotation coordinates
        sam: SAM predictor object expected_area: expected annotation area
        expected_area: Expected area of the converted annotation
        x_off: where the x of the annotation is moved
        y_off: where the y of the annotation is moved
        image_area: Area of the overall image

    Returns:
        tuple containing the predicted contour and the area
    """
    cropped_masks, scores, _ = sam.predict(
        point_coords=ann_point,
        point_labels=np.array([1]),
        multimask_output=True,
    )

    contour, contour_area = mask_to_contour(
        cropped_masks, image_area, ann_point, scores, expected_area
    )
    if contour is None or contour_area is None:
        return None, None

    contour = shift_contour(contour, x_off, y_off)
    return contour, contour_area


def annotation_is_out_of_bounds(
    ann_point: np.ndarray, img_width: int, img_height: int
) -> bool:
    """
    Return whether the annotation is invalid (the contour is inside the image)

    Args:
        ann_point: annotation coordinates
        img_width: Image width
        img_height: Image height

    Returns:
       Returns True if the annotation is out of bounds, False if it is not
    """
    if (
        ann_point[0] < 0
        or ann_point[1] < 0
        or ann_point[0] > img_width
        or ann_point[1] > img_height
    ):
        return True
    return False


def annotation_is_compatible(
    contour: list | None,
    contour_area: float | None,
    img_area: float,
    threshold: float,
    expected_annotation_area: float | None,
) -> bool:
    """
    Returns whether the annotation found is compatible with the expected annotation area
    Args:
        contour: List of points
        contour_area: Area if the found contour
        img_area: Area of the whole image
        threshold: Threshold representing the maximum area of the contour in reference with the image area
        expected_annotation_area: Expected annotation area

    Returns:
        Returns True if contour is not None, if the ratio between contour area and threshold is within the threshold and if the contour area is not too small or big in reference with the expecectation. Returns False otherwise
    """
    if (
        contour is not None
        and contour_area is not None
        and contour_area / img_area <= threshold
    ):
        if (
            expected_annotation_area is None
            or contour_area <= (expected_annotation_area * 1.75)
            and contour_area >= (expected_annotation_area * 0.25)
        ):
            return True
    return False


def get_point_contour(
    contour: list, point: PointAnnotation | tuple | np.ndarray
) -> bool:
    """
    Get whether the point is contained in the contour

    Args:
        contour: possible contour
        point: Point used to filter a valid contour

    Returns:
       Returns True if the contour contains the point, False otherwise
    """
    if isinstance(point, PointAnnotation):
        point = np.array((point.x, point.y))
    if len(point) == 1:
        point = point[0]

    return contour is not None and cv2.pointPolygonTest(contour, point, False) >= 0


def get_best_contour(
    contours: list[tuple[list, int]], expected_area: float
) -> tuple[None, None] | tuple[list, float]:
    """
    Get the contours whose area is closest to the expected area

    Args:
        contours: list of contours to sort
        expected_area: expected area

    Returns:
        Contour that has the area closest to the expected area
    """
    if not len(contours) or all(x[1] is None for x in contours):
        return None, None
    elif len(contours) == 1 and contours[0][0] is not None:
        return contours[0][0], contours[0][1]
    absdiff = [np.abs(x[1] - expected_area) for x in contours if x[1] is not None]
    if len(absdiff) == 0:
        return None, None
    idx = np.argmin(absdiff)
    return contours[idx]


def mask_to_contour(
    masks: np.ndarray,
    image_area: float,
    point: list,
    scores: list,
    expected_area: float,
) -> tuple[list, float] | tuple[None, None]:
    """
    Converts the predicted mask to a contour. Validates the contours.
    Args:
        masks: Resulting mask from the SAM prediction
        image_area:
        point: The input point annotation
        scores: Prediction scores from SAM prediction
        expected_area: Expected prediction area

    Returns:
        tuple of None if the masks are invalid, the contour and its area otherwise
    """
    valid_contours = []
    indices = np.argsort(scores)[::-1]
    sorted_masks = [masks[idx] for idx in indices]
    sorted_contours = [
        transform_mask(mask, point) for mask in sorted_masks if mask is not None
    ]
    sorted_contours = [value for value in sorted_contours if value[0] is not None]

    for contour, contour_area in sorted_contours:
        if annotation_is_compatible(
            contour, contour_area, image_area, 0.05, expected_area
        ):
            valid_contours.append((contour, contour_area))

    if len(valid_contours) == 0:
        return None, None
    result = get_best_contour(valid_contours, expected_area)
    if result[0] is not None:
        return result
    else:
        return None, None


def transform_mask(
    mask: np.ndarray, point: PointAnnotation | np.ndarray
) -> tuple[list, float] | tuple[None, None]:
    """Transform a mask into a contour

    Args:
        mask: an input mask
        point: point annotation with coordinates

    Returns:
        if found, the contours found in the mask, else None

    """
    if mask.any():
        mask = mask * 255
        mask = mask.astype(np.uint8)
        contour, _ = cv2.findContours(mask, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)
        contour = contour[0]

        if get_point_contour(contour, point) and len(contour := contour.squeeze()) > 2:
            return contour.flatten().tolist(), cv2.contourArea(contour)
    return None, None


def process_expected_area(
    annotation: PointAnnotation,
    image: np.ndarray,
    sam: SamPredictor,
) -> dict:
    """Process an annotation with the objective to gather its expected area.

    Args:
        annotation: starting PointAnnotation
        image: Image array
        sam: SAM object

    Returns:
        dict containing the converted annotation and the expected area

    """
    img_height, img_width, _ = image.shape
    img_area = img_height * img_width
    label_id = annotation.label
    point_annotation = np.array([[annotation.x, annotation.y]])
    if annotation_is_out_of_bounds(point_annotation[0], img_width, img_height):
        return {}

    masks, scores, _ = sam.predict(
        point_coords=point_annotation, point_labels=np.array([1]), multimask_output=True
    )
    valid_contours = []

    try:
        indices = np.argsort(scores)[::-1]
        sorted_masks = [masks[idx] for idx in indices]
        sorted_contours = [
            transform_mask(mask, point_annotation[0])
            for mask in sorted_masks
            if mask is not None
        ]
        sorted_contours = [values for values in sorted_contours if values is not None]

        for contour, contour_area in sorted_contours:
            if annotation_is_compatible(contour, contour_area, img_area, 0.05, None):
                valid_contours.append((contour, contour_area))

    except ValueError:
        pass

    if len(valid_contours) == 0:
        return {
            "annotation_id": annotation.annotation_id,
            "label_id": label_id,
            "image_id": annotation.image_id,
            "possible_contours": None,
            "contour_area": np.nan,
            "point_annotation": annotation,
        }

    return {
        "annotation_id": annotation.annotation_id,
        "label_id": label_id,
        "image_id": annotation.image_id,
        "possible_contours": valid_contours,
        "contour_area": valid_contours[0][1],
        "point_annotation": annotation,
    }


def process_annotation(
    annotation: PointAnnotation,
    image_id: int,
    image: np.ndarray,
    sam: SamPredictor,
    expected_area: int,
) -> Union[dict, None]:
    """
    Process point annotation and try to convert it

    Args:
        annotation: Point annotation object to convert
        image_id: ID of the image to which the annotation refers to
        image: Unsharpened input image
        sam: SAM predictor object
        expected_area: expected area for the conversion

    Returns:
        Converted annotation if successful, None otherwise
    """
    crop_size = 1024
    image_area = image.shape[0] * image.shape[1]

    if expected_area * 0.25 > crop_size**2 or crop_size**2 > image_area:
        return {}

    label_id = annotation.label
    ann_point = np.array([[annotation.x, annotation.y]])

    x_off, y_off, annotation_crop = crop_annotation(
        image, ann_point[0], crop_size=crop_size
    )

    crop_ann_point = np.array([[ann_point[0][0] - x_off, ann_point[0][1] - y_off]])

    sam.set_image(annotation_crop)

    contour, contour_area = zoom_sam(
        crop_ann_point, sam, expected_area, x_off, y_off, image_area
    )

    if contour is not None:
        return {
            "image_id": image_id,
            "label_id": label_id,
            "annotation_id": annotation.annotation_id,
            "contour_area": contour_area,
            "points": contour,
            "method": "zoom",
        }

    crop_size = 512
    if expected_area * 0.25 > crop_size**2 or crop_size**2 > image_area:
        return {}

    x_off, y_off, annotation_crop = crop_annotation(
        image, ann_point[0], crop_size=crop_size
    )

    crop_ann_point = np.array([[ann_point[0][0] - x_off, ann_point[0][1] - y_off]])
    sam.set_image(annotation_crop)

    contour, contour_area = super_zoom_sam(
        crop_ann_point, sam, expected_area, x_off, y_off, image_area
    )

    if contour is not None and contour_area is not None:
        return {
            "image_id": image_id,
            "label_id": label_id,
            "points": contour,
            "annotation_id": annotation.annotation_id,
            "contour_area": contour_area,
            "method": "superzoom",
        }

    contour, contour_area = negative_point_sam(
        crop_ann_point[0], x_off, y_off, sam, expected_area, image_area
    )

    if contour is not None and contour_area is not None:
        return {
            "image_id": image_id,
            "label_id": label_id,
            "annotation_id": annotation.annotation_id,
            "contour_area": contour_area,
            "points": contour,
            "method": "negative",
        }

    contour, contour_area = multipoint_sam(
        crop_ann_point[0], x_off, y_off, sam, expected_area, image_area
    )

    if contour is not None and contour_area is not None:
        return {
            "image_id": image_id,
            "annotation_id": annotation.annotation_id,
            "label_id": label_id,
            "points": contour,
            "contour_area": contour_area,
            "method": "multipoint",
        }

    contour, contour_area = inaccurate_annotation_sam(
        crop_ann_point[0], x_off, y_off, sam, image_area, expected_area
    )

    if annotation_is_compatible(contour, contour_area, image_area, 0.05, expected_area):
        return {
            "image_id": image_id,
            "label_id": label_id,
            "annotation_id": annotation.annotation_id,
            "points": contour,
            "contour_area": contour_area,
            "method": "inaccurate",
        }

    return {}


def crop_annotation(
    image: np.ndarray, annotation_point: np.ndarray, crop_size: int = 1024
) -> list:
    """
    Crop the input image annotation
    Args:
        image : Input image
        annotation_point : Array with annotation coordinates
        cropsize : Size of the crop to apply to the image
    Returns:
        List containing translated coordinates and image
    """
    x_offset = min(
        max(0, int(annotation_point[0] - crop_size / 2)), image.shape[1] - crop_size
    )
    y_offset = min(
        max(0, int(annotation_point[1] - crop_size / 2)), image.shape[0] - crop_size
    )
    return [
        x_offset,
        y_offset,
        image[y_offset : y_offset + crop_size, x_offset : x_offset + crop_size],
    ]


def annotation_is_compatible_with_expected_area(
    annotation_area: float,
    expected_area: float,
    lower_bound: float = 0.25,
    upper_bound: float = 1.75,
) -> bool:
    return (
        annotation_area > lower_bound * expected_area
        and annotation_area < upper_bound * expected_area
    )


if __name__ == "__main__":
    argparser = argparse.ArgumentParser()
    argparser.add_argument(
        "--image-paths-file",
        "-i",
        type=str,
        required=True,
        help="Path to the image to apply point to polygon to",
    )
    argparser.add_argument(
        "--input-file",
        type=str,
        required=True,
        help="Input file containing the annotations",
    )

    argparser.add_argument("--model-type", type=str, help="Model type")
    argparser.add_argument("--model-path", type=str, help="Path to model weights")
    argparser.add_argument(
        "--output-file",
        type=str,
        help="Where to save the resulting predictions",
        default=".",
    )
    args = argparser.parse_args()
    logging.basicConfig(filename="/var/www/pythonLogs.txt", level="WARNING")
    annotations = []
    input_values = {}
    with open(args.input_file, "r") as inp:
        input_values = json.load(inp)

    with open(args.image_paths_file, "r") as inp:
        image_paths = json.load(inp)

    sam_model = sam_model_registry[args.model_type](checkpoint=args.model_path)
    sam_model.to("cuda")
    sam = SamPredictor(sam_model)

    resulting_annotations = []

    for image_id, annotations in input_values.items():
        if len(annotations) == 0:
            logging.error(f"No annotations to load for image with id {image_id}!")
            continue
        image_path = image_paths.get(image_id)
        if image_path is None:
            logging.error(f"Image path for {image_id} not found!")
            continue
        image = np.array(Image.open(image_path))
        sam.set_image(image)

        for annotation in annotations:
            resulting_annotations.append(
                process_expected_area(
                    PointAnnotation(
                        annotation["points"][0],
                        annotation["points"][1],
                        annotation["label"],
                        annotation["annotation_id"],
                        image_id,
                    ),
                    image,
                    sam,
                )
            )

    expected_areas = pd.DataFrame(resulting_annotations).sort_values(
        "contour_area", ascending=False
    )

    if expected_areas.empty:
        logging.error("Unable to compute any annotations for expected area!")
        exit(0)

    expected_area_values = (
        expected_areas.dropna()
        .groupby("label_id")
        .apply(lambda x: x.contour_area.median())
        .to_dict()
    )

    resulting_annotations = []

    for image_id in expected_areas.image_id.unique():
        # here we have already the annotations from base SAM via the expected annotations
        precomputed_annotations = expected_areas.query("image_id == @image_id")
        image_path = image_paths.get(image_id)
        if image_path is None:
            logging.error(f"Image path for {image_id} not found!")
            continue
        image = np.array(Image.open(image_path))
        for _, row in precomputed_annotations.iterrows():
            expected_area = expected_area_values.get(row.label_id)
            if expected_area is None:
                continue

            # If a contour was already computed and is valid let's use it
            if (
                row.possible_contours is not None
                and len(
                    (
                        contours := [
                            (c, area)
                            for c, area in row.possible_contours
                            if annotation_is_compatible_with_expected_area(
                                area, expected_area
                            )
                        ]
                    )
                )
                > 0
            ):
                contour, contour_area = get_best_contour(contours, expected_area)
                if contour is not None and contour_area is not None:
                    resulting_annotations.append(
                        {
                            "image_id": image_id,
                            "label_id": row.label_id,
                            "annotation_id": row.annotation_id,
                            "points": contour,
                            "method": "base",
                            "contour_area": contour_area,
                        }
                    )
                    continue

            resulting_annotations.append(
                process_annotation(
                    row.point_annotation, row.image_id, image, sam, expected_area
                )
            )

    resulting_annotations = pd.DataFrame(resulting_annotations).dropna(how="all")

    if not resulting_annotations.empty:
        os.makedirs(os.path.dirname(args.output_file), exist_ok=True)
        resulting_annotations.loc[
            :, ["annotation_id", "points", "image_id", "label_id"]
        ].to_csv(args.output_file, index=False)
