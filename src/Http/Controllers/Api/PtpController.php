<?php

namespace Biigle\Modules\Ptp\Http\Controllers\Api;
use Biigle\Http\Controllers\Api\Controller;
use Biigle\Modules\Ptp\Jobs\PtpJob;
use Biigle\Image;
use Biigle\ImageAnnotation;
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
        foreach ($annotations as $annotation) {
            $annotation = ImageAnnotation::findOrFail($annotation);
            if (!isset($imageAnnotationArray[$annotation->image->id])) {
                $imageAnnotationArray[$annotation->image->id] = [];
            }
            $imageAnnotationArray[$annotation->image->id][] = $annotation;
        };

        // INFO: should we batch per small number or annotations? for now, just grouping per
        // image IDs
        foreach ($imageAnnotationArray as $imageId => $annotations){
            $job = new PtpJob($request->user(), $annotations);
            $job->handle($request->user(), $annotations);
        }

        return ['submitted' => true];
    }
}
