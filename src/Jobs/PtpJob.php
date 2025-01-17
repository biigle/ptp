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
    public function __construct(public string $inputFile, public string $outputDir)
    {
        $this->outputDir = $outputDir;
        $this->inputFile = $inputFile;
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
        FileCache::batch($images, $callback); }
    /**
     * Run the python script for Point to Polygon conversion
     *
     * @param  $imagePath The path the image is found
     * @param  $volumeId The ID of the volume
     * @param  $log File where the logs from the python script will be found
     */
    protected function python(array $paths, array $images)
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
        $tmpInputFile = config('ptp.temp_dir').$this->inputFile;
        if (!file_exists(dirname($tmpInputFile))) {
            mkdir(dirname($tmpInputFile), recursive:true);
        } else {
            unlink($tmpInputFile);
        }

        file_put_contents($tmpInputFile.'.json', json_encode($json));

        $imagePathInput = [];

        //Create input file with images
        for ($i = 0, $size = count($paths); $i < $size; $i++){
            $imagePathInput[$images[$i]->id] = $paths[$i];
        }

        file_put_contents($tmpInputFile.'_images.json', json_encode($imagePathInput));

        $tmpOutputFile = config('ptp.temp_dir').'/'.$this->outputDir.'.json';

        if (!file_exists($tmpOutputDir)) {
            mkdir($tmpOutputDir, recursive:true);
        }

        $files = scandir($tmpOutputDir);

        #Clean output directory
        $files = $storage->allFiles($this->outputDir);
        $storage->delete($files);

        $command = "{$python} -u {$script} --image-paths-file {$tmpInputFile}_images.json --input-file {$tmpInputFile}.json --device {$device} --model-type {$modelType} --model-path {$modelPath} --output-file {$tmpOutputFile} ";

        exec("$command 2>&1", $lines, $code);

        if ($code !== 0) {
            $lines = implode("\n", $lines);
            throw new Exception("Error while executing python script '{$script}':\n{$lines}", $code);
        }

        //TODO: upload conversions here
    }
}
