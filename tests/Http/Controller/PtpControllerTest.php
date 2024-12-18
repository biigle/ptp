<?php

namespace Biigle\Tests\Ptp\Modules\Jobs;

use Biigle\MediaType;
use ApiTestCase;
use Biigle\Image;
use Biigle\Modules\MagicSam\Jobs\GenerateEmbedding;
use Illuminate\Support\Facades\Queue;
use Biigle\MediaType;


class PtpControllerTest extends ApiTestCase
{
    public function testCreateJob()
    {
        Queue::fake();
        $image = Image::factory()->create(['volume_id' => $this->volume()->id]);

        $this->doTestApiRoute('POST', "/api/v1/send-ptp-job");

        $this->beGlobalGuest();
        $this->postJson("/api/v1/send-ptp-job", ['volume_id' => $this->volume()->id])->assertStatus(403);

        $this->beUser();
        $this->postJson("/api/v1/send-ptp-job", ['volume_id' => $this->volume()->id])->assertStatus(403);


        $v = VolumeTest::create(['media_type_id' => MediaType::videoId()]);
        $this->beSuperUser();
        $this->postJson("/api/v1/send-ptp-job", ['volume_id' => $v->id])->assertStatus(503);


        $this->beSuperUser();
        $this->postJson("/api/v1/send-ptp-job")
            ->assertStatus(200)
            ->assertExactJson(['url' => null]);

        Queue::assertPushedOn('gpu-quick', function (GenerateEmbedding $job) use ($image) {
            $this->assertEquals($image->id, $job->image->id);
            $this->assertEquals($this->guest()->id, $job->user->id);

            return true;
        });
    }
}

