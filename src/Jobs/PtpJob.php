<?php
namespace Biigle\Modules\Ptp\Jobs;
use Biigle\Jobs\Job as BaseJob;
use Biigle\User;
use Biigle\Image;
use Biigle\ImageAnnotation;
use Biigle\Modules\Ptp\PtpExpectedArea;
use Exception;
use File;
use FileCache;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Bus\Batchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Storage;

class PtpJob extends BaseJob implements ShouldQueue
{
    //TODO: Check which ones are actually useful
    use Batchable, InteractsWithQueue, Queueable, SerializesModels;
    //TODO: rewrite following PR suggestion
    /**
     * The queue to push this job to.
     *
     * @var string
     */
    public $queue;

    /**
     * Number of times to retry this job.
     *
     * @var integer
     */
    public $tries = 1;

    /**
     * The job ID.
     *
     * @var string
     */
    public $id;

    /**
     * The user who submitted the Largo session.
     *
     * @var \Biigle\User
     */
    public $user;

    /**
     * Array of all dismissed image annotation IDs for each label.
     *
     * @var array
     */
    public $dismissedImageAnnotations;

    /**
     * Array of all changed image annotation IDs for each label.
     *
     * @var array
     */
    public $changedImageAnnotations;

    /**
     * Array of all dismissed video annotation IDs for each label.
     *
     * @var array
     */
    public $dismissedVideoAnnotations;

    /**
     * Array of all changed video annotation IDs for each label.
     *
     * @var array
     */
    public $changedVideoAnnotations;

    /**
     * Whether to dismiss labels even if they were created by other users.
     *
     * @var bool
     */
    public $force;

    //TODO: Fix comments below and remove the useless ones above

    /**
     * ID of the image where PTP is applied
     *
     * @var int
     */
    public int $imageId ;

    /**
     * Type of the Job between compute-area and ptp
     *
     * @var string
     */
    public string $jobType;


    /**
     * Array of annotations
     *
     * @var array
     */
    public array $annotations ;

    /**
     * Array of annotation coordinates (Points)
     *
     * @var array
     */
    public array $points ;

    /**
     * ID of the label of the annotation to convert
     *
     * @var int
     */
    public int $labelId ;
    /**
     * Output file where the PTP job output will be stored
     *
     * @var string
     */
    public string $outputDir;

    /**
     * IDs of the annotations to convert
     *
     * @var array
     */
    public array $images;


    /**
     * IDs of the annotations to convert
     *
     * @var array
     */
    public string $outputFile;

    /**
     * IDs of the annotations to convert
     *
     * @var array
     */
    public string $inputFile;

    /**
     * Create a new job instance.
     *
     * @param \Biigle\User $user
     * @param ImageAnnotation[] $annotations
     *
     * @return void
     */
    public function __construct(User $user, string $inputFile, string $jobType, string $outputDir)
    {
        $this->queue = config('ptp.job_queue');
        $this->user = $user;

        $this->outputDir = $outputDir;
        $this->inputFile = $inputFile;

        $this->jobType = $jobType;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $callback = function ($images, $paths){
            for ($i = 0; $i < count($images); $i++){
                $this->python($paths[$i], $images[$i]['volume_id'], $images[$i]['id']);
            };
        };
        $storage = Storage::disk(config('ptp.ptp_storage_disk'));
        $images = array_map(fn ($imageId): Image => Image::findOrFail($imageId), array_keys($storage->json($this->inputFile)));

        FileCache::batch($images, $callback);
    }
    /**
     * Run the python script for Point to Polygon conversion
     *
     * @param  $imagePath The path the image is found
     * @param  $volumeId The ID of the volume
     * @param  $log File where the logs from the python script will be found
     */
    protected function python(string $imagePath, int $imageId, int $volumeId, string $log = 'log.txt')
    {
        $code = 0;
        $lines = [];
        $python = config('ptp.python');
        $script = config('ptp.ptp_script');
        $logFile = config('ptp.temp_dir').'/'.$log;
        $device = config('ptp.device');
        $modelPath = config('ptp.model_path');
        $modelType = config('ptp.model_type');
        $jobType = $this->jobType;
        $outputDir = $this->outputDir;


        $storage = Storage::disk(config('ptp.ptp_storage_disk'));
        $json = $storage->json($this->inputFile);
        $tmpInputFile = config('ptp.temp_dir').$this->inputFile;
        if (!file_exists(dirname($tmpInputFile))) {
            mkdir(dirname($tmpInputFile), recursive:true);
        } else {
            unlink($tmpInputFile);
        }

        file_put_contents($tmpInputFile, json_encode($json));
        $tmpOutputDir = config('ptp.temp_dir').'/'.$this->outputDir;

        if (!file_exists($tmpOutputDir)) {
            mkdir($tmpOutputDir, recursive:true);
        }

        $files = scandir($tmpOutputDir);

        #Clean output directory
        $files = $storage->allFiles($this->outputDir);
        $storage->delete($files);

        $command = "{$python} -u {$script} {$jobType} -i {$imagePath} --image-id {$imageId}  --input-file {$tmpInputFile} --device {$device} --model-type {$modelType} --model-path {$modelPath} --output-dir {$tmpOutputDir} ";

        exec("$command > {$logFile} 2>&1", $lines, $code);

        if ($code !== 0) {
            $lines = File::get($logFile);
            throw new Exception("Error while executing python script'{$script}':\n{$lines}", $code);
        }
        $files = scandir($tmpOutputDir);
        foreach ($files as $file){
            if ($file == '.' | $file =='..'){
                continue;
            }
            $storage->put($outputDir.$file, file_get_contents($tmpOutputDir.$file));
        }
    }
}
