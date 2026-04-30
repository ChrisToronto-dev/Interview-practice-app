<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function verify(Request $request)
    {
        $apiKey = $request->input('api_key');
        $ttsKey = $request->input('tts_key');

        // Groq API keys typically start with 'gsk_'
        $groqValid = $apiKey && str_starts_with($apiKey, 'gsk_');
        $ttsValid = $ttsKey ? str_starts_with($ttsKey, 'AIza') : false;

        if ($groqValid) {
            return response()->json([
                'success' => true,
                'groq_valid' => true,
                'tts_valid' => $ttsValid,
                'tts_provided' => !empty($ttsKey)
            ]);
        }

        return response()->json(['success' => false, 'message' => 'Invalid Groq API Key format'], 401);
    }
}
