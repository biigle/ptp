<?php

namespace Biigle\Tests\Modules\Ptp\Jobs;

use Biigle\ImageAnnotation;
use Biigle\ImageAnnotationLabel;
use Biigle\Modules\Ptp\Exceptions\PythonException;
use Biigle\Modules\Ptp\Jobs\PtpJob;
use Biigle\Shape;
use Biigle\Image;
use Biigle\Label;
use Biigle\User;
use Biigle\Volume;
use Exception;
use Ramsey\Uuid\Uuid;
use TestCase;


class PtpJobTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        $this->volume = Volume::factory()->create();

        $attrs = $this->volume->attrs;
        $this->uuid = Uuid::uuid4();
        $attrs['ptp_job_id'] = $this->uuid;
        $this->volume->attrs = $attrs;
        $this->volume->save();

        $this->image = Image::factory()->create([
            'volume_id' => $this->volume->id,
        ]);

        $this->inputFile = 'test';
        $this->outputFile = 'testOutput.json';

        $this->user = User::factory()->create();
        $this->user2 = User::factory()->create();

        config(['ptp.ptp_storage_disk' => 'test']);
        config(['ptp.device' => 'cpu']);
    }

    private function setUpAnnotations()
    {
        $this->imageAnnotation = ImageAnnotation::factory()->create([
            'image_id' => $this->image->id,
            'shape_id' => Shape::pointId(),
            'points' => [0,0],
        ]);

        $this->label = Label::factory()->create();

        $this->imageAnnotationLabel = ImageAnnotationLabel::factory()->create([
            'annotation_id' => $this->imageAnnotation->id,
            'label_id' => $this->label->id,
            'user_id' => $this->user->id,
        ]);

        //Add an annotation that is not a point annotation to check that it is filtered out
        $this->fakeAnnotation = ImageAnnotation::factory()->create([
            'image_id' => $this->image->id,
            'shape_id' => Shape::polygonId(),
            'points' => [0,0,1,2,3,4,5],
        ]);

        $fakeLabel = Label::factory()->create();

        ImageAnnotationLabel::factory()->create([
            'annotation_id' => $this->fakeAnnotation->id,
            'label_id' => $fakeLabel->id,
            'user_id' => $this->user2->id,
        ]);

        $this->inputFileContents = [
            $this->image->id => [[
                'annotation_id' => $this->imageAnnotation->id,
                'points' => $this->imageAnnotation->points,
                'shape' => Shape::pointId(),
                'image' => $this->image->id,
                'label' => $this->label->id,
        ]]];
    }

    public function testPtpHandle(): void
    {
        //Test that the PTP job handle correctly calls the handle, succedes and cleans up the volume
        $job = new MockPtpJob(
            $this->volume->id, $this->volume->name, $this->inputFile, $this->outputFile, $this->user, $this->uuid
        );

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('No files converted!');
        try {
            file_put_contents(config('ptp.temp_dir').'/'.$this->outputFile, '[]');
            $this->setUpAnnotations();
            $job->handle();
            $this->assertTrue($job->pythonCalled);
            $volume = Volume::where('id', $this->volume->id)->first();
            $this->assertFalse(isset($volume->attrs['ptp_job_id']));
        } finally {
            unlink(config('ptp.temp_dir').'/'.$this->inputFile.'.json');
            unlink(config('ptp.temp_dir').'/'.$this->inputFile.'_images.json');
        }
    }

    public function testPtpGenerateInputFile(): void
    {
        $job = new MockPtpJob($this->volume->id, $this->volume->name, $this->inputFile, $this->outputFile, $this->user, $this->uuid);
        try {
            $this->setUpAnnotations();
            $job->generateInputFile();
            $json = json_decode(file_get_contents(config('ptp.temp_dir').'/'.$this->inputFile.'.json'), true);
            $this->assertEquals($this->inputFileContents, $json);
        } finally {
            unlink(config('ptp.temp_dir').'/'.$this->inputFile.'.json');
        }
    }

    public function testPtpGenerateImageInputFile(): void
    {
        $job = new MockPtpJob($this->volume->id, $this->volume->name, $this->inputFile, $this->outputFile, $this->user, $this->uuid);
        try {
            $job->generateImageInputFile(['testPath'], [$this->image]);
            $json = json_decode(file_get_contents(config('ptp.temp_dir').'/'.$this->inputFile.'_images.json'), true);
            $this->assertEquals($json, [$this->image->id => 'testPath']);
        } finally {
            unlink(config('ptp.temp_dir').'/'.$this->inputFile.'_images.json');
        }
    }

    public function testPtpPythonFailed(): void
    {
        //Here we test that the real python script is called, fails and the PTP job is cleared
        $this->expectException(PythonException::class);
        $this->setUpAnnotations();
        $job = new PtpJob($this->volume->id, $this->volume->name, $this->inputFile, $this->outputFile, $this->user, $this->uuid);
        config(['ptp.python' => 'fake']);
        try {
            $job->handle();
        } finally {
            unlink(config('ptp.temp_dir').'/'.$this->inputFile.'.json');
            unlink(config('ptp.temp_dir').'/'.$this->inputFile.'_images.json');
        }
        $volume = Volume::where('id', $this->volume->id)->first();
        $this->assertFalse(isset($volume->attrs['ptp_job_id']));
    }

    public function testPtpUploadedAnnotations(): void
    {
        //Test that annotations are correctly uploaded by the uploadAnnotations method
        $job = new MockPtpJob($this->volume->id, $this->volume->name, $this->inputFile, $this->outputFile, $this->user2, $this->uuid);
        $this->setUpAnnotations();
        try {
            $outputFileContent = [[
                'points' => [1,2,3,4,5,6],
                'annotation_id' => $this->imageAnnotation->id,
                'label_id' => $this->label->id,
            ]];
            file_put_contents(config('ptp.temp_dir').'/'.$this->outputFile, json_encode($outputFileContent));
            $job->uploadConvertedAnnotations();

            $imageAnnotationValues = ImageAnnotation::where('image_id', $this->image->id)
                ->whereNotIn('id', [$this->imageAnnotation->id, $this->fakeAnnotation->id])
                ->select('id', 'points', 'image_id', 'shape_id')
                ->latest()
                ->first()
                ->toArray();

            $expectedValue = [
                'id' => $imageAnnotationValues['id'],
                'points' => [1,2,3,4,5,6],
                'image_id' => $this->image->id,
                'shape_id' => Shape::polygonId(),
            ];

            $this->assertEquals($expectedValue, $imageAnnotationValues);

            $imageAnnotationLabelValues = ImageAnnotationLabel::where('annotation_id', $imageAnnotationValues['id'])->select('label_id', 'user_id')->first()->toArray();
            $expectedValue = ['label_id' => $this->label->id, 'user_id' => $this->user2->id];
            $this->assertEquals($imageAnnotationLabelValues, $expectedValue);
        } finally {
            unlink(config('ptp.temp_dir').'/'.$this->outputFile);
        }
    }
}

class MockPtpJob extends PtpJob
{
    public bool $pythonCalled = false;

    protected function python(): void
    {
        $this->pythonCalled = true;
    }
}

