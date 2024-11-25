<?php
namespace Biigle\Modules\Ptp;

use Biigle\Modules\Ptp\Database\Factories\PtpExpectedAreaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PtpExpectedArea extends Model
{
    use HasFactory;

    /**
     * Don't maintain timestamps for this model.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'volume_id',
        'label_id',
        'areas',
    ];

    /**
     * Create a new factory instance for the model.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    protected static function newFactory()
    {
        return PtpExpectedAreaFactory::new();
    }
}
