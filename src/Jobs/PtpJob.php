<?php
namespace Biigle\Modules\Ptp\Jobs;
use Biigle\Jobs\Job as BaseJob;
use Biigle\Image;
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
     * Type of the Job between compute-area and ptp
     *
     * @var $inputFile Input JSON file containing the annotations to convert
     * @var $jobType Type of job between `ptp` and `compute-area`
     * @var $outputDir Directory that will contain the resulting conversions or areas
     *
     */
    public function __construct(public string $inputFile, public string $jobType, public string $outputDir)
    {
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
                $this->python($paths[$i],  $images[$i]['id'],$images[$i]['volume_id']);
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
