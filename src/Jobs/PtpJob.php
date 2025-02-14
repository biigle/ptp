<?php
namespace Biigle\Modules\Ptp\Jobs;
use Biigle\Jobs\Job as BaseJob;
use Biigle\Image;
use Biigle\ImageAnnotation;
use Biigle\ImageAnnotationLabel;
use Biigle\Modules\Ptp\Exceptions\PythonException;
use Biigle\Shape;
use Biigle\User;
use Biigle\Volume;
use Exception;
use File;
use FileCache;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Bus\Batchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class PtpJob extends BaseJob implements ShouldQueue
{
    use Batchable, InteractsWithQueue, Queueable, SerializesModels;
    private string $tmpInputFile;
    private string $tmpImageInputFile;
    /**
     * Job used for converting Point annotations to Polygons
     *
     * @var $volumeId Id of the volume for the PTP Job
     * @var $outputFile File that will contain the resulting conversions
     * @var $inputFile Input JSON file containing the annotations to convert
     * @var $user User starting the PtpJob
     * @var $id Uuid associated to the job
     *
     */
    public function __construct(public int $volumeId, public string $inputFile, public string $outputFile, public User $user, public string $id)
    {
        $this->volumeId = $volumeId;
        $this->outputFile = config('ptp.temp_dir').'/'.$outputFile;
        $this->tmpInputFile = config('ptp.temp_dir').'/'.$inputFile.'.json';
        $this->tmpImageInputFile = config('ptp.temp_dir').'/'.$inputFile.'_images.json';
        $this->user = $user;
        $this->id = $id;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $callback = function ($images, $paths){
            $this->generateImageInputFile($paths,  $images);
        };
        $imageData = $this->generateInputFile();
        $imageIds = array_keys($imageData);
        $images = Image::whereIn('id', $imageIds)->get()->all();
        FileCache::batch($images, $callback);
        $this->python();
        $this->uploadConvertedAnnotations();
        $this->cleanupJob();
    }

    /**
     * Generate the input Job File
     *
     * @return
     */
    public function generateInputFile(): array
    {
        $imageAnnotationArray = [];

        $pointShapeId = Shape::pointId(); //Find annotations with selected label in desired volume
        $annotations = ImageAnnotation::join('image_annotation_labels','image_annotations.id', '=', 'image_annotation_labels.annotation_id')
            ->join('images','image_annotations.image_id', '=','images.id')
            ->where('images.volume_id', $this->volumeId)
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

        //$inputFile.'.json' will be used for image annotations, $inputFile.'_images.json' for image paths
        $jsonData = json_encode($imageAnnotationArray);

        //Create input file with annotations
        if (file_exists($this->tmpInputFile)) {
            unlink($this->tmpInputFile);
        } else if (!file_exists(dirname($this->tmpInputFile))){
            mkdir(dirname($this->tmpInputFile), recursive:true);
        }

        file_put_contents($this->tmpInputFile, $jsonData);
        return $imageAnnotationArray;
    }

    //TODO: test if i can generate image input file here
    /**
     *
     *
     * @param  $paths
     * @param  $images
     */
    public function generateImageInputFile(array $paths, array $images): void
    {
        $imagePathInput = [];
        //Create input file with images
        for ($i = 0, $size = count($paths); $i < $size; $i++){
            $imagePathInput[$images[$i]->id] = $paths[$i];
        }

        file_put_contents($this->tmpImageInputFile, json_encode($imagePathInput));
    }

    /**
     * Run the python script for Point to Polygon conversion
     *
     * @param  $paths The paths where the images is found
     * @param  $images Array of images
     */
    protected function python(): void
    {
        $code = 0;
        $lines = [];
        $python = config('ptp.python');
        $script = config('ptp.ptp_script');
        $device = config('ptp.device');
        $modelPath = config('ptp.model_path');
        $modelType = config('ptp.model_type');
        $checkpointUrl = config('ptp.model_url');

        $this->maybeDownloadCheckpoint($checkpointUrl, $modelPath);

        if (!file_exists(dirname($this->outputFile))) {
            mkdir(dirname($this->outputFile), recursive:true);
        } else if (file_exists($this->outputFile)){
            unlink($this->outputFile);
        }

        $command = "{$python} -u {$script} --image-paths-file {$this->tmpImageInputFile} --input-file {$this->tmpInputFile} --device {$device} --model-type {$modelType} --model-path {$modelPath} --output-file {$this->outputFile} ";

        exec("$command 2>&1", $lines, $code);

        if ($code !== 0) {
            $lines = implode("\n", $lines);
            throw new PythonException("Error while executing python script '{$script}':\n{$lines}", $code);
        }
    }

    /**
     * Upload the converted annotations to the DB
     *
    **/
    public function uploadConvertedAnnotations(): void
    {
        $jsonData = json_decode(file_get_contents($this->outputFile), true);
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

    /**
     * Cleanup the existing job from the Volumes attribute
     *
    **/
    public function cleanupJob(): void
    {
        Volume::where('attrs->ptp_job_id', $this->id)->each(function ($volume) {
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

