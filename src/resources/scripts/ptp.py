import argparse
import json
import logging
import math
import os
import random
from collections import namedtuple
from typing import Union
import time

import cv2
import pandas as pd
import numpy as np
from PIL import Image
from segment_anything import SamPredictor, sam_model_registry

PointAnnotation = namedtuple(
    "Annotation",
    ["x", "y", "label", "annotation_id", "image_id"],
)


def super_zoom_sam(
    ann_point: np.ndarray, image: np.ndarray, sam: SamPredictor, expected_area: float, x_off:float, y_off:float

) -> tuple:
    """
    Apply the Segment anything model after zooming in
    Args:
       ann_point: Coordinates of the point from which to create the polygon
       unsharp_image: Unsharpened image
       sam: Segment anything model predictor
       prev_area: expected area

    Returns:
        List containing new predicted polygon and objects used for predicting the new
    """
    cropped_masks, cropped_scores, _ = sam.predict(
        point_coords=ann_point,
        point_labels=np.array([1]),
        multimask_output=True,
    )
    contour, contour_area = mask_to_contour(
        cropped_masks, 512, 512, ann_point, cropped_scores, expected_area
    )
    # if there is no contour return None
    if contour is None:
        return None, None
    # add the offset to the contour
    contour[::2] += x_off
    contour[1::2] += y_off
    return contour, contour_area


def inaccurate_annotation_sam(
    crop_ann_point: np.ndarray,
    x_off: int,
    y_off: int,
    croppedSAM: SamPredictor,
    img_area: int,
    expected_area: int,
) -> list:
    """
    Try to generate a somewhat inaccurate prediction by moving the mask off for some values
    Args:
        crop_ann_point: Annotation point array
        x_off: by how much we are off in terms of X coordinates
        y_off: by how much we are off in terms of Y coordinates
        croppedSAM: Predictor that will execute on the cropped image
        img_area: global image area
        expected_area: expected area of the new annotation

    Returns:
        List containing coordinates results and SAM object
    """
    # we only have one point all the time soe we create one positive label and reuse it
    sam_label = np.array([1])

    # arrays to hold the contours and their areas
    allcontours = []
    allareas = []

    # generate all the possible variations of the annotation point (5 pixels in each direction)
    posvariation = [(x, y) for x in [5, 0, -5] for y in [5, 0, -5] if x != 0 or y != 0]
    for xvar, yvar in posvariation:
        # create a variation of the original point
        offpoint = np.array([[crop_ann_point[0] + xvar, crop_ann_point[1] + yvar]])
        # get the results from SAM
        masks, scores, _ = croppedSAM.predict(
            point_coords=offpoint, point_labels=sam_label, multimask_output=True
        )
        # convert the masks to contours
        contour, contour_area = mask_to_contour(
            masks, 1024, 1024, [crop_ann_point], scores, expected_area
        )
        # if there is a contour/area add it to the list, otherwise just discard it
        if contour is not None and contour_area / img_area <= 0.04:
            allcontours.append(contour)
            allareas.append(contour_area)

    # if no valid contour could be found reutrn None
    if len(allcontours) == 0:
        return [None, None]
    # if there is only one contour take it
    elif len(allcontours) == 1:
        contour = allcontours[0]
        contour_area = allareas[0]
    # if more then one contour was found compare to known areas
    elif len(allcontours) > 1:
        # get the minimum difference between previous area and current area
        idx = np.argmin(np.abs(np.array(allareas) - expected_area))
        # take the contour with the smallest difference
        contour = allcontours[idx]
        contour_area = allareas[idx]

    # add the offset to the contour
    contour[::2] += x_off
    contour[1::2] += y_off
    return [contour, contour_area]


# generate 2 additional positive point in the vicinity of the annotation
def multipoint_sam(
    crop_ann_point: np.ndarray,
    x_off: int,
    y_off: int,
    croppedSAM: SamPredictor,
    expected_area: int,
) -> list:
    """
     Generate SAM prediction by adding two random points around the point annotation
     Args:
         crop_ann_point: Annotation point array
         x_off: by how much we are off in terms of X coordinates
         y_off: by how much we are off in terms of Y coordinates
         croppedSAM: Predictor that will execute on the cropped image
         img_area: global image area
         expected_area: expected area of the new annotation

    Returns:
         List containing coordinates results and SAM object
    """
    # generate 2 random points in the vicinity of the point annotation
    include_point1 = generate_random_circle_point(
        crop_ann_point, np.random.randint(1, 5)
    )
    include_point2 = generate_random_circle_point(
        crop_ann_point, np.random.randint(1, 5)
    )
    # sam annotation points array with original and 2 random additional points
    pospoints = np.array([crop_ann_point, include_point1, include_point2])
    # the respective label 1 -> positive, 0 -> negative
    sam_label = np.array([1, 1, 1])
    # get the results from SAM
    masks, scores, _ = croppedSAM.predict(
        point_coords=pospoints, point_labels=sam_label, multimask_output=True
    )
    # convert the masks to contours
    contour, contour_area = mask_to_contour(
        masks, 1024, 1024, [crop_ann_point], scores, expected_area
    )
    # if there is no contour return None
    if contour is None:
        return [None, None]

    # add the offset to the contour
    contour[::2] += x_off
    contour[1::2] += y_off
    return [contour, contour_area]


def generate_random_circle_point(center: np.ndarray, radius: int) -> list:
    """
    Generate random circle point near the given center
    Args:
        center: Coordinates of the center of the circle
        radius: Radius from the given center

    Returns:
        List containg the X and Y coordinates of the point in the circle
    """
    # initialize x and y with invalid values
    x = -1
    y = -1
    # until the point is inside the image...
    while x < 0 or x > 1024 or y < 0 or y > 1024:
        # ... generate a random angle
        alpha = random.random() * math.pi * 2
        # ... and calculate the x and y coordinates with the given radius
        x, y = math.ceil(center[0] + radius * math.cos(alpha)), math.ceil(
            center[1] + radius * math.sin(alpha)
        )
    # when a valid point was generated return it
    return [x, y]


def negative_point_sam(
    crop_ann_point: np.ndarray,
    x_off: int,
    y_off: int,
    croppedSAM: SamPredictor,
    expected_area: int,
) -> tuple:
    """
     Apply the Segment Anything Model by estimating points that should not be part of the annotation

     Args:
         crop_ann_point: Annotation point array
         x_off: by how much we are off in terms of X coordinates
         y_off: by how much we are off in terms of Y coordinates
         croppedSAM: Predictor that will execute on the cropped image
         img_area: global image area
         expected_area: expected area of the new annotation

    Returns:
         tuple contour and contour area
    """
    # Let's estimate that a point that is outside the radius of a circle with an area double what we expect is not correct for the annotation
    if expected_area is None:
        return None, None
    dist = np.sqrt(expected_area * 2 / np.pi)
    radius = dist + random.randint(5, 10)

    # because the object is center a radius bigger than the distance to the edge doesn't make sense + we add a small margin so return None instead
    if radius > 400:
        return None, None
    # generate 2 random points on the circle around the annotation
    exclude_point1 = generate_random_circle_point(crop_ann_point, radius)
    exclude_point2 = generate_random_circle_point(crop_ann_point, radius)
    exclude_point3 = generate_random_circle_point(crop_ann_point, radius)
    exclude_point4 = generate_random_circle_point(crop_ann_point, radius)
    exclude_point5 = generate_random_circle_point(crop_ann_point, radius)
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
        masks, 1024, 1024, crop_ann_point, scores, expected_area
    )
    # if there is no contour return None
    if contour is None:
        return None, None

    # add the offset to the contour
    contour[::2] += x_off
    contour[1::2] += y_off
    return contour, contour_area


def zoom_sam(
    ann_point: np.ndarray, sam: SamPredictor, expected_area: float, x_off:float, y_off:float
) -> tuple:
    """
    Apply the Segment Anything Model while zooming in the annotation

    Args:
        ann_point: Annotation coordinates
        sam: SAM predictor object
        expected_area: expected annotation area
        x_off: where the x of the annotation is moved
        y_off: where the y of the annotation is moved

    Returns:
        tuple containing the predicted contour and the area
    """
    cropped_masks, scores, _ = sam.predict(
        point_coords=ann_point,
        point_labels=np.array([1]),
        multimask_output=True,
    )
    # convert the mask of SAM to contour
    contour, contour_area = mask_to_contour(
        cropped_masks, 1024, 1024, ann_point, scores, expected_area
    )
    # if there is no contour return None
    if contour is None:
        return None, None
    # add the offset to the contour
    contour[::2] += x_off
    contour[1::2] += y_off
    return contour, contour_area


# check if an annotation is oob
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
       True if the annotation is out of bounds, False if it is
    """
    # if annotation is out of bounds
    if (
        ann_point[0] < 0
        or ann_point[1] < 0
        or ann_point[0] > img_width
        or ann_point[1] > img_height
    ):
        # return True -> invalid annotation
        return True
    return False


# test if the current contour is valid "enough" or if we should try something else
def annotation_is_compatible(
    contour: list,
    contour_area: float,
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


def get_point_contour(contours: list, point: PointAnnotation|tuple|np.ndarray) -> list:
    """
    Get the contour that contains the desired point

    Args:
        contours: List of possible contours
        point: Point used to filter a valid contour

    Returns:
       A list containing the coordinates for a valid contour containing the point.
    """
    if isinstance(point, PointAnnotation):
        point = np.array((point.x, point.y))

    return [c for c in contours if c is not None and cv2.pointPolygonTest(np.array(c), point, False) >= 0]


def get_best_contour(
    contours: list[tuple[list, int]], expected_area: int
) -> list | None:
    """
    Get the mask that is closest to the expected area

    Args:
        contours:
        point:
        expected_area:

    Returns:

    """
    if not len(contours) or all(lambda x: x[1] is None for x in contours):
        return None
    elif len(contours) == 1:
        return contours[0][0]
    results = []
    absdiff = [np.abs(x[1] - expected_area) for x in contours if x[1] is not None]
    if len(absdiff) == 0:
        return None
    idx = np.argmin(absdiff)
    return results[idx]


def mask_to_contour(
    masks: np.ndarray,
    img_height: int,
    img_width: int,
    point: list,
    scores: list = [],
    expected_area: float = 9999999999,
) -> tuple:
    """
    Converts the predicted mask to a contour
    Args:
        masks: Resulting mask from the SAM prediction
        img_height: Image height
        img_width: Image width
        point: The input point annotation
        scores: Prediction scores from SAM prediction
        multimask: Whether the prediction is multimask
        expected_area: Expected prediction area

    Returns:
        List of coordinates of predictions if valid. List of None otherwise
    """
    valid_contours = []
    indices = np.argsort(scores)[::-1]
    sorted_masks = [masks[idx] for idx in indices]
    sorted_contours = [transform_mask(mask) for mask in sorted_masks if mask is not None]
    sorted_contours = get_point_contour(sorted_contours, point[0])

    for contour in sorted_contours:
        contour_area = cv2.contourArea(np.array(contour))
        if annotation_is_compatible(contour, contour_area, img_height * img_width, 0.2, expected_area):
            valid_contours.append((contour, cv2.contourArea(np.array(contour))))

    if len(valid_contours) == 0:
        return None, None

    return valid_contours[0][0], valid_contours[0][1]


def transform_mask(mask: np.ndarray) -> list:
    """Transform a mask into a contour

    Args:
        mask:

    Returns:

    """
    if mask.any():
        mask = mask * 255
        mask = mask.astype(np.uint8)
        contour, _ = cv2.findContours(mask, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)

        return contour[0].tolist()


def process_expected_area(
    annotation: PointAnnotation,
    image: np.ndarray,
    sam: SamPredictor,
) -> dict:
    img_height, img_width, _ = image.shape
    img_area = img_height * img_width
    label_id = annotation.label
    point_annotation = np.array([annotation.x, annotation.y])
    if annotation_is_out_of_bounds(point_annotation, img_width, img_height):
        return {}
    point_annotation = np.array([point_annotation])

    masks, scores, _ = sam.predict(
        point_coords=point_annotation, point_labels=np.array([1]), multimask_output=True
    )
    valid_contours = []
    try:
        indices = np.argsort(scores)[::-1]
        sorted_masks = [masks[idx] for idx in indices]
        sorted_contours = [transform_mask(np.reshape(mask, (img_height, img_width))) for mask in sorted_masks if mask is not None]
        sorted_contours = get_point_contour(sorted_contours, annotation)

        for contour in sorted_contours:
            contour_area = cv2.contourArea(np.array(contour))
            if annotation_is_compatible(contour, contour_area, img_area, 0.2, None):
                valid_contours.append((contour, cv2.contourArea(np.array(contour))))

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

    label_id = annotation.label
    ann_point = np.array([[annotation.x, annotation.y]])


    x_off, y_off, annotation_crop = crop_annotation(
        image, ann_point[0], cropsize=1024
    )

    crop_ann_point = np.array([[ann_point[0][0] - x_off, ann_point[0][1] - y_off]])
    sam = SamPredictor(sam.model)

    sam.set_image(annotation_crop)

    contour, contour_area = zoom_sam(
        ann_point, sam, expected_area, x_off, y_off
    )


    if annotation_is_compatible(
        contour, contour_area, 1024 * 1024, 0.2, expected_area
    ):
        return {
            "image_id": image_id,
            "label_id": label_id,
            "annotation_id": annotation.annotation_id,
            "contour_area": contour_area,
            "points": contour.tolist(),
            "method": "zoom",
        }

    x_off, y_off, annotation_crop = crop_annotation(
        image, ann_point[0], cropsize=512
    )

    crop_ann_point = np.array([[ann_point[0][0] - x_off, ann_point[0][1] - y_off]])
    sam = SamPredictor(sam.model)
    sam.set_image(annotation_crop)

    contour, contour_area = super_zoom_sam(
        crop_ann_point, image, sam, expected_area, x_off, y_off
    )
    if annotation_is_compatible(
        contour, contour_area, 512 * 512, 0.2, expected_area
    ):
        return {
            "image_id": image_id,
            "label_id": label_id,
            "points": contour.tolist(),
            "annotation_id": annotation.annotation_id,
            "contour_area": contour_area,
            "method": "superzoom",
        }

    contour, contour_area = negative_point_sam(
        crop_ann_point, x_off, y_off, sam, expected_area
    )
    if annotation_is_compatible(
        contour, contour_area, 512 * 512, 0.2, expected_area
    ):
        return {
            "image_id": image_id,
            "label_id": label_id,
            "annotation_id": annotation.annotation_id,
            "contour_area": contour_area,
            "points": contour.tolist(),
            "method": "negative",
        }
    contour, contour_area = multipoint_sam(
        crop_ann_point[0], x_off, y_off, sam, expected_area
    )

    if annotation_is_compatible(
        contour, contour_area, 512 * 512, 0.2, expected_area
    ):
        return {
            "image_id": image_id,
            "annotation_id": annotation.annotation_id,
            "label_id": label_id,
            "points": contour.tolist(),
            "contour_area": contour_area,
            "method": "multipoint",
        }
    image_area = image.shape[0] * image.shape[1]
    contour, contour_area = inaccurate_annotation_sam(
        crop_ann_point[0], x_off, y_off, sam, image_area, expected_area
    )

    if annotation_is_compatible(
        contour, contour_area, 512 * 512, 0.2, expected_area
    ):
        return {
            "image_id": image_id,
            "label_id": label_id,
            "annotation_id": annotation.annotation_id,
            "points": contour.tolist(),
            "contour_area": contour_area,
            "method": "inaccurate",
        }

    return {}


def image_unsharp(image: np.array) -> np.array:
    """Unsharp the input image using GaussianBlur

    Args:
        image: Image ton unsharp

    Returns:
        Unsharpened image
    """

    start = time.perf_counter()
    gaussian_3 = cv2.GaussianBlur(image, (0, 0), 10.0)
    unsharp_image = cv2.addWeighted(image, 2.0, gaussian_3, -1.0, 0)
    logging.warning(f"Unsharp took {time.perf_counter() - start}s")
    return unsharp_image


# returns the beginning of the slice indices as a list of x and y values and the image itself
def crop_annotation(
    image: np.ndarray, annotation_point: np.ndarray, cropsize: int = 1024
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
        max(0, int(annotation_point[0] - cropsize / 2)), image.shape[1] - cropsize
    )
    y_offset = min(
        max(0, int(annotation_point[1] - cropsize / 2)), image.shape[0] - cropsize
    )
    return [
        x_offset,
        y_offset,
        image[y_offset : y_offset + cropsize, x_offset : x_offset + cropsize],
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

    # instance segmentation arguments
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

    # get the correct sam model and load weights
    sam_model = sam_model_registry[args.model_type](checkpoint=args.model_path)
    # move the model to the correct device
    sam_model.to("cuda")
    # create the sam predictor
    sam = SamPredictor(sam_model)

    # Compute expected areas
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

    expected_areas = pd.DataFrame(resulting_annotations)

    if expected_areas.empty:
        logging.error("Unable to compute any annotations for expected area!")
        exit(0)

    expected_areas_values = (
        expected_areas.dropna()
        .groupby("label_id")
        .apply(lambda x: x.contour_area.median())
        .to_dict()
    )

    # now that we have the expected areas, we can convert the annotations
    resulting_annotations = []

    for image_id in expected_areas.image_id.unique():
        # here we have already the annotations from base SAM
        precomputed_annotations = expected_areas.query("image_id == @image_id")
        image_path = image_paths.get(image_id)
        if image_path is None:
            logging.error(f"Image path for {image_id} not found!")
            continue
        image = np.array(Image.open(image_path))
        for _, row in precomputed_annotations.iterrows():
            expected_area = expected_areas_values.get(row.label_id)
            if expected_area is None:
                continue
            #If a contour was already computed and is valid let's use it
            if row.possible_contours is not None and len(
                (
                    contours := [
                        (c, area)
                        for c, area in row.possible_contours
                        if annotation_is_compatible_with_expected_area(
                            area, expected_area
                        )
                    ]
                )
            ):
                contour = get_best_contour(contours, expected_area)
                resulting_annotations.append({
                    "image_id": image_id,
                    "label_id": row.label_id,
                    "annotation_id": row.annotation_id,
                    "points": contour,
                    "method": "base",
                })
                continue

            resulting_annotations.append(process_annotation(
                row.point_annotation, row.image_id, image, sam, expected_area
            ))

    os.makedirs(os.path.dirname(args.output_file), exist_ok=True)
    vals = (
        pd.DataFrame(resulting_annotations)
        .loc[:, ["annotation_id", "method"]]
        .groupby("method")
        .count()
    )
    logging.warning(vals)
    pd.DataFrame(resulting_annotations).loc[
        :, ["annotation_id", "points", "image_id", "label_id"]
    ].to_csv(args.output_file, index=False)
