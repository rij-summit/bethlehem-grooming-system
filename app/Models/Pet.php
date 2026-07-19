<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Pet extends Model
{
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
        'medical_conditions',
        'is_archived',
    ];

    // Pet belongs to a user
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }
}