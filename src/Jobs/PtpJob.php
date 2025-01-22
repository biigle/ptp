<?php
namespace Biigle\Modules\Ptp\Jobs;
use Biigle\Jobs\Job as BaseJob;
use Biigle\Image;
use Biigle\ImageAnnotation;
use Biigle\ImageAnnotationLabel;
use Biigle\Shape;
use Biigle\User;
use Biigle\Volume;
use Exception;
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
     * Type of the Job between compute-area and ptp
     *
     * @var $outputFile File that will contain the resulting conversions
     * @var $inputFile Input JSON file containing the annotations to convert
     * @var $user User starting the PtpJob
     * @var $volume Volume where the Job is executed
     *
     */
    public function __construct(public string $inputFile, public string $outputFile, public User $user, public int $volumeId)
    {
        $this->outputFile = config('ptp.temp_dir').'/'.$outputFile;
        $this->inputFile = config('ptp.temp_dir').'/'.$inputFile;
        $this->user = $user;
        $this->volumeId = $volumeId;
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

        $storage = Storage::disk(config('ptp.ptp_storage_disk'));
        $json = $storage->json($this->inputFile.'.json');

        //Create input file with annotations
        $tmpInputFile = $this->inputFile;

        if (file_exists($tmpInputFile.'.json')) {
            unlink($tmpInputFile.'.json');
        } else if (!file_exists(dirname($tmpInputFile.'.json'))){
            mkdir(dirname($tmpInputFile.'.json'), recursive:true);
        }

        file_put_contents($tmpInputFile.'.json', json_encode($json));

        $imagePathInput = [];

        //Create input file with images
        for ($i = 0, $size = count($paths); $i < $size; $i++){
            $imagePathInput[$images[$i]->id] = $paths[$i];
        }

        file_put_contents($tmpInputFile.'_images.json', json_encode($imagePathInput));

        if (!file_exists(dirname($this->outputFile))) {
            mkdir(dirname($this->outputFile), recursive:true);
        } else if (file_exists($this->outputFile)){
            unlink($this->outputFile);
        }

        $command = "{$python} -u {$script} --image-paths-file {$tmpInputFile}_images.json --input-file {$tmpInputFile}.json --device {$device} --model-type {$modelType} --model-path {$modelPath} --output-file {$this->outputFile} ";

        exec("$command 2>&1", $lines, $code);

        if ($code !== 0) {
            $lines = implode("\n", $lines);
            throw new Exception("Error while executing python script '{$script}':\n{$lines}", $code);
        }

    }

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

    public function cleanupJob()
    {
        Volume::where('attrs->largo_job_id', $this->volumeId)->each(function ($volume) {
            $attrs = $volume->attrs;
            unset($attrs['largo_job_id']);
            $volume->attrs = $attrs;
            $volume->save();
        });

    }

    public function failed(?Throwable $exception): void
    {
        $this->cleanupJob();
        parent::failed($exception);

    }
}
