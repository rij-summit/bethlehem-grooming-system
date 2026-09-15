<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatbotFeedback extends Model
{
    protected $table = 'chatbot_feedback';

    protected $fillable = [
        'response_id',
        'helpful',
        'question_excerpt',
        'answer_excerpt',
        'answer_source',
    ];

    protected $casts = [
        'helpful' => 'boolean',
    ];
}
