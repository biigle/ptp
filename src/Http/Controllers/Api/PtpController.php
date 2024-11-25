<?php

namespace Biigle\Modules\Ptp\Http\Controllers\Api;
use Biigle\Http\Controllers\Api\Controller;
use Biigle\Modules\Ptp\Jobs\PtpJob;
use Biigle\Modules\Ptp\Jobs\UploadPtpExpectedAreaJob;
use Biigle\ImageAnnotation;
use Biigle\Volume;
use Biigle\Label;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Response;

//TODO: Doctstring
class PtpController extends Controller
{
    /**
     */
    public function generatePtpJob(Request $request)
    {
        $this->validate($request, ['label_id' => 'integer', 'volume_id' => 'integer', 'job_type' => 'string']);
        if ($request->job_type == 'ptp'){
            $this->validate($request, ['expectedArea' => 'integer']);
        }
        $volume = Volume::findOrFail($request->volume_id);
        $this->authorize('edit-in', $volume);
        $jobType = $request->job_type;
        Label::findOrFail($request->label_id);
        $expectedArea = $request->expectedArea;
        if (!in_array($jobType, ['compute-area','ptp'])) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $imageAnnotationArray = [];
        $labelId = $request->label_id;
        $pointShapeId = 1;

        //Find annotations with selected label in desired volume
        $annotations = ImageAnnotation::join('image_annotation_labels','image_annotations.id', '=', 'image_annotation_labels.annotation_id')
            ->join('images','image_annotations.image_id', '=','images.id')
            ->where('image_annotation_labels.label_id', $labelId)
            ->where('images.volume_id', $volume->id)
            ->where('image_annotations.shape_id', $pointShapeId)
            ->get();

        // group per file
        foreach ($annotations as $annotation) {
            if (!isset($imageAnnotationArray[$annotation->image->id])) {
                $imageAnnotationArray[$annotation->image->id] = [];
            }
            //TODO: we should load the label dimension here
            $imageAnnotationArray[$annotation->image->id][] = [
                'annotation_id' => $annotation->id,
                'points' => $annotation->points,
                'shape' => $annotation->shape_id,
                'image' => $annotation->image->id,
                'expected_area' => $expectedArea,
                'label' => $labelId,
            ];
        };
        // INFO: should we batch per small number or annotations? for now, just grouping per
        // image IDs

        $jobArray = [];
        $outputDir = config('ptp.temp_dir').'/'.$jobType.'/'.$volume->id.'/'.$labelId;

        foreach ($imageAnnotationArray as $imageId => $imageAnnotationValues){
            //TODO: why do I need to put the args both when I create the new job and when I dispatch it?
            $job = new PtpJob($request->user(), $imageAnnotationValues, $jobType, $labelId, $outputDir) ;
            array_push($jobArray, $job);
        }
        //TODO: if here
        $uploadJob = new UploadPtpExpectedAreaJob($outputDir, $volume->id, $labelId);

        Bus::chain([Bus::batch($jobArray), $uploadJob])->dispatch();

        return ['submitted' => true];
    }
}
