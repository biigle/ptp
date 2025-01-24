<?php

namespace Biigle\Modules\Ptp\Http\Controllers\Api;
use Biigle\Http\Controllers\Api\Controller;
use Biigle\Modules\Ptp\Jobs\PtpJob;
use Biigle\ImageAnnotation;
use Biigle\Shape;
use Biigle\Volume;
use Illuminate\Http\Request;
use Ramsey\Uuid\Uuid;
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
        $this->authorize('edit-in', $volume);

        if (!$volume->isImageVolume() || $volume->hasTiledImages()){
            abort(400, 'Point to polygon conversion cannot be executed on this volume!');
        }

        if (is_array($volume->attrs) && array_key_exists('ptp_job_id', $volume->attrs)) {
            abort(400, 'Another Point to polygon conversion job is running in this volume!');
        }

        $imageAnnotationArray = [];

        $pointShapeId = Shape::pointId(); //Find annotations with selected label in desired volume
        $annotations = ImageAnnotation::join('image_annotation_labels','image_annotations.id', '=', 'image_annotation_labels.annotation_id')
            ->join('images','image_annotations.image_id', '=','images.id')
            ->where('images.volume_id', $volume->id)
            ->where('image_annotations.shape_id', $pointShapeId)
            ->select('image_annotations.id as id', 'images.id as image_id', 'image_annotations.points as points','image_annotations.shape_id as shape_id', 'image_annotation_labels.label_id as label_id')
            ->get();


        foreach ($annotations as $annotation) {
            if (!isset($imageAnnotationArray[$annotation->image_id])) {
                $imageAnnotationArray[$annotation->image_id] = [];
            }
            $imageAnnotationArray[$annotation->image_id][] = [
                'annotation_id' => $annotation->id,
                'points' => $annotation->points,
                'shape' => $annotation->shape_id,
                'image' => $annotation->image_id,
                'label' => $annotation->label_id,
            ];
        };

        $inputDir = config('ptp.temp_dir').'/input-files-ptp/';
        if (!file_exists($inputDir)) {
            mkdir($inputDir, 0755, true);
        }

        //$inputFile.'.json' will be used for image annotations, $inputFile.'_images.json' for image paths
        $inputFile = 'ptp/input-files/'.$volume->id;
        $jsonData = json_encode($imageAnnotationArray);
        $storage = Storage::disk(config('ptp.ptp_storage_disk'));
        $storage->put(config('ptp.temp_dir').'/'.$inputFile.'.json', $jsonData);

        $outputFile = 'ptp/'.$volume->id.'_converted_annotations.json';

        $id = $this->setUniquePtpJob($volume);

        PtpJob::dispatch($inputFile, $outputFile, $request->user(), $id);

        return ['submitted' => true];
    }

    /**
    *
    * Assign UUID to a volume for the PTP job so that only one job is run per volume at a time.
    * @var Volume $volume Volume where the PTP job is executed
    *
    **/
    public function setUniquePtpJob(Volume $volume): string
    {
        $attrs = $volume->attrs;
        $uuid = Uuid::uuid4();
        $attrs['ptp_job_id'] = $uuid;
        $volume->attrs = $attrs;
        $volume->save();
        return $uuid;
    }
}

