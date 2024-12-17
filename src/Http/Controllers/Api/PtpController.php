<?php

namespace Biigle\Modules\Ptp\Http\Controllers\Api;
use Biigle\Http\Controllers\Api\Controller;
use Biigle\Modules\Ptp\Jobs\PtpJob;
use Biigle\Modules\Ptp\Jobs\UploadPtpExpectedAreaJob;
use Biigle\Modules\Ptp\Jobs\UploadConvertedAnnotationsJob;
use Biigle\Modules\Ptp\PtpExpectedArea;
use Biigle\ImageAnnotation;
use Biigle\Shape;
use Biigle\Volume;
use Biigle\Label;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Storage;

/**
 * Controller used for creating a PTP Job Chain
 */
class PtpController extends Controller
{
    /**
     * Generate Point to Polygon Job Chain.
     * This method generates, based on the request. The jobs generated are first for computing and uploading the expected areas of converted polygons.
     * Then, it generates a job for executing the conversion and then upload the new annotations to the DB
     *
     * @param  $request
     * @return
     */
    public function generatePtpJob(Request $request) {
        $this->validate($request, ['volume_id' => 'integer']);
        $volume = Volume::findOrFail($request->volume_id);
        if (!$volume->isImageVolume()){
            abort(503);
        }
        $this->authorize('edit-in', $volume);

        $imageAnnotationArray = [];

        $pointShapeId = Shape::pointId(); //Find annotations with selected label in desired volume
        $annotations = ImageAnnotation::join('image_annotation_labels','image_annotations.id', '=', 'image_annotation_labels.annotation_id')
            ->join('images','image_annotations.image_id', '=','images.id')
            ->where('images.volume_id', $volume->id)
            ->where('image_annotations.shape_id', $pointShapeId)
            ->select('image_annotations.id as id', 'images.id as image_id', 'image_annotations.points as points','image_annotations.shape_id as shape_id', 'image_annotation_labels.label_id as label_id')
            ->get();

        $expectedAreas = PtpExpectedArea::query()->where('volume_id', $volume->id)->get()->keyBy('label_id');

        foreach ($annotations as $annotation) {
            if (!isset($imageAnnotationArray[$annotation->image_id])) {
                $imageAnnotationArray[$annotation->image_id] = [];
            }
            $expectedArea = null;
            if (in_array($annotation->label_id, $expectedAreas->toArray())){
                $expectedArea = $this->findMedian(json_decode($expectedAreas[$annotation->label_id]->areas));
            }
            $imageAnnotationArray[$annotation->image_id][] = [
                'annotation_id' => $annotation->id,
                'points' => $annotation->points,
                'shape' => $annotation->shape_id,
                'image' => $annotation->image_id,
                'label' => $annotation->label_id,
                'expected_area' => $expectedArea,
            ];
        };

        $inputDir = config('ptp.temp_dir').'/input-files-ptp/';
        if (!file_exists($inputDir)) {
            mkdir($inputDir, 0755, true);
        }

        $inputFile = '/input-files-ptp/'.$volume->id.'.json';
        $jsonData = json_encode($imageAnnotationArray);
        $storage = Storage::disk(config('ptp.ptp_storage_disk'));
        $storage->put($inputFile, $jsonData);

        // INFO: should we batch per small number or annotations? for now, just grouping per
        // image IDs
        $jobArray = [];

        $outputDir = 'compute-area/'.$volume->id.'/';

        $job = new PtpJob($inputFile, 'compute-area', $outputDir);
        array_push($jobArray, $job);

        $uploadJob = new UploadPtpExpectedAreaJob($outputDir, $volume->id);
        array_push($jobArray, $uploadJob);

        $outputDir = 'ptp/'.$volume->id.'/';

        $job = new PtpJob($inputFile, 'ptp', $outputDir);
        array_push($jobArray, $job);

        $job = new UploadConvertedAnnotationsJob($outputDir, $request->user());
        array_push($jobArray, $job);

        Bus::chain($jobArray)->dispatch();

        return ['submitted' => true];
    }
    /**
     * Find median of array
     *
     * @param  $array
     * @return integer
     */
    protected function findMedian(array $array): int
    {
        sort($array);
        $count = count($array);
        $index = intdiv($count, 2);
        if ($count % 2 == 0) {    // count is odd
            return $array[$index];
        } else {                   // count is even
            return ($array[$index-1] + $array[$index]) / 2;
        }
    }
}
