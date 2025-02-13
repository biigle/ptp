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
     * Ignore this job if the project or volume does not exist any more.
     *
     * @var bool
     */
    protected $deleteWhenMissingModels = true;
    /**
     * Generate Point to Polygon Job Chain.
     * This method generates, based on the request. The jobs generated are first for computing and uploading the expected areas of converted polygons.
     * Then, it generates a job for executing the conversion and then upload the new annotations to the DB
     *
     * @param  $request
     * @param $volumeId
     * @return
     */
    public function generatePtpJob(Request $request, int $volumeId)
    {
        $volume = Volume::findOrFail($volumeId);
        $this->authorize('edit-in', $volume);

        if (!$volume->isImageVolume() || $volume->hasTiledImages()){
            abort(400, 'Point to polygon conversion cannot be executed on this volume!');
        }

        if (is_array($volume->attrs) && array_key_exists('ptp_job_id', $volume->attrs)) {
            abort(400, 'Another point to polygon conversion job is running in this volume!');
        }

        $pointShapeId = Shape::pointId(); //Find annotations with selected label in desired volume
        $annotationsCount = ImageAnnotation::join('image_annotation_labels','image_annotations.id', '=', 'image_annotation_labels.annotation_id')
            ->join('images','image_annotations.image_id', '=','images.id')
            ->where('images.volume_id', $volumeId)
            ->where('image_annotations.shape_id', $pointShapeId)
            ->count();

        if ($annotationsCount == 0){
            abort(400, 'No point annotations to convert!');
        }

        //$inputFile.'.json' will be used for image annotations, $inputFile.'_images.json' for image paths
        $inputFile = 'ptp/input-files/'.$volume->id;

        $outputFile = 'ptp/'.$volume->id.'_converted_annotations.json';

        $id = $this->setUniquePtpJob($volume);

        PtpJob::dispatch($volume->id, $inputFile, $outputFile, $request->user(), $id);

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

