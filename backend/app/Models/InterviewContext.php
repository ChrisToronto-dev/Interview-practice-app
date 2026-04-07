<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InterviewContext extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'type',
        'content',
    ];
}
