<?php
namespace Biigle\Modules\Ptp\Jobs;
use Biigle\ImageAnnotation;
use Biigle\ImageAnnotationLabel;
use Biigle\User;
use Biigle\Shape;
use Biigle\Jobs\Job as BaseJob;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Bus\Batchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Storage;
/**
 * Upload the result of  computing the expected area for Point to Polygon Conversion to the DB
 */
class UploadConvertedAnnotationsJob extends BaseJob implements ShouldQueue
{
    use Batchable, InteractsWithQueue, Queueable, SerializesModels;
    /**
     * The queue to push this job to.
     *
     * @var string
     */
    public $queue;

    /**
     * User responsible for the Point to Polygon conversion.
     *
     * @var User
     */
    public User $user;

    /**
     * The queue to push this job to.
     *
     * @var string
     */
    public string $inputDir;


    public function __construct(string $inputDir, User $user)
    {
        $this->inputDir = $inputDir;
        $this->user = $user;
    }
    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        try {
            $storage = Storage::disk(config('ptp.ptp_storage_disk'));
            $files = $storage->allFiles($this->inputDir);
        } catch (Exception $e){
            throw new Exception("Unable to load files from $this->inputDir: $e");
        }
        foreach ($files as $file) {
            if ($file == '.' || $file == '..'){
                continue;
            }
            $jsonData = $storage->json($file);
            if (is_null($jsonData)) {
                throw new Exception("Error while reading file $file");
            }
            $polygonShape = Shape::polygonId();
            foreach ($jsonData as $annotation) {
                $newAnnotation = ImageAnnotation::findOrFail($annotation['annotation_id'])->replicate();
                $newAnnotation->points = $annotation['points'];
                $newAnnotation->shape_id = $polygonShape;
                $newAnnotation->save();
                $imageAnnotationLabel = [
                    'label_id' => $annotation['label_id'],
                    'annotation_id' => $newAnnotation->id,
                    'user_id' => $this->user->id,
                    'confidence' => 1.0,
                ];
                ImageAnnotationLabel::insert($imageAnnotationLabel) ;

            }
        }
    }
}

