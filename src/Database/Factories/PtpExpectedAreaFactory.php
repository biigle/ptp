<?php

namespace Biigle\Modules\Ptp\Database\Factories;

use Biigle\Label;
use Biigle\Modules\Ptp\PtpExpectedArea;
use Biigle\Volume;
use Illuminate\Database\Eloquent\Factories\Factory;

class PtpExpectedAreaFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = PtpExpectedArea::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'label_id' => Label::factory(),
            'volume_id' => Volume::factory(),
            'areas' => json_encode([]),
        ];
    }
}
