<?php

namespace Biigle\Modules\Ptp\Jobs;

use Biigle\ImageAnnotation;
use Biigle\ImageAnnotationLabel;
use Biigle\Jobs\Job as BaseJob;
use Biigle\Modules\Ptp\Exceptions\PythonException;
use Biigle\Modules\Ptp\Notifications\PtpJobConcluded;
use Biigle\Modules\Ptp\Notifications\PtpJobFailed;
use Biigle\Shape;
use Biigle\User;
use Biigle\Volume;
use Carbon\Carbon;
use Exception;
use File;
use FileCache;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class PtpJob extends BaseJob implements ShouldQueue
{
    use Batchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * File where the input data for the Python script will be kept
     * @var string
     */
    protected string $tmpInputFile;

    /**
     * File where the image input data for the Python script will be kept
     * @var string
     */
    protected string $tmpImageInputFile;

    /**
     * File where result data from the Python conversion script will be stored
     * @var string
     */
    protected string $outputFile;

    /**
     * Number of annotations to be inserted per chunk
     * @var int
     */
    public static int $insertChunkSize = 5000;

    /**
     * Ignore this job if the project or volume does not exist any more.
     *
     * @var bool
     */
    protected $deleteWhenMissingModels = true;

    /**
     * Job used for converting Point annotations to Polygons
     *
     * @param int $volumeId Id of the volume for the PTP Job
     * @param string $volumeName Name of the volume for the PTP Job
     * @param User $user User starting the PtpJob
     * @param string $jobId Uuid associated to the job
     *
     */
    public function __construct(
        public int $volumeId,
        public string $volumeName,
        public User $user,
        public string $jobId,
    ) {
        $this->volumeId = $volumeId;
        $this->volumeName = $volumeName;

        //$inputFile.'.json' will be used for image annotations, $inputFile.'_images.json' for image paths
        $inputFile = 'ptp/input-files/'.$volumeId;

        $outputFile = 'ptp/'.$volumeId.'_converted_annotations.json';

        $this->outputFile = config('ptp.temp_dir').'/'.$outputFile;
        $this->tmpInputFile = config('ptp.temp_dir').'/'.$inputFile.'.json';
        $this->tmpImageInputFile = config('ptp.temp_dir').'/'.$inputFile.'_images.json';
        $this->user = $user;
        $this->jobId = $jobId;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $callback = function ($images, $paths) {
            $this->generateImageInputFile($paths, $images);
            $this->python();
        };
        $imageData = $this->generateInputFile();
        FileCache::batch(array_values($imageData), $callback);
        $this->uploadConvertedAnnotations();
        $this->user->notify(new PtpJobConcluded($this->volumeName));
        $this->cleanupJob();
    }

    /**
     * Generate the input File containing data for the execution of the job
     *
     * @return array
     */
    public function generateInputFile(): array
    {
        $imageAnnotationArray = [];

        $pointShapeId = Shape::pointId();

        $annotations = ImageAnnotation::join('image_annotation_labels', 'image_annotations.id', '=', 'image_annotation_labels.annotation_id')
            ->join('images', 'image_annotations.image_id', '=', 'images.id')
            ->where('images.volume_id', $this->volumeId)
            ->where('image_annotations.shape_id', $pointShapeId)
            ->select('image_annotations.id as id', 'images.id as image_id', 'image_annotations.points as points', 'image_annotations.shape_id as shape_id', 'image_annotation_labels.label_id as label_id')
            ->with('file')
            ->lazy();

        $images = [];

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

            if (!isset($images[$annotation->image_id])) {
                $images[$annotation->image_id] = $annotation->getFile();
            }
        };

        $jsonData = json_encode($imageAnnotationArray);

        if (!File::exists(dirname($this->tmpInputFile))) {
            File::makeDirectory(dirname($this->tmpInputFile), 0700, true, true);
        }

        File::put($this->tmpInputFile, $jsonData);
        return $images;
    }

    /**
     * Generate the input file containing image id and path data
     *
     * @param array $paths Array containing the paths to the images.
     * @param array $images Array containing the images
     */
    public function generateImageInputFile(array $paths, array $images): void
    {
        $imagePathInput = [];

        for ($i = 0, $size = count($paths); $i < $size; $i++) {
            $imagePathInput[$images[$i]->id] = $paths[$i];
        }

        File::put($this->tmpImageInputFile, json_encode($imagePathInput));
    }

    /**
     * Run the python script for Point to Polygon conversion
     *
     */
    protected function python(): void
    {
        $code = 0;
        $lines = [];
        $python = config('ptp.python');
        $script = config('ptp.ptp_script');
        $modelPath = config('ptp.model_path');
        $modelType = config('ptp.model_type');
        $checkpointUrl = config('ptp.model_url');

        $this->maybeDownloadCheckpoint($checkpointUrl, $modelPath);

        if (!File::exists(dirname($this->outputFile))) {
            File::makeDirectory(dirname($this->outputFile), 0700, true, true);
        }


        $command = "{$python} -u {$script} --image-paths-file {$this->tmpImageInputFile} --input-file {$this->tmpInputFile} --model-type {$modelType} --model-path {$modelPath} --output-file {$this->outputFile} ";

        exec("$command 2>&1", $lines, $code);

        if ($code !== 0) {
            $lines = implode("\n", $lines);
            throw new PythonException("Error while executing python script '{$script}':\n{$lines}", $code);
        }
    }

    /**
     * Upload the converted annotations to the DB
     *
     */
    public function uploadConvertedAnnotations(): void
    {
        $jsonData = json_decode(File::get($this->outputFile), true);

        if (count($jsonData) == 0) {
            throw new Exception('No annotations were converted!');
        }

        $polygonShape = Shape::polygonId();

        $insertAnnotations = [];
        $insertAnnotationLabels = [];

        $now = Carbon::now();

        foreach ($jsonData as $idx => $annotation) {

            //It might happen that we are unable to convert some of the point
            //annotations. In this case, we should not upload the data.
            if (is_null($annotation['points'])) {
                continue;
            }

            $newAnnotation = [
                'image_id' => $annotation['image_id'],
                'points' => json_encode($annotation['points']),
                'shape_id' => $polygonShape,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $insertAnnotations[] = $newAnnotation;
            $insertAnnotationLabels[] = [
                'label_id' => $annotation['label_id'],
                'user_id' => $this->user->id,
            ];

            if ($idx > 0 && ($idx % static::$insertChunkSize) === 0) {
                $this->insertAnnotationChunk($insertAnnotations, $insertAnnotationLabels);
                $insertAnnotations = [];
                $insertAnnotationLabels = [];
            }
        }

        $this->insertAnnotationChunk($insertAnnotations, $insertAnnotationLabels);
    }

    /**
     * Insert chunk of annotations in the DB
     *
     * @param array $annotations Annotations to upload
     * @param array $annotationLabels Annotation labels to upload
     */
    protected function insertAnnotationChunk(
        array $annotations,
        array $annotationLabels
    ): void {
        ImageAnnotation::insert($annotations);

        $ids = ImageAnnotation::orderBy('id', 'desc')
            ->take(count($annotations))
            ->pluck('id')
            ->reverse()
            ->values()
            ->toArray();

        #we can safely add confidence because we only have Image annotations
        foreach ($annotationLabels as $idx => &$annotationLabel) {
            $annotationLabel['annotation_id'] = $ids[$idx];
            $annotationLabel['confidence'] = 1.0;
        }

        // Flatten. Use array_values to prevent accidental array unpacking with string
        // keys (which makes the linter complain).
        $annotationLabels = array_values($annotationLabels);

        ImageAnnotationLabel::insert($annotationLabels);
    }

    /**
     * Cleanup the existing job from the Volumes attribute
     *
     */
    public function cleanupJob(): void
    {
        Volume::where('attrs->ptp_job_id', $this->jobId)->each(function ($volume) {
            $attrs = $volume->attrs;
            unset($attrs['ptp_job_id']);
            $volume->attrs = $attrs;
            $volume->save();
        });
    }

    /**
     * Cleanup the Job if failed
     *
     * @param  $exception
     */
    public function failed(?Throwable $exception): void
    {
        $this->user->notify(new PtpJobFailed($this->volumeName));
        $this->cleanupJob();
    }

    /**
     * Download the checkpoint if not present
     *
     * @param  $from From where to download the checkpoint
     * @param  $to To where to download the checkpoint
     */
    protected function maybeDownloadCheckpoint($from, $to): void
    {
        if (!File::exists($to)) {
            if (!File::exists(dirname($to))) {
                File::makeDirectory(dirname($to), 0700, true, true);
            }
            $success = @copy($from, $to);

            if (!$success) {
                throw new Exception("Failed to download checkpoint from '{$from}'.");
            }
        }
    }
}
