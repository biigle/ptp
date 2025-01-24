<?php
namespace Biigle\Tests\Modules\Ptp\Jobs;
use Biigle\ImageAnnotation;
use Biigle\ImageAnnotationLabel;
use Biigle\Modules\Ptp\Jobs\PtpJob;
use Biigle\Shape;
use Biigle\Tests\ImageAnnotationLabelTest;
use Biigle\Tests\ImageAnnotationTest;
use Biigle\Tests\ImageTest;
use Biigle\Tests\LabelTest;
use Biigle\Tests\UserTest;
use Ramsey\Uuid\Uuid;
use TestCase;
use Storage;

//This test should initialize PtpJobTest, and check that the handle() method is called correctly.

class PtpJobTest extends TestCase
{
    public function setUp(): void
    {
        $image = ImageTest::create();
        $imageAnnotation = ImageAnnotationTest::create([
            'image_id' => $image->id,
        ]);
        $this->label = LabelTest::create();
        $this->user = UserTest::create();
        $this->user2 = UserTest::create();
        $imageAnnotationLabel = ImageAnnotationLabelTest::create([
            'annotation_id' => $imageAnnotation->id,
            'label_id' => $this->label->id,
            'user_id' => $this->user->id,
        ]);
        $this->inputFile = sys_get_temp_dir().'/test.json';
        $this->outputFile = sys_get_temp_dir().'/testOutput.json';
        $this->outputFileContent = [[
            'points' => '[1,2,3]',
            'annotation_id' => $this->imageAnnotation->id,
            'label_id' => $this->label->id,
        ]];
        $this->uuid = Uuid::uuid4();
    }


    public function testHandle()
    {
        file_put_contents($this->outputFile, $this->outputFileContent);
        $job = new MockPtpJob($this->inputFile, $this->outputFile, $this->user, $this->uuid);
        $job->handle();
        $this->assertTrue($job->pythonCalled);
    }

    public function testPython(): void
    {
        //TODO: test that python is called and it breaks correctly
        $this->assertTrue(true);
    }

    public function testUploadedAnnotations(): void
    {

        file_put_contents($this->outputFile, $this->outputFileContent);

        $job = new MockPtpJob($this->inputFile, $this->outputFile, $this->user2, $this->uuid);
        $job->uploadConvertedAnnotations();

        $imageAnnotationValues = ImageAnnotation::where('image_id', $this->image->id)->whereNot('id', $this->imageAnnotation->id)->select('points', 'image_id', 'shape_id')->get()->all();
        $expectedValue = [
            'points' => '[1,2,3]',
            'image_id' => $this->image->id,
            'shape_id' => Shape::polygonId(),
        ];
        $this->assertEquals($imageAnnotationValues[0], $expectedValue);

        $imageAnnotationLabelValues = ImageAnnotationLabel::where('annotation_id', $imageAnnotationValues)->select('label_id', 'user_id')->get()->all();
        $expectedValue = ['label_id' => $this->label->id, 'user_id' => $this->user2->id];
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

