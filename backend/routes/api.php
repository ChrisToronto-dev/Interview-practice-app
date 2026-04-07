<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\AuthController;

Route::post('/auth/verify', [AuthController::class, 'verify']);

use App\Http\Controllers\InterviewController;

Route::middleware('master.auth')->group(function () {
    Route::get('/contexts', [InterviewController::class, 'getContexts']);
    Route::post('/contexts', [InterviewController::class, 'saveContexts']);
    Route::post('/interviews', [InterviewController::class, 'startSession']);
    Route::post('/interviews/{sessionId}/chat', [InterviewController::class, 'chat']);
    Route::post('/tts', [InterviewController::class, 'tts']);
});
