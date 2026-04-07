<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InterviewSession extends Model
{
    protected $fillable = [
        'title',
    ];

    public function logs()
    {
        return $this->hasMany(InterviewSessionLog::class);
    }
}
