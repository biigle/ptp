<?php
namespace Biigle\Modules\Ptp\Jobs;
use Biigle\Modules\Maia\GenericImage;
use Biigle\Jobs\Job;
use Biigle\User;
use Biigle\Image;
use Biigle\ImageAnnotations;
use Exception;
use File;
use FileCache;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Storage;


class PtpJob extends Job implements ShouldQueue
{
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

    //TODO: Fix comments below

    /**
     * Whether to dismiss labels even if they were created by other users.
     *
     * @var Image
     */
    public Image $image ;


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
    public array $labels ;
    /**
     * Whether to dismiss labels even if they were created by other users.
     *
     * @var ImageAnnotation[]
     */
    public array $expectedAreas ;

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
    public function __construct(User $user, array $annotations)
    {
        $this->queue = config('ptp.job_queue');
        $this->targetDisk = config('ptp.ptp_storage_disk');
        $this->user = $user;
        //Assumes that annotations are grouped by images
        $this->image = $annotations[0]['image'];
        // We expect these annotations to be point annotations
        $this->points = [];
        $this->labels= [];
        $this->expectedAreas = [];
        $this->annotationIds = [];
        foreach ($annotations as $annotation) {
            $this->points[] = implode(',', $annotation['points']);
            $this->labels[] = $annotation['label'];
            $this->expectedAreas[] = $annotation['expected_area'];
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
        try {
            FileCache::getOnce($this->image, function ($image, $path){
                $this->python($path);
            });
        } catch (Exception $e) {
            return $e->getMessage();
        };
        //unsure about this? I deleted the image files by mistake once and I saw that in other places it
        // is added

    }

    //TODO: add docstring
    protected function python(string $imagePath, string $log = 'log.txt')
    {
        $code = 0;
        $lines = [];
        $python = config('ptp.python');
        $script = config('ptp.ptp_script');
        $logFile = __DIR__."/{$log}";
        $points = implode(' ',$this->points);
        $labels = implode(' ', $this->labels);
        $expectedAreas = implode(' ', $this->expectedAreas);
        $device = config('ptp.device');
        $modelPath = config('ptp.model_path');
        $modelType = config('ptp.model_type');
        $imageId = $this->image->id;
        $annotationIds = implode(' ', $this->annotationIds);
        exec("{$python} -u {$script} -i {$imagePath} --image-id {$imageId} -p {$points} -l {$labels} -e {$expectedAreas} -a {$annotationIds} --device {$device} --model-type {$modelType} --model-path {$modelPath} > {$logFile} 2>&1", $lines, $code);

        if ($code !== 0) {
            $lines = File::get($logFile);
            throw new Exception("Error while executing python script'{$script}':\n{$lines}", $code);
        }
    }

}
