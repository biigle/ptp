<?php

namespace Biigle\Tests\Modules\Ptp\Jobs;

use Biigle\ImageAnnotation;
use Biigle\ImageAnnotationLabel;
use Biigle\Modules\Ptp\Exception\PythonException;
use Biigle\Modules\Ptp\Jobs\PtpJob;
use Biigle\Shape;
use Biigle\Image;
use Biigle\Label;
use Biigle\User;
use Biigle\Volume;
use Ramsey\Uuid\Uuid;
use Storage;
use TestCase;


class PtpJobTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        $this->volume = Volume::factory()->create();
        $this->image = Image::factory()->create(['volume_id' => $this->volume->id]);
        $attrs = $this->volume->attrs;
        $this->uuid = Uuid::uuid4();
        $attrs['ptp_job_id'] = $this->uuid;
        $this->volume->attrs = $attrs;
        $this->volume->save();
        $this->imageAnnotation = ImageAnnotation::factory()->create([
            'image_id' => $this->image->id,
        ]);
        $this->label = Label::factory()->create();
        $this->user = User::factory()->create();
        $this->user2 = User::factory()->create();
        $this->imageAnnotationLabel = ImageAnnotationLabel::factory()->create([
            'annotation_id' => $this->imageAnnotation->id,
            'label_id' => $this->label->id,
            'user_id' => $this->user->id,
        ]);
        $this->inputFile = 'test';
        $inputFileContent = [
            $this->image->id => [[
                'annotation_id' => $this->imageAnnotation->id,
                'points' => [1,2],
                'shape' => Shape::pointId(),
                'image' => $this->image->id,
                'label' => $this->label->id,
            ]]];
        $this->outputFile = 'testOutput.json';
        $outputFileContent = [[
            'points' => [1,2,3,4,5,6],
            'annotation_id' => $this->imageAnnotation->id,
            'label_id' => $this->label->id,
        ]];

        config(['ptp.ptp_storage_disk' => 'test']);
        config(['ptp.device' => 'cpu']);
        $storage = Storage::disk('test');
        $storage->put(config('ptp.temp_dir').'/'.$this->inputFile.'.json', json_encode($inputFileContent));
        file_put_contents(config('ptp.temp_dir').'/'.$this->outputFile, json_encode($outputFileContent));
    }

    public function testHandle(): void
    {
        //Test that the PTP job handle correctly calls the handle, succedes and cleans up the volume
        $job = new MockPtpJob($this->inputFile, $this->outputFile, $this->user, $this->uuid);
        $job->handle();
        $this->assertTrue($job->pythonCalled);
        $this->assertNull($this->volume->attrs['ptp_job_id']);
    }

    public function testPythonFailed(): void
    {
        //Here we test that the real python script is called, fails and the PTP job is cleared
        $this->expectException(PythonException::class);
        $job = new PtpJob($this->inputFile, $this->outputFile, $this->user, $this->uuid);
        $job->handle();
        $this->assertNull($this->volume->attrs['ptp_job_id']);
    }

    public function testUploadedAnnotations(): void
    {
        //Test that annotations are correctly uploaded by the uploadAnnotations method
        $job = new MockPtpJob($this->inputFile, $this->outputFile, $this->user2, $this->uuid);
        $job->uploadConvertedAnnotations();

        $imageAnnotationValues = ImageAnnotation::where('image_id', $this->image->id)->whereNot('id', $this->imageAnnotation->id)->select('id', 'points', 'image_id', 'shape_id')->get()->all();
        $expectedValue = [
            'id' => $imageAnnotationValues[0]->id,
            'points' => [1,2,3,4,5,6],
            'image_id' => $this->image->id,
            'shape_id' => Shape::polygonId(),
        ];
        $this->assertEquals($imageAnnotationValues[0]->toArray(), $expectedValue);

        $imageAnnotationLabelValues = ImageAnnotationLabel::where('annotation_id', $imageAnnotationValues[0]->id)->select('label_id', 'user_id')->get()->all();
        $expectedValue = ['label_id' => $this->label->id, 'user_id' => $this->user2->id];
        $this->assertEquals($imageAnnotationLabelValues[0]->toArray(), $expectedValue);
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

