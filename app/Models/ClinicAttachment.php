<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClinicAttachment extends Model
{
    protected $table = 'clinic_attachments';

    protected $fillable = [
        'clinic_record_id',
        'file_name',
        'file_path',
        'file_type',
        'file_size_bytes',
        'label',
    ];

    public function record()
    {
        return $this->belongsTo(ClinicRecord::class, 'clinic_record_id', 'id');
    }
}
