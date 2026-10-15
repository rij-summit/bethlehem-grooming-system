<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ClinicServiceCategory extends Model
{
    public const SUPPORTED_NAMES = [
        'Consultation & Fees', 'Laboratory', 'Diagnostic Tests', 'Diagnostic Imaging', 'Confinement',
    ];

    public $timestamps = false;

    protected $fillable = ['name'];

    public function scopeSupported(Builder $query): void
    {
        $query->whereIn('name', self::SUPPORTED_NAMES);
    }
}
