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

        $today = now()->toDateString();

        $geminiUsed = ApiUsageLog::where('type', 'gemini')
            ->whereDate('created_at', $today)
            ->count();

        $ttsUsed = ApiUsageLog::where('type', 'tts')
            ->whereDate('created_at', $today)
            ->count();

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
            'questions_remaining' => $remaining,
            'reset_at'            => now()->endOfDay()->toIso8601String(),
        ]);
    }

    public function startSession(Request $request)
    {
        $session = InterviewSession::create([
            'title' => 'Session ' . now()->format('Y-m-d H:i:s'),
        ]);

        $reply = $this->callGemini([]);
        ApiUsageLog::create(['type' => 'gemini']);

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

        $reply = $this->callGemini($session->logs()->get());
        ApiUsageLog::create(['type' => 'gemini']);

        $session->logs()->create([
            'role' => 'assistant',
            'content' => $reply
        ]);

        return response()->json([
            'reply' => $reply,
        ]);
    }

    private function callGemini($logs)
    {
        $apiKey = request()->header('X-Gemini-Api-Key') ?? env('GEMINI_API_KEY');
        $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key={$apiKey}";
        
        $resume = InterviewContext::where('type', 'resume')->first()?->content ?? '';
        $qna = InterviewContext::where('type', 'qna')->first()?->content ?? '';
        $jobPosting = InterviewContext::where('type', 'job_posting')->first()?->content ?? '';

        $systemInstruction = "You are a professional technical interviewer for a software engineer role. "
            . "The applicant has provided their resume, a Q&A base, and a job posting for the position they are applying for. "
            . "Ask them interview questions sequentially based on their context, specifically matching their skills to the job posting. "
            . "IMPORTANT: Keep each response to 1-2 sentences MAX. Ask only ONE question at a time. "
            . "Be conversational and natural like a real voice interview. No markdown, no bullet points, no lists. "
            . "If the candidate's answer is good, give a very brief acknowledgment (a few words) then ask your next question. "
            . "Speak in English. "
            . "Job Posting:\n" . $jobPosting . "\n\nResume:\n" . $resume . "\n\nQ&A Base:\n" . $qna;

        $contents = [];
        if (count($logs) === 0) {
            $contents[] = [
                'role' => 'user',
                'parts' => [['text' => 'Hello, I am ready for the interview. Please start by asking my first question.']]
            ];
        } else {
            foreach ($logs as $log) {
                $contents[] = [
                    'role' => $log->role === 'assistant' ? 'model' : 'user',
                    'parts' => [['text' => $log->content]]
                ];
            }
        }

        $payload = [
            'system_instruction' => [
                'parts' => [
                    ['text' => $systemInstruction]
                ]
            ],
            'contents' => $contents,
            'generationConfig' => [
                'maxOutputTokens' => 150,
                'temperature' => 0.8,
            ]
        ];

        $response = Http::timeout(30)->post($url, $payload);

        if ($response->successful()) {
            $json = $response->json();
            return $json['candidates'][0]['content']['parts'][0]['text'] ?? "Failed to generate text.";
        }

        return "API Error: " . $response->body();
    }

    private function callGeminiTTS(string $text): ?string
    {
        $apiKey = request()->header('X-Gemini-Api-Key') ?? env('GEMINI_API_KEY');
        $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-preview-tts:generateContent?key={$apiKey}";

        $payload = [
            'contents' => [
                ['parts' => [['text' => $text]]]
            ],
            'generationConfig' => [
                'responseModalities' => ['AUDIO'],
                'speechConfig' => [
                    'voiceConfig' => [
                        'prebuiltVoiceConfig' => ['voiceName' => 'Aoede']
                    ]
                ]
            ]
        ];

        $response = Http::timeout(30)->post($url, $payload);
        if (!$response->successful()) return null;

        $json = $response->json();
        $rawBase64 = $json['candidates'][0]['content']['parts'][0]['inlineData']['data'] ?? null;
        if (!$rawBase64) return null;

        // PCM -> WAV conversion (24kHz, 16bit, mono)
        $pcmData   = base64_decode($rawBase64);
        $sampleRate = 24000; $numChannels = 1; $bitsPerSample = 16;
        $dataSize = strlen($pcmData);
        $byteRate = $sampleRate * $numChannels * ($bitsPerSample / 8);
        $blockAlign = $numChannels * ($bitsPerSample / 8);
        $wavHeader = pack('A4', 'RIFF') . pack('V', 36 + $dataSize) . pack('A4', 'WAVE')
            . pack('A4', 'fmt ') . pack('V', 16) . pack('v', 1)
            . pack('v', $numChannels) . pack('V', $sampleRate) . pack('V', $byteRate)
            . pack('v', $blockAlign) . pack('v', $bitsPerSample)
            . pack('A4', 'data') . pack('V', $dataSize);

        return base64_encode($wavHeader . $pcmData);
    }

    public function tts(Request $request)
    {
        $request->validate(['text' => 'required|string|max:2000']);

        $audioBase64 = $this->callGeminiTTS($request->text);

        if (!$audioBase64) {
            return response()->json(['error' => 'TTS generation failed'], 500);
        }

        ApiUsageLog::create(['type' => 'tts']);

        return response()->json([
            'audio_base64' => $audioBase64,
        ]);
    }
}
