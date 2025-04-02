<?php

namespace Biigle\Tests\Modules\Ptp\Jobs;

use Biigle\Image;
use Biigle\ImageAnnotation;
use Biigle\ImageAnnotationLabel;
use Biigle\Label;
use Biigle\Modules\Ptp\Exceptions\PythonException;
use Biigle\Modules\Ptp\Jobs\PtpJob;
use Biigle\Shape;
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

        $this->inputFile = config('ptp.temp_dir').'/ptp/input-files/'.$this->volume->id;
        $this->outputFile = config('ptp.temp_dir').'/'.'ptp/'.$this->volume->id.'_converted_annotations.json';

        if (!file_exists(dirname($this->inputFile))) {
            mkdir(dirname($this->inputFile), recursive: true);
        }

        if (!file_exists(dirname($this->outputFile))) {
            mkdir(dirname($this->outputFile), recursive: true);
        }

        $this->user = User::factory()->create();
        $this->user2 = User::factory()->create();

        config(['ptp.device' => 'cpu']);
    }

    private function setUpAnnotations()
    {
        $this->imageAnnotation = ImageAnnotation::factory()->create([
            'image_id' => $this->image->id,
            'shape_id' => Shape::pointId(),
            'points' => [0, 0],
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
            'points' => [0, 0, 1, 2, 3, 4, 5],
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

    public function testPtpHandleWithoutConvertedFiles(): void
    {
        //Test that the PTP job correctly calls the handle, fails without converted files and cleans up the volume
        $job = new MockPtpJob(
            $this->volume->id,
            $this->volume->name,
            $this->user,
            $this->uuid,
            true,
            []
        );

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('No files converted!');
        try {
            $this->setUpAnnotations();
            $job->handle();
            $this->assertTrue($job->pythonCalled);
            $volume = Volume::where('id', $this->volume->id)->first();
            $this->assertFalse(isset($volume->attrs['ptp_job_id']));
        } finally {
            unlink($this->inputFile.'.json');
            unlink($this->inputFile.'_images.json');
        }
    }

    public function testPtpGenerateInputFile(): void
    {
        $job = new MockPtpJob($this->volume->id, $this->volume->name, $this->user, $this->uuid);
        try {
            $this->setUpAnnotations();
            $job->generateInputFile();
            $json = json_decode(file_get_contents($this->inputFile.'.json'), true);
            $this->assertEquals($this->inputFileContents, $json);
        } finally {
            unlink($this->inputFile.'.json');
        }
    }

    public function testPtpGenerateImageInputFile(): void
    {
        $job = new MockPtpJob($this->volume->id, $this->volume->name, $this->user, $this->uuid);
        try {
            $job->generateImageInputFile(['testPath'], [$this->image]);
            $json = json_decode(file_get_contents($this->inputFile.'_images.json'), true);
            $this->assertEquals($json, [$this->image->id => 'testPath']);
        } finally {
            unlink($this->inputFile.'_images.json');
        }
    }

    public function testPtpPythonFailed(): void
    {
        //Here we test that the real python script is called, fails and the PTP job is cleared
        $this->expectException(PythonException::class);
        $this->setUpAnnotations();
        $job = new PtpJob($this->volume->id, $this->volume->name, $this->user, $this->uuid);
        config(['ptp.python' => 'fake']);
        try {
            $job->handle();
        } finally {
            unlink($this->inputFile.'.json');
            unlink($this->inputFile.'_images.json');
        }
        $volume = Volume::where('id', $this->volume->id)->first();
        $this->assertFalse(isset($volume->attrs['ptp_job_id']));
    }

    public function testPtpUploadedAnnotations(): void
    {
        //Test that annotations are correctly uploaded by the uploadAnnotations method
        $job = new MockPtpJob($this->volume->id, $this->volume->name, $this->user2, $this->uuid);
        $this->setUpAnnotations();
        try {
            $outputFileContent = [[
                'points' => [1, 2, 3, 4, 5, 6],
                'image_id' => $this->image->id,
                'label_id' => $this->label->id,
            ]];

            file_put_contents($this->outputFile, json_encode($outputFileContent));
            $job->uploadConvertedAnnotations();

            $imageAnnotationValues = ImageAnnotation::where('image_id', $this->image->id)
                ->whereNotIn('id', [$this->imageAnnotation->id, $this->fakeAnnotation->id])
                ->select('id', 'points', 'image_id', 'shape_id')
                ->latest()
                ->first()
                ->toArray();

            $expectedValue = [
                'id' => $imageAnnotationValues['id'],
                'points' => [1, 2, 3, 4, 5, 6],
                'image_id' => $this->image->id,
                'shape_id' => Shape::polygonId(),
            ];

            $this->assertEquals($expectedValue, $imageAnnotationValues);

            $imageAnnotationLabelValues = ImageAnnotationLabel::where('annotation_id', $imageAnnotationValues['id'])->select('label_id', 'user_id')->first()->toArray();
            $expectedValue = ['label_id' => $this->label->id, 'user_id' => $this->user2->id];
            $this->assertEquals($imageAnnotationLabelValues, $expectedValue);
        } finally {
            unlink($this->outputFile);
        }
    }

    public function testPtpSuccessfulHandle(): void
    {
        //Test that the PTP job handle is executed correctly from start to finish.
        $this->setUpAnnotations();

        $outputFileContent = [[
            'points' => [1, 2, 3, 4, 5, 6],
            'image_id' => $this->image->id,
            'label_id' => $this->label->id,
        ]];

        $job = new MockPtpJob(
            $this->volume->id,
            $this->volume->name,
            $this->user,
            $this->uuid,
            true,
            $outputFileContent
        );

        try {
            $job->handle();
            $this->assertTrue($job->pythonCalled);
            $volume = Volume::where('id', $this->volume->id)->first();
            $this->assertFalse(isset($volume->attrs['ptp_job_id']));

            $imageAnnotationValues = ImageAnnotation::where('image_id', $this->image->id)
                ->whereNotIn('id', [$this->imageAnnotation->id, $this->fakeAnnotation->id])
                ->select('id', 'points', 'image_id', 'shape_id')
                ->latest()
                ->first()
                ->toArray();

            $expectedValue = [
                'id' => $imageAnnotationValues['id'],
                'points' => [1, 2, 3, 4, 5, 6],
                'image_id' => $this->image->id,
                'shape_id' => Shape::polygonId(),
            ];
            $this->assertEquals($expectedValue, $imageAnnotationValues);

            $imageAnnotationLabelValues = ImageAnnotationLabel::where('annotation_id', $imageAnnotationValues['id'])->select('label_id', 'user_id')->first()->toArray();
            $expectedValue = ['label_id' => $this->label->id, 'user_id' => $this->user->id];
            $this->assertEquals($imageAnnotationLabelValues, $expectedValue);
        } finally {
            unlink($this->inputFile.'.json');
            unlink($this->inputFile.'_images.json');
            unlink($this->outputFile);
        }
    }
}

class MockPtpJob extends PtpJob
{
    public bool $pythonCalled = false;

    public function __construct(
        public int $volumeId,
        public string $volumeName,
        public User $user,
        public string $jobId,
        public bool $generateOutput = false,
        public array $mockOutputData = [],
    ) {
        $this->generateOutput = $generateOutput;
        $this->mockOutputData = $mockOutputData;
        $args = array_slice(func_get_args(), 0, 4, true);

        parent::__construct(...$args);
    }

    protected function python(): void
    {
        $this->pythonCalled = true;

        if ($this->generateOutput) {
            file_put_contents($this->outputFile, json_encode($this->mockOutputData));
        }
    }
}
