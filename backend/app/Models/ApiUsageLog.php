<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApiUsageLog extends Model
{
    protected $fillable = [
        'type',       // 'gemini' or 'tts'
        'char_count', // Character count for TTS calls (Google TTS usage tracking)
    ];
}
