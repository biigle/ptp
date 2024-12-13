<?php
namespace Biigle\Modules\Ptp\Jobs;
use Biigle\Modules\Ptp\PtpExpectedArea;
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
class UploadPtpExpectedAreaJob extends BaseJob implements ShouldQueue
{
    use Batchable, InteractsWithQueue, Queueable, SerializesModels;
    /**
     * The queue to push this job to.
     *
     * @var string
     */
    public $queue;

    /**
     * The queue to push this job to.
     *
     * @var string
     */
    public string $inputDir;

    /**
     * ID of the volume associated with this area
     *
     * @var string
     */
    public int $volumeId;

    /**
     * ID of the label associated with this area
     *
     * @var string
     */
    public int $labelId;

    public function __construct(string $inputDir, int $volumeId)
    {
        $this->inputDir = $inputDir;
        $this->volumeId = $volumeId;
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
            $labelId = explode('.', basename($file))[0];
            PtpExpectedArea::updateOrCreate(['volume_id'=> $this->volumeId, 'label_id' => intval($labelId), 'areas' => $jsonData]);
        }
    }
}

