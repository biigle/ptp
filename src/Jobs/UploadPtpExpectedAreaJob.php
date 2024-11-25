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

    public function __construct(string $inputDir, int $volumeId, int $labelId)
    {
        $this->inputDir = $inputDir;
        $this->volumeId = $volumeId;
        $this->labelId = $labelId;
    }
    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        try {
            $files = scandir($this->inputDir);
        } catch (Exception $e){
            throw new Exception("Unable to load files from $this->inputDir");
        }
        $values = [];
        foreach ($files as $file) {
            if ($file == '.' || $file == '..'){
                continue;
            }
            $json = file_get_contents($this->inputDir.'/'.$file);
            if ($json == false){
                throw new Exception("Unable to read file $file");
            }
            $jsonData = json_decode($json, true);
            if (is_null($jsonData)) {
                throw new Exception("Error while reading file $file");
            }
            array_push($values, ...$jsonData);
        }

        $labelId = $this->labelId;
        $volumeId = $this->volumeId;

        PtpExpectedArea::factory->create(['volume_id'=> $volumeId, 'label_id' => $labelId, 'areas' => $values]);

    }
}

