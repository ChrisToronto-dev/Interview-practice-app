<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Models\InterviewContext;
use App\Models\InterviewSession;
use App\Models\InterviewSessionLog;
use App\Models\ApiUsageLog;
use Smalot\PdfParser\Parser;

class InterviewController extends Controller
{
    public function getContexts()
    {
        return response()->json(InterviewContext::all());
    }

    public function saveContexts(Request $request)
    {
        $request->validate([
            'resume' => 'nullable|string',
            'qna' => 'nullable|string',
            'job_posting' => 'nullable|string',
        ]);

        if ($request->has('resume')) {
            InterviewContext::updateOrCreate(['type' => 'resume'], ['content' => $request->resume ?? '']);
        }
        if ($request->has('qna')) {
            InterviewContext::updateOrCreate(['type' => 'qna'], ['content' => $request->qna ?? '']);
        }
        if ($request->has('job_posting')) {
            InterviewContext::updateOrCreate(['type' => 'job_posting'], ['content' => $request->job_posting ?? '']);
        }

        return response()->json(['success' => true]);
    }

    public function extractPdf(Request $request)
    {
        $request->validate([
            'pdf' => 'required|file|mimes:pdf|max:10240', // Max 10MB
        ]);

        try {
            $parser = new Parser();
            $pdf = $parser->parseFile($request->file('pdf')->path());
            $text = $pdf->getText();

            // Clean up unnecessary consecutive spaces or line breaks in text
            $text = preg_replace('/\n\s*\n/', "\n\n", $text);

            return response()->json(['text' => trim($text)]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to parse PDF: ' . $e->getMessage()], 500);
        }
    }

    public function getUsage()
    {
        $geminiLimit  = (int) env('GEMINI_DAILY_LIMIT', 500);
        $ttsLimit     = (int) env('GEMINI_TTS_DAILY_LIMIT', 100);
        $googleTtsMonthlyLimit = 1_000_000; // Google TTS Neural2 free tier: 1M chars/month

        $today     = now()->toDateString();
        $monthStart = now()->startOfMonth()->toDateString();

        $geminiUsed = ApiUsageLog::where('type', 'gemini')
            ->whereDate('created_at', $today)
            ->count();

        $ttsUsed = ApiUsageLog::where('type', 'tts')
            ->whereDate('created_at', $today)
            ->count();

        // Google TTS: sum of characters converted this month
        $ttsCharsThisMonth = (int) ApiUsageLog::where('type', 'tts')
            ->whereDate('created_at', '>=', $monthStart)
            ->sum('char_count');

        // Calculate remaining questions based on TTS bottleneck
        $remaining = max(0, $ttsLimit - $ttsUsed);

        return response()->json([
            'gemini' => [
                'used'      => $geminiUsed,
                'limit'     => $geminiLimit,
                'remaining' => max(0, $geminiLimit - $geminiUsed),
            ],
            'tts' => [
                'used'      => $ttsUsed,
                'limit'     => $ttsLimit,
                'remaining' => max(0, $ttsLimit - $ttsUsed),
            ],
            'google_tts' => [
                'chars_this_month'  => $ttsCharsThisMonth,
                'monthly_limit'     => $googleTtsMonthlyLimit,
                'chars_remaining'   => max(0, $googleTtsMonthlyLimit - $ttsCharsThisMonth),
            ],
            'questions_remaining' => $remaining,
            'reset_at'            => now()->endOfDay()->toIso8601String(),
        ]);
    }

    public function startSession(Request $request)
    {
        $session = InterviewSession::create([
            'title' => 'Session ' . now()->format('Y-m-d H:i:s'),
        ]);

        $reply = $this->callGroq([]);
        ApiUsageLog::create(['type' => 'groq']);

        $session->logs()->create([
            'role' => 'assistant',
            'content' => $reply
        ]);

        return response()->json([
            'session_id' => $session->id,
            'reply' => $reply,
        ]);
    }

    public function chat(Request $request, $sessionId)
    {
        $request->validate([
            'message' => 'required|string'
        ]);

        $session = InterviewSession::findOrFail($sessionId);

        $session->logs()->create([
            'role' => 'user',
            'content' => $request->message
        ]);

        $reply = $this->callGroq($session->logs()->get());
        ApiUsageLog::create(['type' => 'groq']);

        $session->logs()->create([
            'role' => 'assistant',
            'content' => $reply
        ]);

        return response()->json([
            'reply' => $reply,
        ]);
    }

    public function feedback(Request $request, $sessionId)
    {
        $session = InterviewSession::findOrFail($sessionId);
        $logs = $session->logs()->orderBy('id')->get();

        $apiKey = request()->header('X-Groq-Api-Key') ?? env('GROQ_API_KEY');
        $url = "https://api.groq.com/openai/v1/chat/completions";

        $systemInstruction = "You are an expert interview coach. Review the following mock interview transcript between an AI interviewer and an applicant. "
            . "Provide constructive, detailed feedback on the applicant's answers. Point out strengths and areas for improvement. "
            . "Format your response in beautiful, readable Markdown.";

        $transcript = "";
        foreach ($logs as $log) {
            $role = $log->role === 'assistant' ? 'Interviewer' : 'Applicant';
            $transcript .= "{$role}: {$log->content}\n\n";
        }

        $payload = [
            'model' => 'llama-3.3-70b-versatile',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $systemInstruction
                ],
                [
                    'role' => 'user',
                    'content' => "Here is the interview transcript:\n\n" . $transcript
                ]
            ],
            'temperature' => 0.7,
        ];

        $response = Http::withToken($apiKey)->timeout(60)->post($url, $payload);
        
        if ($response->successful()) {
            ApiUsageLog::create(['type' => 'groq']);
            $json = $response->json();
            $feedbackText = $json['choices'][0]['message']['content'] ?? "Failed to generate feedback.";
            return response()->json(['feedback' => $feedbackText]);
        }

        return response()->json(['error' => 'Failed to fetch feedback from AI'], 500);
    }

    private function callGroq($logs)
    {
        $apiKey = request()->header('X-Groq-Api-Key');
        if (empty($apiKey)) {
            $apiKey = env('GROQ_API_KEY');
            \Illuminate\Support\Facades\Log::info("Header missing, using env key: " . substr($apiKey, 0, 10) . '...');
        } else {
            \Illuminate\Support\Facades\Log::info("Header present, using header key: " . substr($apiKey, 0, 10) . '...');
        }
        $url = "https://api.groq.com/openai/v1/chat/completions";
        
        $resume = InterviewContext::where('type', 'resume')->first()?->content ?? '';
        $qna = InterviewContext::where('type', 'qna')->first()?->content ?? '';
        $jobPosting = InterviewContext::where('type', 'job_posting')->first()?->content ?? '';

        $systemInstruction = "You are a professional technical interviewer. ";

        if (!empty($jobPosting)) {
            $systemInstruction .= "The applicant has provided their resume, a Q&A base, and a job posting. "
                . "Ask them interview questions sequentially based on their context, specifically matching their skills to the job posting. ";
        } else {
            $systemInstruction .= "The applicant has provided their resume and a Q&A base (expected questions). "
                . "CRITICAL INSTRUCTION: You MUST ask interview questions sequentially and STRICTLY from the provided Q&A Base. Do not invent or ask any questions outside of the Q&A Base. ";
        }

        $systemInstruction .= "IMPORTANT: Keep each response to 1-2 sentences MAX. Ask only ONE question at a time. "
            . "Be conversational and natural like a real voice interview. No markdown, no bullet points, no lists. "
            . "If the candidate's answer is good, give a very brief acknowledgment (a few words) then ask your next question. "
            . "Speak in English.\n\n";

        if (!empty($jobPosting)) {
            $systemInstruction .= "Job Posting:\n" . $jobPosting . "\n\n";
        }
        $systemInstruction .= "Resume:\n" . $resume . "\n\nQ&A Base:\n" . $qna;

        $messages = [];
        $messages[] = [
            'role' => 'system',
            'content' => $systemInstruction
        ];

        if (count($logs) === 0) {
            $messages[] = [
                'role' => 'user',
                'content' => 'Hello, I am ready for the interview. Please start by asking my first question.'
            ];
        } else {
            foreach ($logs as $log) {
                $messages[] = [
                    'role' => $log->role === 'assistant' ? 'assistant' : 'user',
                    'content' => $log->content
                ];
            }
        }

        $payload = [
            'model' => 'llama-3.3-70b-versatile',
            'messages' => $messages,
            'max_tokens' => 150,
            'temperature' => 0.8,
        ];

        // Try up to 2 times for transient errors
        $response = null;
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $response = Http::withToken($apiKey)->timeout(45)->post($url, $payload);
            if ($response->successful()) break;
            // Only retry on 5xx server errors (not 4xx client errors)
            if ($response->status() < 500) break;
            if ($attempt < 2) sleep(1); // Brief pause before retry
        }

        if ($response->successful()) {
            $json = $response->json();
            return $json['choices'][0]['message']['content'] ?? "Failed to generate text.";
        }

        $status = $response->status();
        return "API Error ({$status}): Please try again.";
    }

    private function callGoogleTTS(string $text): ?array
    {
        $apiKey = request()->header('X-Google-TTS-Key') ?? env('GOOGLE_TTS_API_KEY');
        if (!$apiKey) {
            \Illuminate\Support\Facades\Log::warning('Google TTS API key not configured');
            return null;
        }

        $url = "https://texttospeech.googleapis.com/v1/text:synthesize?key={$apiKey}";

        $payload = [
            'input'       => ['text' => $text],
            'voice'       => [
                'languageCode' => 'en-US',
                'name'         => 'en-US-Neural2-J', // Professional male interviewer voice
            ],
            'audioConfig' => [
                'audioEncoding' => 'MP3',
                'speakingRate'  => 1.0,
                'pitch'         => 0.0,
            ],
        ];

        $response = Http::timeout(30)->post($url, $payload);

        if (!$response->successful()) {
            \Illuminate\Support\Facades\Log::warning('Google TTS failed', [
                'status' => $response->status(),
                'body'   => substr($response->body(), 0, 500),
            ]);
            return null;
        }

        $json = $response->json();
        $audioContent = $json['audioContent'] ?? null;
        if (!$audioContent) return null;

        return [
            'base64' => $audioContent,
            'mime_type' => 'audio/mpeg'
        ];
    }
    public function tts(Request $request)
    {
        $request->validate(['text' => 'required|string|max:2000']);

        $audioData = $this->callGoogleTTS($request->text);

        if (!$audioData) {
            return response()->json(['error' => 'TTS generation failed on Google Cloud. Please configure Google TTS API keys.'], 500);
        }

        ApiUsageLog::create([
            'type'       => 'tts',
            'char_count' => mb_strlen($request->text), // Track characters for Google TTS usage
        ]);

        return response()->json([
            'audio_base64' => $audioData['base64'],
            'mime_type'    => $audioData['mime_type'],
        ]);
    }
}
