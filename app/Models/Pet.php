<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Pet extends Model
{
    public const CLINIC_VERIFIABLE_FIELDS = [
        'breed',
        'fur_type',
        'weight',
        'size',
    ];

    protected $table = 'pets';

    protected $primaryKey = 'pet_id';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'unregistered_customer_id',
        'pet_name',
        'species',
        'breed',
        'gender',
        'birthdate',
        'is_neutered',
        'neutered_date',
        'is_deceased',
        'deceased_date',
        'weight',
        'color',
        'size',
        'fur_type',
        'clinic_verified_fields',
        'medical_conditions',
        'is_archived',
    ];

    protected $casts = [
        'clinic_verified_fields' => 'array',
    ];

    public static function normalizeName(mixed $name): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim((string) $name)) ?: trim((string) $name);

        return preg_replace_callback(
            '/\p{L}/u',
            static fn (array $match): string => mb_strtoupper($match[0]),
            $normalized,
            1,
        ) ?? $normalized;
    }

    public function setPetNameAttribute(mixed $name): void
    {
        $this->attributes['pet_name'] = self::normalizeName($name);
    }

    // Pet belongs to a user
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function unregisteredCustomer()
    {
        return $this->belongsTo(UnregisteredCustomer::class, 'unregistered_customer_id');
    }

    public function vaccinationRecords()
    {
        return $this->hasMany(VaccinationRecord::class, 'pet_id', 'pet_id');
    }

}
