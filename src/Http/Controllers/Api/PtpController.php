<?php

namespace Biigle\Modules\Ptp\Http\Controllers\Api;
use Biigle\Http\Controllers\Api\Controller;
use Biigle\Modules\Ptp\Jobs\PtpJob;
use Biigle\Image;
use Biigle\ImageAnnotation;
use Biigle\ImageAnnotationLabel;
use Illuminate\Http\Request;
use File;
use FileCache;

//TODO: Doctstring
//TODO: validate request
//TODO: verify that user is editor on volume
class PtpController extends Controller
{
    /**
     */
    public function generatePtpJob(Request $request)
    {
        $imageAnnotationArray = [];
        $annotations = $request->annotations;

        // group per file
        foreach ($annotations as $annotation => $value) {
            $annotation = ImageAnnotation::findOrFail($annotation);
            if (!isset($imageAnnotationArray[$annotation->image->id])) {
                $imageAnnotationArray[$annotation->image->id] = [];
            }
            //TODO: we should load the label dimension here
            $imageAnnotationArray[$annotation->image->id][] = [
                'annotation_id' => $annotation->id,
                'points' => $annotation->points,
                'shape' => $annotation->shape_id,
                'image' => $annotation->image,
                'expected_area' => intval($value),
                'label' => ImageAnnotationLabel::findOrFail($annotation->id)->id,

            ];
        };
        // INFO: should we batch per small number or annotations? for now, just grouping per
        // image IDs
        //
        foreach ($imageAnnotationArray as $imageId => $imageAnnotationValues){
            $job = new PtpJob($request->user(), $imageAnnotationValues);
            $job->handle($request->user(), $imageAnnotationArray);
        }

        return ['submitted' => true];
    }
}
