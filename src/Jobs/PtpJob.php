<?php
namespace Biigle\Modules\Ptp\Jobs;
use Biigle\Modules\Maia\GenericImage;
use Biigle\Jobs\Job as BaseJob;
use Biigle\User;
use Biigle\Image;
use Biigle\ImageAnnotations;
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
    use Batchable, InteractsWithQueue, Queueable, SerializesModels;
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
     * Whether to dismiss labels even if they were created by other users.
     *
     * @var Image
     */
    public int $imageId ;

    /**
     * Whether to dismiss labels even if they were created by other users.
     *
     * @var string
     */
    public string $jobType;


    /**
     * Whether to dismiss labels even if they were created by other users.
     *
     * @var ImageAnnotation[]
     */
    public array $annotations ;

    /**
     * Whether to dismiss labels even if they were created by other users.
     *
     * @var ImageAnnotation[]
     */
    public array $points ;

    /**
     * Whether to dismiss labels even if they were created by other users.
     *
     * @var ImageAnnotation[]
     */
    public int $labelId ;
    /**
     * Whether to dismiss labels even if they were created by other users.
     *
     * @var ImageAnnotation[]
     */
    public string $outputFile;

    /**
     * Whether to dismiss labels even if they were created by other users.
     *
     * @var ImageAnnotation[]
     */
    public array $annotationIds;

    /**
     * Whether to dismiss labels even if they were created by other users.
     *
     * @var string
     */
    public string $targetDisk;
    /**
     * Create a new job instance.
     *
     * @param \Biigle\User $user
     * @param ImageAnnotation[] $annotations
     *
     * @return void
     */
    public function __construct(User $user, array $annotations, string $jobType, int $labelId, string $outputFile)
    {
        $this->queue = config('ptp.job_queue');
        $this->targetDisk = config('ptp.ptp_storage_disk');
        $this->user = $user;

        //Assumes that annotations are grouped by images
        $this->imageId = $annotations[0]['image'];
        $this->outputFile = $outputFile;

        // We expect these annotations to be point annotations
        $this->points = [];
        $this->labelId= $labelId;
        $this->annotationIds = [];
        $this->jobType = $jobType;
        foreach ($annotations as $annotation) {
            $this->points[] = implode(',', $annotation['points']);
            //TODO: find a better way to pass this arg.
            $this->annotationIds[] = $annotation['annotation_id'];
        }

    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $image = Image::findOrFail($this->imageId);
        $volumeId = $image->volume->id;
        FileCache::getOnce($image, function ($image, $path) use ($volumeId){
            $this->python($path, $volumeId);
        });
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
    //TODO: add docstring
    /**
     * Run the python script for Point to Polygon conversion
     *
     * @param  $imagePath The path the image is found
     * @param  $volumeId The ID of the volume
     * @param  $log File where the logs from the python script will be found
     */
    protected function python(string $imagePath, int $volumeId, string $log = 'log.txt')
    {
        $code = 0;
        $lines = [];
        $python = config('ptp.python');
        $script = config('ptp.ptp_script');
        $logFile = __DIR__.'/'.$log;
        $points = implode(' ',$this->points);
        $labelId = $this->labelId;
        $device = config('ptp.device');
        $modelPath = config('ptp.model_path');
        $modelType = config('ptp.model_type');
        $imageId = $this->imageId;
        $annotationIds = implode(' ', $this->annotationIds);
        $jobType = $this->jobType;
        $outputFile = $this->outputFile;
        $command = "{$python} -u {$script} {$jobType} -i {$imagePath} --image-id {$imageId} -p {$points} -l {$labelId}  -a {$annotationIds} --device {$device} --model-type {$modelType} --model-path {$modelPath} --output-file {$outputFile} ";
        if ($jobType == 'ptp') {
            $expectedAreaValues = json_decode(PtpExpectedArea::where('label_id', $labelId)->where('volume_id', $volumeId)->first()->areas);
            $medianArea = $this->findMedian($expectedAreaValues);
            $command = $command." -e $medianArea";
        }
        exec("$command > {$logFile} 2>&1", $lines, $code);


        if ($code !== 0) {
            $lines = File::get($logFile);
            throw new Exception("Error while executing python script'{$script}':\n{$lines}", $code);
        }

        $storage = Storage::disk(config('ptp.ptp_storage_disk'));
        $storage->put($outputFile, file_get_contents($outputFile));
    }
}
