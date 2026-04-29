<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function verify(Request $request)
    {
        $apiKey = $request->input('api_key');
        $ttsKey = $request->input('tts_key');

        // Gemini API keys typically start with 'AIza'
        $geminiValid = $apiKey && str_starts_with($apiKey, 'AIza');
        $ttsValid = $ttsKey ? str_starts_with($ttsKey, 'AIza') : false;

        if ($geminiValid) {
            return response()->json([
                'success' => true,
                'gemini_valid' => true,
                'tts_valid' => $ttsValid,
                'tts_provided' => !empty($ttsKey)
            ]);
        }

        return response()->json(['success' => false, 'message' => 'Invalid Gemini API Key format'], 401);
    }
}
