<?php

namespace Biigle\Tests\Modules\Ptp;

use Biigle\Modules\Ptp\PtpServiceProvider;
use TestCase;

class ModuleServiceProviderTest extends TestCase
{
    public function testServiceProvider()
    {
        $this->assertTrue(class_exists(PtpServiceProvider::class));
    }
}
