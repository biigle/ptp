<?php

namespace Biigle\Tests\Modules\Ptp\Controller;

use ApiTestCase;
use Biigle\MediaType;
use Biigle\Role;
use Biigle\Tests\ImageTest;
use Biigle\Tests\ProjectTest;
use Biigle\Tests\UserTest;
use Biigle\Tests\VolumeTest;
use Illuminate\Support\Facades\Queue;


class PtpControllerTest extends ApiTestCase
{
    public function testCreateJob()
    {
        Queue::fake();
        $image = ImageTest::create(['volume_id' => $this->volume()->id]);

        config(['ptp.ptp_storage_disk' => 'test']);

        $this->doTestApiRoute('POST', '/api/v1/send-ptp-job');

        $this->beGlobalGuest();
        $this->postJson('/api/v1/send-ptp-job', ['volume_id' => $this->volume()->id])->assertStatus(403);

        $this->beUser();
        $this->postJson('/api/v1/send-ptp-job', ['volume_id' => $this->volume()->id])->assertStatus(403);

        $this->beEditor();
        $this->postJson('/api/v1/send-ptp-job', ['volume_id' => $this->volume()->id])
            ->assertStatus(200);
    }

    public function testVideoVolumes()
    {
        $project = ProjectTest::create();
        $volume = VolumeTest::create(['media_type_id' => MediaType::videoId()]);
        $project->addVolumeId($volume->id);

        $user = UserTest::create();
        $project->addUserId($user->id, Role::editorId());

        $this->be($user);

        $this->postJson('/api/v1/send-ptp-job', ['volume_id' => $volume->id])->assertStatus(400);
    }

    public function testTiledImages()
    {
        $image = ImageTest::create(['volume_id' => $this->volume()->id, 'tiled' => true]);
        $this->beEditor();
        $this->postJson('/api/v1/send-ptp-job', ['volume_id' => $this->volume()->id])->assertStatus(400);

    }
}

