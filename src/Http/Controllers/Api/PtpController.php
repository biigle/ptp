<?php

namespace Biigle\Modules\Ptp\Http\Controllers\Api;
use Biigle\Http\Controllers\Api\Controller;
use Biigle\Modules\Ptp\Jobs\PtpJob;
use Biigle\Modules\Ptp\Jobs\UploadPtpExpectedAreaJob;
use Biigle\Modules\Ptp\Jobs\UploadConvertedAnnotationsJob;
use Biigle\Modules\Ptp\PtpExpectedArea;
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
    public function generatePtpJob(Request $request) {
        $this->validate($request, ['label_id' => 'integer', 'volume_id' => 'integer']);
        $volume = Volume::findOrFail($request->volume_id);
        $this->authorize('edit-in', $volume); Label::findOrFail($request->label_id);
        $imageAnnotationArray = [];
        $labelId = $request->label_id;
        $pointShapeId = 1; //Find annotations with selected label in desired volume
        $annotations = ImageAnnotation::join('image_annotation_labels','image_annotations.id', '=', 'image_annotation_labels.annotation_id')
            ->join('images','image_annotations.image_id', '=','images.id')
            ->where('image_annotation_labels.label_id', $labelId)
            ->where('images.volume_id', $volume->id)
            ->where('image_annotations.shape_id', $pointShapeId)
            ->select('image_annotations.id as id', 'images.id as image_id', 'image_annotations.points as points','image_annotations.shape_id as shape_id')
            ->get();
        foreach ($annotations as $annotation) {
            if (!isset($imageAnnotationArray[$annotation->image_id])) {
                $imageAnnotationArray[$annotation->image_id] = [];
            }
            //TODO: we should load the label dimension here
            $imageAnnotationArray[$annotation->image_id][] = [
                'annotation_id' => $annotation->id,
                'points' => $annotation->points,
                'shape' => $annotation->shape_id,
                'image' => $annotation->image_id,
                'label' => $labelId,
            ];
        };

        // INFO: should we batch per small number or annotations? for now, just grouping per
        // image IDs

        $jobArray = [];
        $expectedAreaCount = PtpExpectedArea::where('label_id', $labelId)->where('volume_id', $volume->id)->count('id');

        if ($expectedAreaCount == 0){
            $expectedAreaJobs = [];
            $outputDir = config('ptp.temp_dir').'/compute-area/'.$volume->id.'/'.$labelId;

            foreach ($imageAnnotationArray as $imageId => $imageAnnotationValues){
                //TODO: why do I need to put the args both when I create the new job and when I dispatch it?
                $outputFile = "$outputDir/".$labelId."_"."$imageId.json";
                $job = new PtpJob($request->user(), $imageAnnotationValues, 'compute-area', $labelId, $outputFile);
                array_push($expectedAreaJobs, $job);
            }
            array_push($jobArray, Bus::batch($expectedAreaJobs));
            $uploadJob = new UploadPtpExpectedAreaJob($outputDir, $volume->id, $labelId);
            array_push($jobArray, $uploadJob);
        }

        $ptpConversionJobs = [];
        $outputDir = config('ptp.temp_dir').'/ptp/'.$volume->id.'/'.$labelId;

        foreach ($imageAnnotationArray as $imageId => $imageAnnotationValues){
            $outputFile = "$outputDir/".$labelId."_"."$imageId.json";
            $job = new PtpJob($request->user(), $imageAnnotationValues, 'ptp', $labelId, $outputFile) ;
             array_push($ptpConversionJobs, $job);
        }
        $job = new UploadConvertedAnnotationsJob($outputDir);
        //TODO: add the job that updates the annotations
        array_push($jobArray, Bus::batch($ptpConversionJobs), $job);

        Bus::chain($jobArray)->dispatch();

        return ['submitted' => true];
    }
}
