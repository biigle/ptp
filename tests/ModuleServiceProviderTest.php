<?php

namespace Biigle\Tests\Modules\Ptp;

use Biigle\Modules\Ptp\ModuleServiceProvider;
use TestCase;

class ModuleServiceProviderTest extends TestCase
{
    public function testServiceProvider()
    {
        $this->assertTrue(class_exists(ModuleServiceProvider::class));
    }
}
