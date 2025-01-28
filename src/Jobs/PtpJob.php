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
use Storage;
use Throwable;

class PtpJob extends BaseJob implements ShouldQueue
{
    use Batchable, InteractsWithQueue, Queueable, SerializesModels;
    /**
     * Job used for converting Point annotations to Polygons
     *
     * @var $outputFile File that will contain the resulting conversions
     * @var $inputFile Input JSON file containing the annotations to convert
     * @var $user User starting the PtpJob
     * @var $id Uuid associated to the job
     *
     */
    public function __construct(public string $inputFile, public string $outputFile, public User $user, public string $id)
    {
        $this->outputFile = config('ptp.temp_dir').'/'.$outputFile;
        $this->inputFile = config('ptp.temp_dir').'/'.$inputFile;
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
            $this->python($paths,  $images);
        };
        $storage = Storage::disk(config('ptp.ptp_storage_disk'));
        $imageIds = array_keys($storage->json($this->inputFile.'.json'));
        $images = Image::whereIn('id', $imageIds)->get()->all();
        FileCache::batch($images, $callback);
        $this->uploadConvertedAnnotations();
        $this->cleanupJob();
    }
    /**
     * Run the python script for Point to Polygon conversion
     *
     * @param  $paths The paths where the images is found
     * @param  $images Array of images
     */
    protected function python(array $paths, array $images): void
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

        $storage = Storage::disk(config('ptp.ptp_storage_disk'));
        $json = $storage->json($this->inputFile.'.json');

        //Create input file with annotations
        $tmpInputFile = $this->inputFile.'.json';

        if (file_exists($tmpInputFile)) {
            unlink($tmpInputFile);
        } else if (!file_exists(dirname($tmpInputFile))){
            mkdir(dirname($tmpInputFile), recursive:true);
        }

        file_put_contents($tmpInputFile, json_encode($json));

        $imagePathInput = [];

        //Create input file with images
        for ($i = 0, $size = count($paths); $i < $size; $i++){
            $imagePathInput[$images[$i]->id] = $paths[$i];
        }

        $tmpInputImageFile = $this->inputFile.'_images.json';

        file_put_contents($tmpInputImageFile, json_encode($imagePathInput));

        if (!file_exists(dirname($this->outputFile))) {
            mkdir(dirname($this->outputFile), recursive:true);
        } else if (file_exists($this->outputFile)){
            unlink($this->outputFile);
        }

        $command = "{$python} -u {$script} --image-paths-file {$tmpInputImageFile} --input-file {$tmpInputFile} --device {$device} --model-type {$modelType} --model-path {$modelPath} --output-file {$this->outputFile} ";

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
        if (is_null($jsonData)) {
            throw new Exception("Error while reading file $this->outputFile");
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

    public function failed(?Throwable $exception): void
    {
        $this->cleanupJob();
    }

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

