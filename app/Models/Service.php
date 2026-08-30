<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Service extends Model
{
    protected $table = 'services';

    protected $primaryKey = 'service_id';

    public $timestamps = false;

    protected $fillable = [
        'service_name',
        'slug',
        'description',
        'base_price',
        'price_small',
        'price_medium',
        'price_large',
        'price_extra_large',
        'price_min',
        'price_max',
        'is_starting_price',
        'is_active',
        'duration_minutes',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_starting_price' => 'boolean',
    ];
}
