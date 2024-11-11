from PIL import Image, ImageFile
from collections import namedtuple
import logging
import random
import json
import numpy as np
import pandas as pd
import cv2
from segment_anything import SamPredictor, sam_model_registry
import torch
import math
import argparse
from pathlib import Path
import re
import asyncio
import getpass
import matplotlib.pyplot as plt
import matplotlib.patches as patches
from sahi import AutoDetectionModel
from sahi.predict import get_sliced_prediction
from scipy.spatial.distance import cdist
import json
from pycocotools import mask
import copy
import requests
import os

try:
    from dotenv import load_dotenv
    load_dotenv()
except ImportError:
   pass

Annotation = namedtuple("Annotation", ["x","y", "expected_area", "label", "annotation_id"])


# for some very small annotation it seems to work better to zoom further in
# (this is somewhat unsafe for large objects as we might cut off the object)
def super_zoom_sam(ann_point, unsharp_image, sam, prev_area):
    # crop the image to the area around the annotation
    x_off, y_off, annotation_crop = crop_annotation(unsharp_image, ann_point, cropsize=512)
    # get the annotation point in the cropped image
    crop_ann_point = [ann_point[0]-x_off, ann_point[1]-y_off]
    # convert the annotation point to a numpy array
    sam_crop_ann_point = np.array([crop_ann_point])
    # create a new sam predictor with the same model
    croppedSAM = SamPredictor(sam.model)
    # set the image for SAM
    croppedSAM.set_image(annotation_crop)
    # get the result from SAM
    croppedMasks, croppedScores, _ = croppedSAM.predict(point_coords=sam_crop_ann_point, point_labels=np.array([1]), multimask_output=True)
    # convert the mask of SAM to contour
    contour, contour_area = mask_to_contour(croppedMasks, 512, 512, crop_ann_point, croppedScores, True, prev_area)
    # if there is no contour return None
    if contour is None:
        return [None, None, x_off, y_off, crop_ann_point, croppedSAM]
    # add the offset to the contour
    contour[::2]+=x_off
    contour[1::2]+=y_off
    return [contour, contour_area, x_off, y_off, crop_ann_point, croppedSAM]


# the annotation might be slightly off, replace the annotation point with variations of it and compare to previous sizes to validate the annotation
# (this is somewhat unsafe for objects with a lot of variation in size, either to properties inherent to object or to the distance to the camera)
def inaccurate_annotation_sam(crop_ann_point, x_off, y_off, croppedSAM, img_area, prev_area):
    # we only have one point all the time soe we create one positive label and reuse it
    sam_label = np.array([1])

    # arrays to hold the contours and their areas
    allcontours=[]
    allareas=[]

    # generate all the possible variations of the annotation point (5 pixels in each direction)
    posvariation = [(x,y) for x in [5,0,-5] for y in [5,0,-5] if x!=0 or y!=0]
    for xvar,yvar in posvariation:
        # create a variation of the original point
        offpoint = np.array([[crop_ann_point[0]+xvar,crop_ann_point[1]+yvar]])
        # get the results from SAM
        masks, scores, _ = croppedSAM.predict(point_coords=offpoint, point_labels=sam_label, multimask_output=True)
        # convert the masks to contours
        contour, contour_area = mask_to_contour(masks, 1024, 1024, crop_ann_point , scores , True, prev_area)
        # if there is a contour/area add it to the list, otherwise just discard it
        if contour is not None and contour_area/img_area <= 0.04:
            allcontours.append(contour)
            allareas.append(contour_area)

    # if no valid contour could be found reutrn None
    if len(allcontours)==0:
        return [None, None]
    #if there is only one contour take it
    elif len(allcontours)==1:
        contour = allcontours[0]
        contour_area = allareas[0]
    # if more then one contour was found compare to known areas
    elif len(allcontours)>1:
        # if there are no previous areas return None (might be the first object with that label)
        if previous_area is None:
            return [None, None]
        # get the minium difference between previous area and current area
        idx=np.argmin(np.abs(np.array(allareas)-prev_area))
        # take the contour with the smallest difference
        contour = allcontours[idx]
        contour_area = allareas[idx]

    # add the offset to the contour
    contour[::2]+=x_off
    contour[1::2]+=y_off
    return [contour, contour_area]

# generate 2 additional positive point in the vicinity of the annotation
def multipoint_sam(crop_ann_point, x_off, y_off, croppedSAM, prev_area):
    # generate 2 random points in the vicinity of the point annotation
    include_point1 = generate_random_circle_point(crop_ann_point, np.random.randint(1,5))
    include_point2 = generate_random_circle_point(crop_ann_point, np.random.randint(1,5))
    # sam annotation points array with original and 2 random additional points
    pospoints = np.array([crop_ann_point, include_point1, include_point2])
    # the respective label 1 -> positive, 0 -> negative
    sam_label = np.array([1, 1, 1])
    # get the results from SAM
    masks, scores, _ = croppedSAM.predict(point_coords=pospoints, point_labels=sam_label, multimask_output=True)
    # convert the masks to contours
    contour, contour_area = mask_to_contour(masks, 1024, 1024, crop_ann_point , scores, True, prev_area)
    # if there is no contour return None
    if contour is None:
        return [None, None]

    # add the offset to the contour
    contour[::2]+=x_off
    contour[1::2]+=y_off
    return [contour, contour_area]


def generate_random_circle_point(center, radius):
    # initialize x and y with invalid values
    x=-1
    y=-1
    # until the point is inside the image...
    while (x < 0 or x > 1024 or y < 0 or y > 1024):
        # ... generate a random angle
        alpha = random.random()*math.pi*2
        # ... and calculate the x and y coordinates with the given radius
        x, y = math.ceil(center[0]+radius*math.cos(alpha)), math.ceil(center[1]+radius*math.sin(alpha))
    # when a valid point was generated return it
    return [x,y]


def negative_point_sam(label_id,crop_ann_point, x_off, y_off, croppedSAM, distance_dict, prev_area):
    if distance_dict is None:
        return [None, None]
    # get the distance from past values
    dist=distance_dict.get(str(label_id))
    # if there is no past value take the average of all distances (still better than nothing)
    if not dist:
        dist = np.mean([distance_dict[x] for x in distance_dict])
    # add a random number to make it a bit larger
    radius = dist + random.randint(5, 10)
    # because the object is center a radius bigger than the distance to the edge doesn't make sense + we add a small margin so return None instead
    if radius > 400:
        return [None, None]
    # generate 2 random points on the circle around the annotation
    exclude_point1 = generate_random_circle_point(crop_ann_point, radius)
    exclude_point2 = generate_random_circle_point(crop_ann_point, radius)
    exclude_point3 = generate_random_circle_point(crop_ann_point, radius)
    exclude_point4 = generate_random_circle_point(crop_ann_point, radius)
    exclude_point5 = generate_random_circle_point(crop_ann_point, radius)
    # generate the SAM annotation points array with 1 positive and 2 negative points
    pos_neg_points = np.array([crop_ann_point, exclude_point1, exclude_point2, exclude_point3, exclude_point4, exclude_point5])
    # the respective label 1 -> positive, 0 -> negative
    sam_label = np.array([1, 0, 0, 0, 0, 0])
    # get the results from SAM
    masks, scores, _ = croppedSAM.predict(point_coords=pos_neg_points, point_labels=sam_label, multimask_output=True)
    # convert the masks to contours
    contour, contour_area = mask_to_contour(masks, 1024, 1024, crop_ann_point , scores, True , prev_area)
    # if there is no contour return None
    if contour is None:
        return [None, None]

    # add the offset to the contour
    contour[::2]+=x_off
    contour[1::2]+=y_off
    return [contour, contour_area]

def zoom_sam(ann_point, unsharp_image, sam, prev_area):
    # crop the image to the area around the annotation
    x_off, y_off, annotation_crop = crop_annotation(unsharp_image, ann_point)
    # get the annotation point in the cropped image
    crop_ann_point = [ann_point[0]-x_off, ann_point[1]-y_off]
    # convert the annotation point to a numpy array
    sam_crop_ann_point = np.array([crop_ann_point])
    # create a new sam predictor with the same model
    croppedSAM = SamPredictor(sam.model)
    # set the image for SAM
    croppedSAM.set_image(annotation_crop)
    # get the result from SAM
    croppedMasks, croppedScores, _ = croppedSAM.predict(point_coords=sam_crop_ann_point, point_labels=np.array([1]), multimask_output=True)
    # convert the mask of SAM to contour
    contour, contour_area = mask_to_contour(croppedMasks, 1024, 1024, crop_ann_point, croppedScores, True, prev_area)
    # if there is no contour return None
    if contour is None:
        return [None, None, x_off, y_off, crop_ann_point, croppedSAM]
    # add the offset to the contour
    contour[::2]+=x_off
    contour[1::2]+=y_off
    return [contour, contour_area, x_off, y_off, crop_ann_point, croppedSAM]

#check if an annotation is oob
def annotation_is_invalid(ann_point, img_width, img_height):
    #if annotation is out of bounds
    if ann_point[0]<0 or ann_point[1]<0 or ann_point[0]>img_width or ann_point[1]>img_height:
        # return True -> invalid annotation
        return True
    return False

# test if the current contour is valid "enough" or if we should try something else
def annotation_is_valid(contour:float, contour_area:float, img_area:float, threshold:float, prev_area:float):
    return contour is not None or contour_area/img_area <= threshold or contour_area <= (prev_area*1.75) or contour_area >= (prev_area*0.25)


def debugPrint(logging, label, contour_area, img_area, threshold, previous_area, label_name):
    if contour_area:
        tsuccessfull="✅" if (contour_area/img_area) < threshold else "❌"
        ssucessfull="✅" if contour_area < (previous_area*1.75) and contour_area > (previous_area*0.25) else "❌"
        logging.debug(f"{label : <30} {contour_area : >10} {np.round(contour_area/img_area,3) : >5} {threshold : <5} Threshold:{tsuccessfull} Size:{ssucessfull} {previous_area :>5} {label_name : >20}")
    else:
        logging.debug(f"{label} none")



# convert a mask to a contour
# scores is a necessary input when the multimask input is True.
def mask_to_contour(masks, img_height, img_width, point, scores=[], multimask=True, typical_area=9999999999):
    if multimask is True:
        assert len(scores) != 0, "Scores must be a list from SAM, but the input is an empty list."
        # init results array
        results=[]
        # go through all 3 masks of multimask
        for i in range(3):
            # and gather the contours and area using recursion
            results.append(mask_to_contour(masks[i], img_height, img_width, point, scores, False))
        # if the typical area is not the default value...
        if typical_area != 9999999999:
            # get the absolute differences between the typical area and the area of each mask
            absdiff=[np.abs(x[1]-typical_area) for x in results if x[1] is not None]
            if len(absdiff)==0:
                return [None, None]
            # take the one mask that is closest to the typical area
            idx=np.argmin(absdiff)
            # and return it
            return results[idx]
        else:
            # otherwise get the one with the best score
            idx = np.argsort(scores)[::-1]
            mask = np.reshape(masks[idx[0]], (img_height, img_width))
    else:
        mask = masks
    # check if mask contains anything. If not return because no contours can be found anyway
    if not mask.any():
        return [None, None]
    mask = mask*255
    mask = mask.astype(np.uint8)
    contours, hierarchy = cv2.findContours(mask, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_NONE)

    # if no contour could be found return None
    if not len(contours):
        return [None, None]
    # if there is only one contour...
    elif len(contours)==1:
        # ... return it
        contour = contours[0]
    # else we have to find the one above the object (since we used RETR_EXTERNAL there can be only one)
    else:
        contour=contourThatContainsThePoint(contours, point)
        # contourThatContainsThePoint might return None if no contour contains the point
        if contour is None:
            return [None, None]
    return [contour.flatten(), cv2.contourArea(contour)]


# process a single annotation, actually converts [x,y] to a polygon
def process_annotation(point: Annotation, unsharp_image, img_height, img_width, img_area, sam, logging, statistics, distance_dict=None, previous_area=None):
    label_id = point.label

    if previous_area is None:
        previous_area = None
    # get the point annotation coordinates in [x,y,x,y...] format convert from string
    ann_point = point.x, point.y
    # check if the annotation point is invalid
    if annotation_is_invalid(ann_point, img_width, img_height):
        statistics['invalid'] += 1
        return None
    result = {
        "label_id": label_id,
    }
    # convert the point annotation to a numpy array
    annotation_point = np.array(ann_point)
    # get the result from SAM
    masks, scores, _ = sam.predict(point_coords=annotation_point, point_labels=np.array([1]), multimask_output=True)
    # convert the mask of SAM to contour
    contour, contour_area = mask_to_contour(masks, img_height, img_width, ann_point, scores, True, previous_area.get(label_id,9999999999))
    # if the contour area is bigger than 4% of the image area or there is more than one contour...
    if annotation_is_valid(contour, contour_area, img_area, 0.04, previous_area.get(label_id,9999999999)):
        return {"image_id":annotation.image_id, "shape_id": POLYGON_ANNOTATION_ID, "label_id": label_id, "confidence": 1, "points": contour.tolist()}


        # ... zoom in on the annotation and try again (SAM uses max 1024 for the longest edge)
    contour, contour_area, x_off, y_off, crop_ann_point, croppedSAM = zoom_sam(ann_point, unsharp_image, sam, previous_area.get(label_id,9999999999))

        # if it still doesn't work...
    if annotation_is_valid(contour, contour_area, 1024*1024, 0.2, previous_area.get(label_id,9999999999)):
            # ... use negative points in addition
       contour, contour_area = negative_point_sam(label_id, crop_ann_point, x_off, y_off, croppedSAM, distance_dict, previous_area.get(label_id,9999999999))
            # if it still doesn't work...
    if annotation_is_valid(contour, contour_area, 1024*1024, 0.2, previous_area.get(label_id,9999999999)):
                # ... use multiple positive points in addition
        contour, contour_area = multipoint_sam(crop_ann_point, x_off, y_off, croppedSAM, previous_area.get(label_id,9999999999))
                # if it still doesn't work...
    if annotation_is_valid(contour, contour_area, 1024*1024, 0.2, previous_area.get(label_id,9999999999)):
                    # ... try to use variations in the annotation point, which might be slightly off
        contour, contour_area = inaccurate_annotation_sam(crop_ann_point, x_off, y_off, croppedSAM, img_area, previous_area.get(label_id,9999999999))
                    # if it still doesn't work...
    statistics['multiPoint'] += 1
    logging.debug(f"superZoom ✅ ({annotation.label_name})")
    if annotation_is_valid(contour, contour_area, 1024*1024, 0.2, previous_area.get(label_id,9999999999)):
                       # ... zoom in even further
        contour, contour_area, _, _, _, _ = super_zoom_sam(ann_point, unsharp_image, sam, previous_area.get(label_id,9999999999))
    statistics['negativePoint'] += 1
                        # if it still doesn't work...
    if annotation_is_valid(contour, contour_area, 512*512,  0.5, previous_area.get(label_id,9999999999)):
                            # ... you're out of luck :-(
        statistics['noneworked'] += 1
    # if first and last x,y do not match add the first x,y to the end of the list to close the polygon
    if (contour[:2] != contour[-2:]).any():
        contour=np.hstack((contour, contour[:2]))
    return {"image_id":annotation.image_id, "shape_id": "3", "label_id": label_id, "confidence": 1, "points": contour.tolist()}

# apply unsharp masking to sharpen an image
def image_unsharp(image: Image):
    gaussian_3 = cv2.GaussianBlur(image, (0, 0), 10.0)
    unsharp_image = cv2.addWeighted(image, 2.0, gaussian_3, -1.0, 0)
    return unsharp_image

 # returns the beginning of the slice indices as a list of x and y values and the image itself
def crop_annotation(image, annotation_point, cropsize=1024):
    x_offset = min(max(0, int(annotation_point[0]-cropsize/2)), image.shape[1]-cropsize)
    y_offset = min(max(0, int(annotation_point[1]-cropsize/2)), image.shape[0]-cropsize)
    return [x_offset, y_offset, image[y_offset:y_offset+cropsize, x_offset:x_offset+cropsize]]



# process a single image
# annotations is a dataframe containing all annotations of the image
def process_image(point: Annotation, image: Image, sam, logging, statistics, distance_dict=None, previous_area=None):
    # read the image from hard disk
    unsharp_image = image_unsharp(image)
    # set the image for SAM (unsharp image in this case)
    sam.set_image(unsharp_image)
    # get image dimensions
    img_height, img_width, _ = image.shape
    # get image area
    img_area = img_height*img_width
    # create a list to store the annotations
    sam_annotations = []
    # for each annotation in the image... (iterrorws returns a tuple of (index,annotation) so we only need the annotation
    # process the annotations with SAM and store them in sam_annotations
    sam_annotations.append(process_annotation(point, unsharp_image, img_height, img_width, img_area, sam, logging, statistics, distance_dict, previous_area))
    return sam_annotations

if __name__ == "__main__":
    argparser = argparse.ArgumentParser()
    argparser.add_argument("--image-path","-i", type=str, required=True, help="Path to the image to apply point to polygon to")
    argparser.add_argument("--points","-p", type=str, nargs='+', required=True, help="Path to the image to apply point to polygon to")
    argparser.add_argument("--label","-l", type=str, nargs='+', required=True, help="Labels of possible annotations")
    argparser.add_argument("--expected-areas","-e", type=int, nargs='+', required=True, help="Expected area of the new annotation polygon")
    argparser.add_argument("--image-id", type=int, required=True, help="ID of the image")
    argparser.add_argument("--annotation-ids", "-a", type=int, nargs='+',required=True, help="Annotation IDs")


    #TODO: This should come from the configs
    argparser.add_argument("--device", type=str, help="Device to use for sam (cpu, cuda, mps)", choices=["cpu","cuda","mps"])

    # instance segmentation arguments
    argparser.add_argument('--model-type', type=str, help="Model type")
    argparser.add_argument('--model-path', type=str, help='Path to model weights')
    argparser.add_argument("--checkpoint-path", type=str, help="Path to model checkpoint path")
    argparser.add_argument('--model_config_path', type=str, help='Path to model config file')
    argparser.add_argument('--cropsize', type=int, help='The window size that is used for inference', default=512)
    argparser.add_argument('--overlap', type=float, help='Overlap ratio', default=0.5)
    argparser.add_argument('--confidence_threshold', type=float, help='Threshold for the belive value of the model', default=0.5)

    args = argparser.parse_args()

    resulting_annotations = []
    image = Image.open(args.image_path)
    points = [Annotation(point[0], point[1], expected_area, label, annotation_id) for point, expected_area, label, annotation_id in zip(args.points, args.expected_areas, args.labels, args.annotation_ids)]

        # get the correct sam model and load weights
    sam_model = sam_model_registry[args.model_type](checkpoint=args.model_path)
        # move the model to the correct device
    sam_model.to(args.device)
        # create the sam predictor
    sam = SamPredictor(sam_model)

        # if the user provided a json file with the max distance for each label load it, otherwise set it to None
    if args.label_max_distances:
            # open the json dict with distance values for each label
        with open(args.label_max_distances) as f:
                distance_dict = json.load(f)
    else:
        distance_dict = None

        # init the statistics dict
    statistics = {'regular': 0, 'zoom': 0, 'negativePoint': 0, 'multiPoint': 0, 'inaccurateAnnotation': 0,'superZoom': 0, 'noneworked': 0, 'invalid': 0}
    for point in points:
        resulting_annotations+=process_image(point, image, sam, logging, statistics, distance_dict)
      # if the save argument is given save the annotations to the given path
           #TODO needs fixing for sam based annotation
        #coco=build_coco_file(resulting_annotations,volumeid2csv)
        #with open(args.save+".json", "w") as f:
        #    json.dump(coco,f,cls=NumpyEncoder, indent=4)
    with open('result.json', "w+") as out_file:
        json.dump(resulting_annotations, fp = out_file, indent=4)

