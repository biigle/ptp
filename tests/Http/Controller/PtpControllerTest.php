<?php

namespace Biigle\Tests\Modules\Ptp\Jobs;

use Biigle\MediaType;
use ApiTestCase;
use Biigle\Image;
use Biigle\Tests\VolumeTest;
use Biigle\Modules\MagicSam\Jobs\GenerateEmbedding;
use Illuminate\Support\Facades\Queue;


class PtpControllerTest extends ApiTestCase
{
    public function testCreateJob()
    {
        Queue::fake();
        $image = Image::factory()->create(['volume_id' => $this->volume()->id]);

        config(['ptp.ptp_storage_disk' => 'test']);

        $this->doTestApiRoute('POST', '/api/v1/send-ptp-job');

        $this->beGlobalGuest();
        $this->postJson('/api/v1/send-ptp-job', ['volume_id' => $this->volume()->id])->assertStatus(403);

        $this->beUser();
        $this->postJson('/api/v1/send-ptp-job', ['volume_id' => $this->volume()->id])->assertStatus(403);

        $this->beEditor();
        $this->postJson('/api/v1/send-ptp-job', ['volume_id' => $this->volume()->id])
            ->assertStatus(200);

        $v = VolumeTest::create(['media_type_id' => MediaType::videoId()]);

        $this->beEditor();
        $this->postJson('/api/v1/send-ptp-job', ['volume_id' => $v->id])->assertStatus(503);

        $v2 = VolumeTest::create(['media_type_id' => MediaType::imageId()]);
        $image = Image::factory()->create(['volume_id' => $this->v2->id, 'tiled' => true]);

        $this->beEditor();
        $this->postJson('/api/v1/send-ptp-job', ['volume_id' => $v->id])->assertStatus(503);
     }
}

