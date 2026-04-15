<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApiUsageLog extends Model
{
    protected $fillable = [
        'type', // 'gemini' or 'tts'
    ];
}
