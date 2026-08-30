<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatbotInsight extends Model
{
    public const STATUSES = ['new', 'reviewed', 'resolved'];

    protected $fillable = [
        'question_fingerprint',
        'question_excerpt',
        'language',
        'failure_reason',
        'occurrence_count',
        'status',
        'first_seen_at',
        'last_seen_at',
        'reviewed_by_user_id',
        'resolved_at',
    ];

    protected $casts = [
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];
}
