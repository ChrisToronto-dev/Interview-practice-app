<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function verify(Request $request)
    {
        $apiKey = $request->input('api_key');

        // Gemini API keys typically start with 'AIza'
        if ($apiKey && str_starts_with($apiKey, 'AIza')) {
            return response()->json(['success' => true]);
        }

        return response()->json(['success' => false, 'message' => 'Invalid Gemini API Key format'], 401);
    }
}
