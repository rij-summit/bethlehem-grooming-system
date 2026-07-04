<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClinicSetting extends Model
{
    public const SINGLETON_ID = 1;

    public const DEFAULT_GROOMERS_ON_DUTY = 2;

    public const MIN_GROOMERS_ON_DUTY = 1;

    public const MAX_GROOMERS_ON_DUTY = 5;

    protected $fillable = [
        'groomers_on_duty',
    ];

    protected $casts = [
        'groomers_on_duty' => 'integer',
    ];

    public static function current(bool $lockForUpdate = false): self
    {
        $query = static::query()->whereKey(self::SINGLETON_ID);

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->first() ?? static::query()->forceCreate([
            'id' => self::SINGLETON_ID,
            'groomers_on_duty' => self::DEFAULT_GROOMERS_ON_DUTY,
        ]);
    }
}
