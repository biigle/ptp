<?php

namespace Biigle\Tests\Modules\Ptp\Jobs;

use Biigle\Image;
use Biigle\ImageAnnotation;
use Biigle\ImageAnnotationLabel;
use Biigle\Jobs\ProcessAnnotatedImage;
use Biigle\Label;
use Biigle\Modules\Ptp\Exceptions\PythonException;
use Biigle\Modules\Ptp\Jobs\PtpJob;
use Biigle\Shape;
use Biigle\User;
use Biigle\Volume;
use Exception;
use File;
use Queue;
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

        $this->image2 = Image::factory()->create([
            'volume_id' => $this->volume->id,
            // Use the same file but don't use the same filename than the first image.
            'filename' => "./test-image.jpg",
        ]);

        //Since we mock the PtpJob to have a batch size of 2, this image should NEVER be processed.
        $this->image3 = Image::factory()->create([
            'volume_id' => $this->volume->id,
            'filename' => "mock-image.png",
        ]);

        $this->inputFile = config('ptp.temp_dir').'/ptp/input-files/'.$this->volume->id;
        $this->outputFile = config('ptp.temp_dir').'/'.'ptp/'.$this->volume->id.'_converted_annotations.csv';

        if (!File::exists(dirname($this->inputFile))) {
            File::makeDirectory(dirname($this->inputFile), 0700, true, true);
        }

        if (!File::exists(dirname($this->outputFile))) {
            File::makeDirectory(dirname($this->outputFile), 0700, true, true);
        }

        $this->user = User::factory()->create();
        $this->user2 = User::factory()->create();

        config(['ptp.device' => 'cpu']);
    }

    private function setUpAnnotations()
    {
        // When generating these annotations, a ProcessAnnotatedImage job is generated.
        $this->imageAnnotation = ImageAnnotation::factory()->create([
            'image_id' => $this->image->id,
            'shape_id' => Shape::pointId(),
            'points' => [0, 0],
        ]);

        $this->imageAnnotation2 = ImageAnnotation::factory()->create([
            'image_id' => $this->image2->id,
            'shape_id' => Shape::pointId(),
            'points' => [0, 0],
        ]);

        $this->label = Label::factory()->create();
        $this->label2 = Label::factory()->create();

        $this->imageAnnotationLabel = ImageAnnotationLabel::factory()->create([
            'annotation_id' => $this->imageAnnotation->id,
            'label_id' => $this->label->id,
            'user_id' => $this->user->id,
        ]);

        $this->imageAnnotationLabel2 = ImageAnnotationLabel::factory()->create([
            'annotation_id' => $this->imageAnnotation2->id,
            'label_id' => $this->label2->id,
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
            ]],
            $this->image2->id =>[[
                'annotation_id' => $this->imageAnnotation2->id,
                'points' => $this->imageAnnotation->points,
                'shape' => Shape::pointId(),
                'image' => $this->image2->id,
                'label' => $this->label2->id,
            ]]];
    }

    public function testPtpHandleWithoutConvertedFiles(): void
    {
        //Test that the PTP job correctly calls the handle, fails without converted files and cleans up the volume
        $job = new MockPtpJob(
            $this->volume,
            $this->user,
            $this->uuid,
            false,
        );

        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Unable to find output file $this->outputFile");
        try {
            $this->setUpAnnotations();
            $job->handle();
            $this->assertEquals(1, $job->pythonCalled);
            $volume = Volume::where('id', $this->volume->id)->first();
            $this->assertFalse(isset($volume->attrs['ptp_job_id']));

            //Check that the job actually cleaned up the files after execution.
            $this->assertFalse(File::exists($this->inputFile.'.json'));
            $this->assertFalse(File::exists($this->inputFile.'_images.json'));
            $this->assertFalse(File::exists($this->outputFile));
        } finally {
            File::delete($this->inputFile.'.json');
            File::delete($this->inputFile.'_images.json');
        }
    }

    public function testPtpHandleWithEmptyConvertedFiles(): void
    {
        //Test that the PTP job correctly calls the handle, fails without converted files and cleans up the volume
        $job = new MockPtpJob(
            $this->volume,
            $this->user,
            $this->uuid,
            true,
            true,
        );

        $this->expectException(Exception::class);
        $this->expectExceptionMessage("No annotations were converted!");
        try {
            $this->setUpAnnotations();
            $job->handle();
            $this->assertEquals(1, $job->pythonCalled);
            $volume = Volume::where('id', $this->volume->id)->first();
            $this->assertFalse(isset($volume->attrs['ptp_job_id']));

            //Check that the job actually cleaned up the files after execution.
            $this->assertFalse(File::exists($this->inputFile.'.json'));
            $this->assertFalse(File::exists($this->inputFile.'_images.json'));
            $this->assertFalse(File::exists($this->outputFile));
        } finally {
            File::delete($this->inputFile.'.json');
            File::delete($this->inputFile.'_images.json');
        }
    }

    public function testPtpGenerateInputFile(): void
    {
        $job = new MockPtpJob($this->volume, $this->user, $this->uuid);
        try {
            $this->setUpAnnotations();
            $imageData = $job->generateInputFile($this->volume->images());
            $this->assertTrue(isset($imageData[$this->image->id]));

            $imageData[$this->image->id] = $imageData[$this->image->id]->pluck('id');
            $imageData[$this->image2->id] = $imageData[$this->image2->id]->pluck('id');
            $expectedImageData = [
                $this->image->id => $this->image->pluck('id'),
                $this->image2->id => $this->image2->pluck('id'),
            ];

            $this->assertEquals($expectedImageData, $imageData);

            $json = json_decode(File::get($this->inputFile.'.json'), true);
            $this->assertEquals($this->inputFileContents, $json);

            //Case with empty image
            $imageData = $job->generateInputFile(collect([$this->image3]));
            $this->assertTrue(empty($imageData));
        } finally {
            File::delete($this->inputFile.'.json');
        }
    }

    public function testPtpGenerateImageInputFile(): void
    {
        $job = new MockPtpJob($this->volume, $this->user, $this->uuid);
        try {
            $job->generateImageInputFile(['testPath', 'testPath2'], [$this->image, $this->image2]);
            $json = json_decode(File::get($this->inputFile.'_images.json'), true);
            $expectedImageInput = [
                $this->image->id => 'testPath',
                $this->image2->id => 'testPath2',
            ];
            $this->assertEquals($expectedImageInput, $json);

        } finally {
            File::delete($this->inputFile.'_images.json');
        }
    }

    public function testPtpPythonFailed(): void
    {
        //Here we test that the real python script is called, fails and the PTP job is cleared
        $this->expectException(PythonException::class);
        $this->setUpAnnotations();
        $job = new PtpJob($this->volume, $this->user, $this->uuid);
        config(['ptp.python' => 'fake']);
        try {
            $job->handle();

            //Check that the job actually cleaned up files after execution.
            $this->assertFalse(File::exists($this->inputFile.'.json'));
            $this->assertFalse(File::exists($this->inputFile.'_images.json'));
            $this->assertFalse(File::exists($this->outputFile));
        } finally {
            File::delete($this->inputFile.'.json');
            File::delete($this->inputFile.'_images.json');
        }
        $volume = Volume::where('id', $this->volume->id)->first();
        $this->assertFalse(isset($volume->attrs['ptp_job_id']));
    }

    public function testPtpUploadedAnnotations(): void
    {
        //Test that annotations are correctly uploaded by the uploadAnnotations method
        $job = new MockPtpJob($this->volume, $this->user2, $this->uuid);
        $this->setUpAnnotations();
        try {
            $outputFileContent = [
                [
                    'annotation_id' => null,
                    'points' => [1, 2, 3, 4, 5, 6],
                    'image_id' => $this->image->id,
                    'label_id' => $this->label->id,
                ],
                [
                    'annotation_id' => null,
                    'points' => [1, 2, 3, 4, 5, 6],
                    'image_id' => $this->image2->id,
                    'label_id' => $this->label2->id,
                ],
                //This annotation should not be uploaded
                [
                    'annotation_id' => null,
                    'points' => null,
                    'image_id' => $this->image2->id,
                    'label_id' => $this->label2->id,
                ],
            ];

            $header = implode(',', [
                'annotation_id',
                'points',
                'image_id',
                'label_id'
            ]);
            $csvArray = array_map(function ($row) {
                if (!is_null($row['points'])) {
                    $row['points'] = '"['.implode(',', $row['points']).']"' ;
                }
                return implode(',', $row);
            }, $outputFileContent);
            $csv = $header."\n".implode("\n", $csvArray);
            File::put($this->outputFile, $csv);

            $job->uploadConvertedAnnotations();

            $imageAnnotationValues = ImageAnnotation::whereIn('image_id', [$this->image->id, $this->image2->id])
                ->whereNotIn('id', [$this->imageAnnotation->id, $this->imageAnnotation2->id, $this->fakeAnnotation->id])
                ->select('id', 'points', 'image_id', 'shape_id')
                ->get();

            $ids = $imageAnnotationValues->pluck('id');

            $this->assertEquals(2, count($ids));

            $expectedValue = [[
                'id' => $ids[0],
                'points' => [1, 2, 3, 4, 5, 6],
                'image_id' => $this->image->id,
                'shape_id' => Shape::polygonId(),
            ], [
                'id' => $ids[1],
                'points' => [1, 2, 3, 4, 5, 6],
                'image_id' => $this->image2->id,
                'shape_id' => Shape::polygonId(),
            ]];

            $this->assertEquals($expectedValue, $imageAnnotationValues->toArray());

            $imageAnnotationLabelValues = ImageAnnotationLabel::whereIn('annotation_id', $ids)
                ->select('label_id', 'user_id')
                ->get()
                ->toArray();

            $expectedLabelValue = [
                [
                    'label_id' => $this->label->id,
                    'user_id' => $this->user2->id
                ],
                [
                    'label_id' => $this->label2->id,
                    'user_id' => $this->user2->id
                ]
            ];
            $this->assertEquals($expectedLabelValue, $imageAnnotationLabelValues);

            // Check if timestamps are populated.
            $count = ImageAnnotation::whereIn('id', $ids)
                ->whereNotNull('created_at')
                ->whereNotNull('updated_at')
                ->count();
            $this->assertEquals(2, $count);

            $count = ImageAnnotationLabel::whereIn('annotation_id', $ids)
                ->whereNotNull('created_at')
                ->whereNotNull('updated_at')
                ->count();
            $this->assertEquals(2, $count);

            // Here there are 5 jobs because 3 are generated when the setting up annotations
            Queue::assertPushed(ProcessAnnotatedImage::class, 5);

            //Jobs should appear in the exact order
            $idx = 0;
            Queue::assertPushed(ProcessAnnotatedImage::class, function ($job) use ($expectedValue, &$idx) {
                match ($idx) {
                    0 => $this->assertJobIsRight($this->imageAnnotation, $job),
                    1 => $this->assertJobIsRight($this->imageAnnotation2, $job),
                    2 => $this->assertJobIsRight($this->fakeAnnotation, $job),
                    3 => $this->assertJobIsRight($expectedValue[0], $job),
                    4 => $this->assertJobIsRight($expectedValue[1], $job),
                };

                $idx += 1;

                return true;
            });
        } finally {
            File::delete($this->outputFile);
        }
    }

    public function testPtpSuccessfulHandle(): void
    {
        //Test that the PTP job handle is executed correctly from start to finish.
        $this->setUpAnnotations();


        $job = new MockPtpJob(
            $this->volume,
            $this->user,
            $this->uuid,
            true,
        );

        try {
            $job->handle();
            $this->assertEquals(1, $job->pythonCalled);
            $volume = Volume::where('id', $this->volume->id)->first();
            $this->assertFalse(isset($volume->attrs['ptp_job_id']));

            //Check that the job actually cleaned up the files after execution
            $this->assertFalse(File::exists($this->inputFile.'.json'));
            $this->assertFalse(File::exists($this->inputFile.'_images.json'));
            $this->assertFalse(File::exists($this->outputFile));

            $imageAnnotationValues = ImageAnnotation::whereIn('image_id', [$this->image->id, $this->image2->id])
                ->whereNotIn('id', [$this->imageAnnotation->id, $this->imageAnnotation2->id, $this->fakeAnnotation->id])
                ->select('id', 'points', 'image_id', 'shape_id')
                ->get();

            $ids = $imageAnnotationValues->pluck('id');

            $this->assertEquals(2, count($ids));

            $expectedValue = [[
                'id' => $ids[0],
                'points' => [1, 2, 3, 4],
                'image_id' => $this->image->id,
                'shape_id' => Shape::polygonId(),
            ],
                [
                    'id' => $ids[1],
                    'points' => [1, 2, 3, 4],
                    'image_id' => $this->image2->id,
                    'shape_id' => Shape::polygonId(),
                ]];

            $this->assertEquals($expectedValue, $imageAnnotationValues->toArray());

            $imageAnnotationLabelValues = ImageAnnotationLabel::whereIn('annotation_id', $ids)
                ->select('label_id', 'user_id')
                ->get()
                ->toArray();
            $expectedLabelValue = [['label_id' => $this->label->id, 'user_id' => $this->user->id], ['label_id' => $this->label2->id, 'user_id' => $this->user->id]];
            $this->assertEquals($expectedLabelValue, $imageAnnotationLabelValues);

            // Here there are 5 jobs because 3 are generated when the setting up annotations
            Queue::assertPushed(ProcessAnnotatedImage::class, 5);

            //Jobs should appear in the exact order
            $idx = 0;

            Queue::assertPushed(ProcessAnnotatedImage::class, function ($job) use ($expectedValue, &$idx) {
                match ($idx) {
                    0 => $this->assertJobIsRight($this->imageAnnotation, $job),
                    1 => $this->assertJobIsRight($this->imageAnnotation2, $job),
                    2 => $this->assertJobIsRight($this->fakeAnnotation, $job),
                    3 => $this->assertJobIsRight($expectedValue[0], $job),
                    4 => $this->assertJobIsRight($expectedValue[1], $job),
                };
                $idx += 1;
                return true;
            });

        } finally {
            File::delete($this->inputFile.'.json');
            File::delete($this->inputFile.'_images.json');
            File::delete($this->outputFile);
        }
    }

    public function assertJobIsRight($expectedValue, $job): bool
    {

        $this->assertEquals($expectedValue['image_id'], $job->file->id);
        $this->assertEquals([$expectedValue['id']], $job->only);
        return true;
    }
}

class MockPtpJob extends PtpJob
{
    //If > 1, it means that the function has been called more than once.
    //That should not happen, because it means that it was called for image3
    public int $pythonCalled = 0;

    //useful to test that empty images are not processed in a chunk
    public static int $imageChunkSize = 2;

    public function __construct(
        public Volume $volume,
        public User $user,
        public string $jobId,
        public bool $generateOutput = false,
        public bool $emptyOutput = false,
    ) {
        $args = array_slice(func_get_args(), 0, 3, true);

        parent::__construct(...$args);
    }

    protected function python(): void
    {
        $this->pythonCalled += 1;

        if ($this->generateOutput) {
            $csv = '';
            if (!$this->emptyOutput) {
                $csv .= 'annotation_id,points,image_id,label_id';
                $json = json_decode(File::get($this->tmpInputFile), true);
                foreach ($json as $imageId => $mockValues) {
                    foreach ($mockValues as $annotation) {
                        $csv .= "\n{$annotation['annotation_id']},\"[1,2,3,4]\",{$imageId},{$annotation['label']}";
                    }
                }
            }
            File::put($this->outputFile, $csv);
        }
    }
}
