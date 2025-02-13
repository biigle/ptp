<?php

namespace Biigle\Tests\Modules\Ptp\Controller;

use ApiTestCase;
use Biigle\Image;
use Biigle\ImageAnnotation;
use Biigle\MediaType;
use Biigle\Shape;
use Biigle\Volume;
use Illuminate\Testing\Fluent\AssertableJson;

class PtpControllerTest extends ApiTestCase
{
    public function testCreateJob(): void
    {
        $image = Image::factory()->create(['volume_id' => $this->volume()->id]);

        //Test creating a Job with different types of users
        $imageAnnotation = ImageAnnotation::factory()->create([
            'image_id' => $image->id,
            'shape_id' => Shape::pointId(),
        ]);
        config(['ptp.ptp_storage_disk' => 'test']);

        $url = '/api/v1/send-ptp-job/'.$this->volume()->id;

        $this->beGlobalGuest();
        $this->postJson($url)->assertStatus(403);

        $this->beUser();
        $this->postJson($url)->assertStatus(403);

        $this->beEditor();
        $this->postJson($url)->assertStatus(200);
    }

    public function testSetPtpJobId(): void
    {
        //Test that when creating a Job a ptp_job_id is set
        $image = Image::factory()->create(['volume_id' => $this->volume()->id]);

        $imageAnnotation = ImageAnnotation::factory()->create([
            'image_id' => $image->id,
            'shape_id' => Shape::pointId(),
        ]);

        $url = '/api/v1/send-ptp-job/'.$this->volume()->id;

        config(['ptp.ptp_storage_disk' => 'test']);

        $this->beEditor();
        $this->postJson($url)->assertStatus(200);

        //$this->volume does not update with attrs
        $volume = Volume::where('id', $this->volume()->id)->first();

        $this->assertTrue(isset($volume->attrs['ptp_job_id']));

        //If a ptp_job_id is set on a volume, we should get an error
        $this->postJson($url)->assertStatus(400)->assertJson(
            fn (AssertableJson $json) =>
                $json->where('message', 'Another point to polygon conversion job is running in this volume!')
                     ->etc()
        );
    }

    public function testVideoVolumes()
    {
        $this->volume(['media_type_id' => MediaType::videoId()]);

        $this->beEditor();

        $url = '/api/v1/send-ptp-job/'.$this->volume()->id;

        $this->postJson($url)->assertStatus(400)->assertJson(
            fn (AssertableJson $json) =>
                $json->where('message', 'Point to polygon conversion cannot be executed on this volume!')
                     ->etc()
        );
    }

    public function testTiledImages()
    {

        $image = Image::factory()->create([
            'volume_id' => $this->volume()->id,
            'tiled' => true,
        ]);

        $imageAnnotation = ImageAnnotation::factory()->create([
            'image_id' => $image->id,
            'shape_id' => Shape::pointId(),
        ]);

        $this->beEditor();


        $url = '/api/v1/send-ptp-job/'.$this->volume()->id;

        $this->postJson($url)->assertStatus(400)->assertJson(
            fn (AssertableJson $json) =>
                $json->where('message', 'Point to polygon conversion cannot be executed on this volume!')
                     ->etc()
        );
;
    }

    public function testNoImageAnnotations()
    {
        $this->beEditor();

        $url = '/api/v1/send-ptp-job/'.$this->volume()->id;

        $this->postJson($url)->assertStatus(400)->assertJson(
            fn (AssertableJson $json) =>
                $json->where('message', 'No point annotations to convert!')
                     ->etc()
        );
;
    }
}

