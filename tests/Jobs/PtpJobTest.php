<?php
namespace Biigle\Tests\Ptp\Modules\Jobs;
use Biigle\Image;
use Biigle\Modules\Ptp\Jobs\PtpJob;
use TestCase;

//This test should initialize PtpJobTest, and check that the handle() method is called correctly.

class PtpJobTest extends TestCase
{
    public function testHandle()
    {
        $image = Image::factory()->create();
        $contents = [$image->id => []];
        $inputFile = sys_get_temp_dir().'/test-input/test.json';
        file_put_contents($inputFile, json_encode($contents));
        $outputDir = sys_get_temp_dir().'/test-output/';
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

