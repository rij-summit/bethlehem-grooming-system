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

    // Pet belongs to a user
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function vaccinationRecords()
    {
        return $this->hasMany(VaccinationRecord::class, 'pet_id', 'pet_id');
    }

    public function groomingMedicalConcerns()
    {
        return $this->hasMany(GroomingMedicalConcern::class, 'pet_id', 'pet_id');
    }

    public function groomingStoppedPaymentReviews()
    {
        return $this->hasMany(
            GroomingStoppedPaymentReview::class,
            'pet_id',
            'pet_id',
        );
    }

    public function groomingClinicReferrals()
    {
        return $this->hasMany(
            GroomingClinicReferral::class,
            'pet_id',
            'pet_id',
        );
    }
}
