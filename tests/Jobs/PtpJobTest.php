<?php
namespace Biigle\Tests\Modules\Ptp\Jobs;
use Biigle\Image;
use Biigle\Modules\Ptp\Jobs\PtpJob;
use TestCase;
use Storage;

//This test should initialize PtpJobTest, and check that the handle() method is called correctly.

class PtpJobTest extends TestCase
{
    public function testHandle()
    {
        config(['ptp.ptp_storage_disk' => 'test']);
        $image = Image::factory()->create();
        $contents = [$image->id => []];
        $inputFile = sys_get_temp_dir().'/test.json';
        $storage = Storage::disk('test');
        $storage->put($inputFile, json_encode($contents));
        $outputDir = sys_get_temp_dir();
        $job = new MockPtpJob($inputFile, 'compute-area', $outputDir);
        $job->handle();
        $this->assertTrue($job->pythonCalled);
    }
}

class MockPtpJob extends PtpJob
{
    public bool $pythonCalled = false;
    protected function python(string $imagePath, int $imageId, string $log = 'log.txt')
    {
        $this->pythonCalled = true;
    }

}

