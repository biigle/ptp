<?php

namespace Biigle\Modules\Ptp\Jobs;

use Biigle\Image;
use Biigle\ImageAnnotation;
use Biigle\ImageAnnotationLabel;
use Biigle\Jobs\Job as BaseJob;
use Biigle\Jobs\ProcessAnnotatedImage;
use Biigle\Modules\Ptp\Exceptions\PythonException;
use Biigle\Modules\Ptp\Notifications\PtpJobConcluded;
use Biigle\Modules\Ptp\Notifications\PtpJobFailed;
use Biigle\Shape;
use Biigle\User;
use Biigle\Volume;
use Carbon\Carbon;
use DB;
use Exception;
use File;
use FileCache;
use Generator;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use SplFileObject;
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
     * Number of images to be processed per chunk
     * @var int
     */
    public static int $imageChunkSize = 100;

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
     * List of columns of the CSV file
     */
    protected $annotatedFileColumns = [
        'annotation_id',
        'points',
        'image_id',
        'label_id',
    ];

    /**
     * Job used for converting Point annotations to Polygons
     *
     * @param Volume $volume Volume for the PTP Job
     * @param User $user User starting the PtpJob
     * @param string $jobId Uuid associated to the job
     *
     */
    public function __construct(
        public Volume $volume,
        public User $user,
        public string $jobId,
    ) {

        //$inputFile.'.json' will be used for image annotations, $inputFile.'_images.json' for image paths
        $inputFile = 'ptp/input-files/'.$volume->id;

        $outputFile = 'ptp/'.$volume->id.'_converted_annotations.csv';

        $this->outputFile = config('ptp.temp_dir').'/'.$outputFile;
        $this->tmpInputFile = config('ptp.temp_dir').'/'.$inputFile.'.json';
        $this->tmpImageInputFile = config('ptp.temp_dir').'/'.$inputFile.'_images.json';
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        DB::transaction(function () {
            $callback = function ($images, $paths) {
                $this->generateImageInputFile($paths, $images);
                $this->python();
            };
            $this->volume->images()->chunkById(static::$imageChunkSize, function ($chunk) use ($callback) {
                $imageData = $this->generateInputFile($chunk);

                //$imageData can be empty if we have a chunk of images without an annotation
                if (!empty($imageData)) {
                    FileCache::batch($imageData, $callback);
                    $this->uploadConvertedAnnotations();
                }
            });
        });
        $this->user->notify(new PtpJobConcluded($this->volume));
        $this->cleanupJob();
        $this->cleanupFiles();
    }

    /**
     * Generate the input File containing data for the execution of the job
     *
     * @param $chunk Collection of images to process at a time
     * @return array
     */
    public function generateInputFile($chunk): array
    {
        $imageAnnotationArray = [];

        $pointShapeId = Shape::pointId();

        $annotations = ImageAnnotation::join('image_annotation_labels', 'image_annotations.id', '=', 'image_annotation_labels.annotation_id')
            ->join('images', 'image_annotations.image_id', '=', 'images.id')
            ->where('images.volume_id', $this->volume->id)
            ->whereIn('image_annotations.image_id', $chunk->pluck('id'))
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
        //To correctly build the image -> paths json file, we need indexed arrays
        $paths = array_values($paths);
        $images = array_values($images);

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
        if (File::missing($this->outputFile)) {
            return;
        }

        $insertAnnotations = [];
        $insertAnnotationLabels = [];
        foreach ($this->iterateOverCsvFile($this->outputFile) as $idx => $annotation) {
            $polygonShape = Shape::polygonId();

            $now = Carbon::now();

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
                'label_id' => intval($annotation['label_id']),
                'user_id' => $this->user->id,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if ($idx > 0 && ($idx % static::$insertChunkSize) === 0) {
                $this->insertAnnotationChunk($insertAnnotations, $insertAnnotationLabels);
                $insertAnnotations = [];
                $insertAnnotationLabels = [];
            }
        }
        if (count($insertAnnotations) > 0) {
            $this->insertAnnotationChunk($insertAnnotations, $insertAnnotationLabels);
        }
    }

    /**
     * Insert annotation in the DB
     *
     * @param array $annotations Annotation to upload
     * @param array $annotationLabels Annotation label to upload
     */
    protected function insertAnnotationChunk(
        array $annotations,
        array $annotationLabels
    ): void {
        ImageAnnotation::insert($annotations);

        $newImageAnnotations = ImageAnnotation::orderBy('id', 'desc')
            ->take(count($annotations))
            ->get(['id', 'image_id'])
            ->reverse()
            ->values()
            ->toArray();

        #we can safely add confidence because we only have Image annotations
        foreach ($annotationLabels as $idx => &$annotationLabel) {
            $annotationLabel['annotation_id'] = $newImageAnnotations[$idx]['id'];
            $annotationLabel['confidence'] = 1.0;
        }

        ImageAnnotationLabel::insert($annotationLabels);

        $this->processNewAnnotations($newImageAnnotations);
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
     * Cleanup the files that the job might have created
     */
    public function cleanupFiles(): void
    {
        File::delete([$this->outputFile, $this->tmpInputFile, $this->tmpImageInputFile]);
    }

    /**
     * Cleanup the Job if failed
     *
     * @param  $exception
     */
    public function failed(?Throwable $exception): void
    {
        $this->user->notify(new PtpJobFailed($this->volume));
        $this->cleanupJob();
        $this->cleanupFiles();
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

    /**
     * Generate annotation chunks for new annotations
     *
     * @param  $a Annotations to process
     */
    protected function processNewAnnotations(array $a)
    {

        $annotations = collect($a)
            ->groupBy('image_id')
            ->map(fn ($an) => $an->pluck('id'));
        $images = Image::whereIn('id', $annotations->keys())->get();

        foreach ($images as $image) {
            ProcessAnnotatedImage::dispatch($image, only: $annotations->get($image->id)->toArray());
        }
    }

    /**
     * Create a generator that iterates the lines of a CSV file containing annotation results from the PTP conversion.
     * @param $file CSV file to open
     * @return Generator
     */
    protected function iterateOverCsvFile(
        string $file,
    ): Generator {

        if (File::size($file) == 0) {
            throw new Exception('No annotations were converted!');
        }

        $iterator = $this->getCsvFile($file);

        $header = $iterator->fgetcsv();
        if ($header !== $this->annotatedFileColumns) {
            throw new Exception("Annotation file $file is malformed");
        }

        while ($data = $iterator->fgetcsv()) {

            #Can be caused by trailing newline.
            if (count($data) == 1 && is_null($data[0])) {
                continue;
            }

            $tmpChunk = array_combine($header, $data);
            $tmpChunk['points'] = json_decode($tmpChunk['points']);
            yield $tmpChunk;
        }
    }

    /**
     * Open A CSV file.
     * @param $file CSV file to open
     * @return SplFileObject
     */
    protected function getCsvFile(string $file): SplFileObject
    {
        $file = new SplFileObject($file);
        $file->setFlags(SplFileObject::READ_CSV);

        return $file;
    }
}
