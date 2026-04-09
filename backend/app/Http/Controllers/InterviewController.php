<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Models\InterviewContext;
use App\Models\InterviewSession;
use App\Models\InterviewSessionLog;
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
            InterviewContext::updateOrCreate(['type' => 'resume'], ['content' => $request->resume]);
        }
        if ($request->has('qna')) {
            InterviewContext::updateOrCreate(['type' => 'qna'], ['content' => $request->qna]);
        }
        if ($request->has('job_posting')) {
            InterviewContext::updateOrCreate(['type' => 'job_posting'], ['content' => $request->job_posting]);
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

            // 텍스트에서 불필요한 연속 공백이나 줄바꿈 조금 정리
            $text = preg_replace('/\n\s*\n/', "\n\n", $text);

            return response()->json(['text' => trim($text)]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to parse PDF: ' . $e->getMessage()], 500);
        }
    }

    public function startSession(Request $request)
    {
        $session = InterviewSession::create([
            'title' => 'Session ' . now()->format('Y-m-d H:i:s'),
        ]);

        $reply = $this->callGemini([]);
        $audioBase64 = $this->callGeminiTTS($reply);

        $session->logs()->create([
            'role' => 'assistant',
            'content' => $reply
        ]);

        return response()->json([
            'session_id' => $session->id,
            'reply' => $reply,
            'audio_base64' => $audioBase64,
        ]);
    }

    public function chat(Request $request, $sessionId)
    {
        $request->validate([
            'message' => 'required|string'
        ]);

        $session = InterviewSession::findOrFail($sessionId);

        // 사용자 답변 저장
        $session->logs()->create([
            'role' => 'user',
            'content' => $request->message
        ]);

        $reply = $this->callGemini($session->logs()->get());
        $audioBase64 = $this->callGeminiTTS($reply);

        // AI 면접관 질문(답변) 저장
        $session->logs()->create([
            'role' => 'assistant',
            'content' => $reply
        ]);

        return response()->json([
            'reply' => $reply,
            'audio_base64' => $audioBase64,
        ]);
    }

    private function callGemini($logs)
    {
        $apiKey = env('GEMINI_API_KEY');
        $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key={$apiKey}";
        
        $resume = InterviewContext::where('type', 'resume')->first()?->content ?? '';
        $qna = InterviewContext::where('type', 'qna')->first()?->content ?? '';
        $jobPosting = InterviewContext::where('type', 'job_posting')->first()?->content ?? '';

        $systemInstruction = "You are a professional technical interviewer for a software engineer role. "
            . "The applicant has provided their resume, a Q&A base, and a job posting for the position they are applying for. "
            . "Ask them interview questions sequentially based on their context, specifically matching their skills to the job posting. "
            . "Keep your responses and questions concise and natural, imitating a real voice conversation. Do not use markdown if possible. "
            . "Speak in Korean. "
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
            'contents' => $contents
        ];

        $response = Http::post($url, $payload);

        if ($response->successful()) {
            $json = $response->json();
            return $json['candidates'][0]['content']['parts'][0]['text'] ?? "Failed to generate text.";
        }

        return "API Error: " . $response->body();
    }

    private function callGeminiTTS(string $text): ?string
    {
        $apiKey = env('GEMINI_API_KEY');
        $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-preview-tts:generateContent?key={$apiKey}";

        $payload = [
            'contents' => [
                ['parts' => [['text' => $text]]]
            ],
            'generationConfig' => [
                'responseModalities' => ['AUDIO'],
                'speechConfig' => [
                    'voiceConfig' => [
                        'prebuiltVoiceConfig' => ['voiceName' => 'Kore']
                    ]
                ]
            ]
        ];

        $response = Http::timeout(30)->post($url, $payload);
        if (!$response->successful()) return null;

        $json = $response->json();
        $rawBase64 = $json['candidates'][0]['content']['parts'][0]['inlineData']['data'] ?? null;
        if (!$rawBase64) return null;

        // PCM -> WAV 변환 (24kHz, 16bit, mono)
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

        $apiKey = env('GEMINI_API_KEY');
        $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-preview-tts:generateContent?key={$apiKey}";

        $payload = [
            'contents' => [
                ['parts' => [['text' => $request->text]]]
            ],
            'generationConfig' => [
                'responseModalities' => ['AUDIO'],
                'speechConfig' => [
                    'voiceConfig' => [
                        'prebuiltVoiceConfig' => [
                            'voiceName' => 'Kore' // 한국어 자연스러운 목소리
                        ]
                    ]
                ]
            ]
        ];

        $response = Http::timeout(30)->post($url, $payload);

        if (!$response->successful()) {
            return response()->json(['error' => $response->body()], 500);
        }

        $json = $response->json();
        $audioBase64 = $json['candidates'][0]['content']['parts'][0]['inlineData']['data'] ?? null;

        if (!$audioBase64) {
            return response()->json(['error' => 'No audio data returned'], 500);
        }

        $pcmData = base64_decode($audioBase64);

        // PCM -> WAV 변환 (24kHz, 16bit, mono)
        $sampleRate = 24000;
        $numChannels = 1;
        $bitsPerSample = 16;
        $dataSize = strlen($pcmData);
        $byteRate = $sampleRate * $numChannels * ($bitsPerSample / 8);
        $blockAlign = $numChannels * ($bitsPerSample / 8);

        $wavHeader = pack('A4', 'RIFF')
            . pack('V', 36 + $dataSize)   // ChunkSize
            . pack('A4', 'WAVE')
            . pack('A4', 'fmt ')
            . pack('V', 16)               // SubChunk1Size (PCM)
            . pack('v', 1)                // AudioFormat (1 = PCM)
            . pack('v', $numChannels)
            . pack('V', $sampleRate)
            . pack('V', $byteRate)
            . pack('v', $blockAlign)
            . pack('v', $bitsPerSample)
            . pack('A4', 'data')
            . pack('V', $dataSize);

        return response($wavHeader . $pcmData)
            ->header('Content-Type', 'audio/wav')
            ->header('Content-Length', 44 + $dataSize);
    }
}
