<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InterviewSessionLog extends Model
{
    protected $fillable = [
        'interview_session_id',
        'role',
        'content',
    ];

    public function session()
    {
        return $this->belongsTo(InterviewSession::class, 'interview_session_id');
    }
}
