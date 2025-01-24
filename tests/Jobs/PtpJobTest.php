<?php
namespace Biigle\Tests\Modules\Ptp\Jobs;
use Biigle\Image;
use Biigle\ImageAnnotation;
use Biigle\ImageAnnotationLabel;
use Biigle\Label;
use Biigle\Modules\Ptp\Jobs\PtpJob;
use Biigle\Shape;
use Biigle\UserTest;
use Ramsey\Uuid\Uuid;
use TestCase;
use Storage;

//This test should initialize PtpJobTest, and check that the handle() method is called correctly.

class PtpJobTest extends TestCase
{
    public function testHandle()
    {
        config(['ptp.ptp_storage_disk' => 'test']);
        $image = Image::factory()->create();
        $user = UserTest::factory()->create();
        $contents = [$image->id => []];
        $inputFile = sys_get_temp_dir().'/test.json';
        $storage = Storage::disk('test');
        $storage->put($inputFile, json_encode($contents));
        $outputFile = sys_get_temp_dir().'/testOutput.json';
        $id = Uuid::uuid4();
        $job = new MockPtpJob($inputFile, $outputFile, $user, $id);
        $job->handle();
        $this->assertTrue($job->pythonCalled);
    }

    public function testPython(): void
    {
        $this->expectException();
    }

    public function testUploadedAnnotations(): void
    {
        config(['ptp.ptp_storage_disk' => 'test']);
        $image = Image::factory()->create();
        $imageAnnotation = ImageAnnotation::factory()->create([
            'image_id' => $image->id,
        ]);
        $label = Label::factory()->create();
        $user = UserTest::factory()->create();
        $user2 = UserTest::factory()->create();
        $imageAnnotationLabel = ImageAnnotationLabel::factory()->create([
            'annotation_id' => $imageAnnotation->id,
            'label_id' => $label->id,
            'user_id' => $user->id,
        ]);
        $inputFile = sys_get_temp_dir().'/test.json';
        $outputFile = sys_get_temp_dir().'/testOutput.json';
        $id = Uuid::uuid4();
        $outputFileContent = [[
            'points' => '[1,2,3]',
            'annotation_id' => $imageAnnotation->id,
            'label_id' => $label->id,
        ]];
        file_put_contents($outputFile, $outputFileContent);

        $job = new MockPtpJob($inputFile, $outputFile, $user2, $id);
        $job->uploadConvertedAnnotations();

        $imageAnnotationValues = ImageAnnotation::where('image_id', $image->id)->whereNot('id', $imageAnnotation->id)->select('points', 'image_id', 'shape_id')->get()->all();
        $expectedValue = [
            'points' => '[1,2,3]',
            'image_id' => $image->id,
            'shape_id' => Shape::polygonId(),
        ];
        $this->assertEquals($imageAnnotationValues[0], $expectedValue);

        $imageAnnotationLabelValues = ImageAnnotationLabel::where('annotation_id', $imageAnnotationValues)->select('label_id', 'user_id')->get()->all();
        $expectedValue = ['label_id' => $label->id, 'user_id' => $user2->id];
        $this->assertEquals($imageAnnotationLabelValues[0], $expectedValue);
    }
}

class MockPtpJob extends PtpJob
{
    public bool $pythonCalled = false;

    protected function python(array $paths, array $images): void
    {
        $this->pythonCalled = true;
    }
}

