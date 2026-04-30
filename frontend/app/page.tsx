"use client";

import { useState, useEffect, useRef } from 'react';
import { Mic, MicOff, Send, Loader2, Volume2, VolumeX, Download, Settings, X } from 'lucide-react';
import styles from './page.module.css';
import { fetchApi } from './lib/api';

type AppState = 'LOGIN' | 'SETUP' | 'INTERVIEW' | 'SUMMARY';

export default function Home() {
  const [appState, setAppState] = useState<AppState>('LOGIN');
  const [apiKey, setApiKey] = useState('');
  const [googleTtsKey, setGoogleTtsKey] = useState('');
  const [errorMsg, setErrorMsg] = useState('');
  const [loading, setLoading] = useState(false);

  // Key Validity State
  const [groqValid, setGroqValid] = useState(false);
  const [ttsValid, setTtsValid] = useState(false);
  const [showSettings, setShowSettings] = useState(false);

  // Setup state
  const [resume, setResume] = useState('');
  const [qna, setQna] = useState('');
  const [jobPosting, setJobPosting] = useState('');
  const [pdfLoading, setPdfLoading] = useState(false);

  // Interview state
  const [sessionId, setSessionId] = useState<number | null>(null);
  const [messages, setMessages] = useState<{role: string, content: string}[]>([]);
  
  // Summary state
  const [feedback, setFeedback] = useState('');
  const [feedbackLoading, setFeedbackLoading] = useState(false);
  
  // Speech State
  const [isRecording, setIsRecording] = useState(false);
  const [transcript, setTranscript] = useState('');
  const recognitionRef = useRef<any>(null);
  const finalTranscriptRef = useRef<string>(''); // For accumulating finalized text
  const isRecordingRef = useRef(false); // Track latest isRecording for onend handler

  // TTS
  const [isSpeaking, setIsSpeaking] = useState(false);
  const [isMuted, setIsMuted] = useState(false);
  const [isLoadingAudio, setIsLoadingAudio] = useState(false);
  const [ttsError, setTtsError] = useState(false); // TTS failure indicator
  const isMutedRef = useRef(false); // Track latest state with ref
  const audioRef = useRef<HTMLAudioElement | null>(null);

  // Submission state (prevents double-submit)
  const [isSubmitting, setIsSubmitting] = useState(false);

  // Auto-mode: silence detection for auto-submit
  const SILENCE_SECONDS = 3;
  const silenceTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const countdownIntervalRef = useRef<ReturnType<typeof setInterval> | null>(null);
  const [silenceCountdown, setSilenceCountdown] = useState<number | null>(null);

  // Auto-start mic after AI stops speaking
  const [pendingMicStart, setPendingMicStart] = useState(false);

  // Sync refs when state changes
  useEffect(() => { isMutedRef.current = isMuted; }, [isMuted]);
  useEffect(() => { isRecordingRef.current = isRecording; }, [isRecording]);

  // API Usage
  const [usageInfo, setUsageInfo] = useState<{
    questions_remaining: number;
    tts: { used: number; limit: number };
    groq: { used: number; limit: number };
    google_tts: { chars_this_month: number; monthly_limit: number; chars_remaining: number };
  } | null>(null);

  // Auto scroll
  const chatEndRef = useRef<HTMLDivElement>(null);
  useEffect(() => {
    chatEndRef.current?.scrollIntoView({ behavior: 'smooth' });
  }, [messages]);

  // Function to play base64 audio (MP3 or WAV)
  const playAudioBase64 = async (audioBase64: string, mimeType: string = 'audio/mpeg') => {
    if (isMutedRef.current) return;
    // Stop previous audio
    if (audioRef.current) {
      audioRef.current.pause();
      audioRef.current = null;
    }
    setIsSpeaking(true);
    try {
      const byteChars = atob(audioBase64);
      const byteArr = new Uint8Array(byteChars.length);
      for (let i = 0; i < byteChars.length; i++) byteArr[i] = byteChars.charCodeAt(i);
      const blob = new Blob([byteArr], { type: mimeType });
      const url = URL.createObjectURL(blob);
      const audio = new Audio(url);
      audioRef.current = audio;
      audio.onended = () => {
        setIsSpeaking(false);
        URL.revokeObjectURL(url);
        // Signal auto-start mic (handled in useEffect to access latest state)
        if (!isMutedRef.current) setPendingMicStart(true);
      };
      audio.onerror = () => setIsSpeaking(false);
      await audio.play();
    } catch (e) {
      console.error('TTS playback error:', e);
      setIsSpeaking(false);
    }
  };

  // Browser-native TTS fallback (used when Gemini TTS quota is exhausted)
  const speakWithBrowserTTS = (text: string) => {
    if (!('speechSynthesis' in window)) return;
    window.speechSynthesis.cancel(); // Stop any ongoing speech
    const utterance = new SpeechSynthesisUtterance(text);
    utterance.lang = 'en-US';
    utterance.rate = 1.0;
    utterance.pitch = 1.0;
    setIsSpeaking(true);
    utterance.onend = () => {
      setIsSpeaking(false);
      if (!isMutedRef.current) setPendingMicStart(true);
    };
    utterance.onerror = () => setIsSpeaking(false);
    window.speechSynthesis.speak(utterance);
  };

  const fetchAndPlayTTS = async (text: string) => {
    if (isMutedRef.current) return;
    setIsLoadingAudio(true);
    setTtsError(false);
    try {
      const data = await fetchApi('/tts', {
        method: 'POST',
        body: JSON.stringify({ text }),
      });
      if (data.audio_base64) {
        await playAudioBase64(data.audio_base64, data.mime_type ?? 'audio/mpeg');
      }
    } catch (e: any) {
      console.warn('Google TTS failed, using browser TTS fallback:', e.message);
      speakWithBrowserTTS(text); // Seamless fallback — interview continues
    } finally {
      setIsLoadingAudio(false);
    }
  };

  useEffect(() => {
    // Restore cached keys
    const cachedKey = localStorage.getItem('groq_api_key');
    const cachedTtsKey = localStorage.getItem('google_tts_api_key') || '';
    if (cachedTtsKey) setGoogleTtsKey(cachedTtsKey);
    if (cachedKey) {
      setApiKey(cachedKey);
      verifyApiKey(cachedKey, cachedTtsKey, true);
    }
    
    // Init speech
    if (typeof window !== 'undefined') {
      const SpeechRecognition = (window as any).SpeechRecognition || (window as any).webkitSpeechRecognition;
      if (SpeechRecognition) {
        recognitionRef.current = new SpeechRecognition();
        recognitionRef.current.continuous = true;
        recognitionRef.current.interimResults = true;
        recognitionRef.current.lang = 'en-US';

        recognitionRef.current.onresult = (event: any) => {
          let interimTranscript = '';
          for (let i = event.resultIndex; i < event.results.length; i++) {
            const t = event.results[i][0].transcript;
            if (event.results[i].isFinal) {
              // Accumulate finalized text in ref
              finalTranscriptRef.current += t;
            } else {
              // Interim results temporarily
              interimTranscript += t;
            }
          }
          // Total = Finalized + Currently speaking
          const total = finalTranscriptRef.current + interimTranscript;
          setTranscript(total);

          // Reset silence auto-submit timer whenever user speaks
          if (total.trim()) {
            // Clear existing timer
            if (silenceTimerRef.current) clearTimeout(silenceTimerRef.current);
            if (countdownIntervalRef.current) clearInterval(countdownIntervalRef.current);

            // Start countdown display
            let remaining = SILENCE_SECONDS;
            setSilenceCountdown(remaining);
            countdownIntervalRef.current = setInterval(() => {
              remaining -= 1;
              if (remaining <= 0) {
                clearInterval(countdownIntervalRef.current!);
                countdownIntervalRef.current = null;
                setSilenceCountdown(null);
              } else {
                setSilenceCountdown(remaining);
              }
            }, 1000);

            // Auto-submit after silence
            silenceTimerRef.current = setTimeout(() => {
              silenceTimerRef.current = null;
              setSilenceCountdown(null);
              // Use a custom event to trigger submit from outside the stale closure
              window.dispatchEvent(new CustomEvent('auto-submit-answer'));
            }, SILENCE_SECONDS * 1000);
          }
        };

        // Auto-restart when browser stops recognition (happens after ~30-60s silence or network hiccup)
        recognitionRef.current.onend = () => {
          if (isRecordingRef.current) {
            try {
              recognitionRef.current?.start();
            } catch (e) {
              // Ignore "already started" errors
            }
          }
        };

        recognitionRef.current.onerror = (event: any) => {
          const ignoredErrors = ['no-speech', 'audio-capture', 'aborted'];
          if (ignoredErrors.includes(event.error)) {
            // Recoverable — browser's onend will restart if still recording, or we manually aborted it
            return;
          }
          if (event.error === 'network') {
            // Transient network error — let onend handle restart
            console.warn('Speech recognition network error, will retry...');
            return;
          }
          // Non-recoverable errors (e.g., 'not-allowed', 'service-not-allowed')
          console.error('Speech recognition error:', event.error);
          if (event.error === 'not-allowed') {
            alert('Microphone access was denied. Please allow microphone access in your browser settings (click the lock icon in the URL bar) and try again.');
          }
          setIsRecording(false);
          isRecordingRef.current = false;
        };
      }
    }
  }, []);

  const fetchUsage = async () => {
    try {
      const data = await fetchApi('/usage');
      setUsageInfo(data);
    } catch (e) {
      console.error('Failed to fetch usage:', e);
    }
  };

  const verifyApiKey = async (key: string, ttsKey: string = '', silent = false) => {
    if(!silent) setLoading(true);
    try {
      localStorage.setItem('groq_api_key', key);
      // Save Google TTS key (even if empty — clears old value)
      if (ttsKey) {
        localStorage.setItem('google_tts_api_key', ttsKey);
      } else {
        localStorage.removeItem('google_tts_api_key');
      }
      const res = await fetchApi('/auth/verify', {
        method: 'POST',
        body: JSON.stringify({ api_key: key, tts_key: ttsKey })
      });
      setGroqValid(res.groq_valid);
      setTtsValid(res.tts_valid);

      if (appState === 'LOGIN') {
        setAppState('SETUP');
        loadContexts();
      }
      setShowSettings(false);
      if(!silent) setErrorMsg('');
    } catch (e: any) {
      if(!silent) setErrorMsg('Invalid Groq API Key');
      localStorage.removeItem('groq_api_key');
      setGroqValid(false);
    } finally {
      if(!silent) setLoading(false);
    }
  };

  const loadContexts = async () => {
    try {
      const data = await fetchApi('/contexts');
      const r = data.find((d: any) => d.type === 'resume');
      const q = data.find((d: any) => d.type === 'qna');
      const jp = data.find((d: any) => d.type === 'job_posting');
      if (r) setResume(r.content);
      if (q) setQna(q.content);
      if (jp) setJobPosting(jp.content);
    } catch (e) {
      console.error(e);
    }
  };

  const handlePdfUpload = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;

    setPdfLoading(true);
    const formData = new FormData();
    formData.append('pdf', file);

    try {
      const data = await fetchApi('/contexts/extract-pdf', {
        method: 'POST',
        body: formData,
      });
      setResume(data.text);
    } catch (err: any) {
      alert("PDF Extraction Failed: " + err.message);
    } finally {
      setPdfLoading(false);
      // Reset input
      if (e.target) e.target.value = '';
    }
  };

  // Auto-speak first question after session starts
  const saveContextsAndStart = async () => {
    setLoading(true);
    try {
      await fetchApi('/contexts', {
        method: 'POST',
        body: JSON.stringify({ resume, qna, job_posting: jobPosting })
      });
      const data = await fetchApi('/interviews', { method: 'POST' });
      setSessionId(data.session_id);
      setMessages([{ role: 'assistant', content: data.reply }]);
      setAppState('INTERVIEW');
      fetchUsage();
      // Play TTS after text is displayed (immediately if audio_base64 exists, otherwise request separately)
      if (data.audio_base64) {
        playAudioBase64(data.audio_base64);
      } else {
        fetchAndPlayTTS(data.reply);
      }
    } catch(e: any) {
      alert("Error: " + e.message);
    } finally {
      setLoading(false);
    }
  };

  const clearSilenceTimer = () => {
    if (silenceTimerRef.current) { clearTimeout(silenceTimerRef.current); silenceTimerRef.current = null; }
    if (countdownIntervalRef.current) { clearInterval(countdownIntervalRef.current); countdownIntervalRef.current = null; }
    setSilenceCountdown(null);
  };

  const startRecording = async () => {
    clearSilenceTimer();
    setTranscript('');
    finalTranscriptRef.current = '';
    
    // Explicitly request microphone permission to trigger browser popup
    try {
      const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
      // Stop the explicit stream immediately so SpeechRecognition can take over
      stream.getTracks().forEach(track => track.stop());
    } catch (err) {
      console.error('Microphone permission denied explicitly:', err);
      alert('Microphone access was denied. Please allow microphone access in your browser settings (click the lock icon in the URL bar) and try again.');
      return;
    }

    setIsRecording(true);
    isRecordingRef.current = true;
    try {
      recognitionRef.current?.start();
    } catch (e) {
      // Already started — ignore
    }
  };

  const stopRecording = () => {
    clearSilenceTimer();
    setIsRecording(false);
    isRecordingRef.current = false;
    recognitionRef.current?.stop();
  };

  // Listen for auto-submit event (fired from onresult closure to avoid stale state)
  useEffect(() => {
    const handler = () => submitAnswer();
    window.addEventListener('auto-submit-answer', handler);
    return () => window.removeEventListener('auto-submit-answer', handler);
  });

  // Auto-start mic when AI finishes speaking
  useEffect(() => {
    if (pendingMicStart && !isSpeaking && !isSubmitting && !loading) {
      setPendingMicStart(false);
      startRecording();
    }
  }, [pendingMicStart, isSpeaking, isSubmitting, loading]);

  const submitAnswer = async () => {
    if (!transcript.trim() || !sessionId || isSubmitting) return;
    
    clearSilenceTimer();
    // Stop AI voice playback
    if (audioRef.current) { audioRef.current.pause(); audioRef.current = null; }
    setIsSpeaking(false);
    stopRecording();
    const userMessage = transcript;
    setTranscript('');
    finalTranscriptRef.current = '';
    setMessages(prev => [...prev, { role: 'user', content: userMessage }]);
    setLoading(true);
    setIsSubmitting(true);
    setTtsError(false);

    try {
      // Step 1: Quickly receive text only and display immediately
      const data = await fetchApi(`/interviews/${sessionId}/chat`, {
        method: 'POST',
        body: JSON.stringify({ message: userMessage })
      });
      setMessages(prev => [...prev, { role: 'assistant', content: data.reply }]);
      setLoading(false); // Disable loading immediately upon text display
      fetchUsage();

      // Step 2: Request TTS asynchronously (screen already updated)
      if (data.audio_base64) {
        playAudioBase64(data.audio_base64);
      } else {
        fetchAndPlayTTS(data.reply);
      }
    } catch(e: any) {
      alert("Error: " + e.message);
      setLoading(false);
    } finally {
      setIsSubmitting(false);
    }
  };

  const downloadScript = () => {
    if (messages.length === 0) return;
    
    let textContent = "Mock Interview Script\n=====================\n\n";
    messages.forEach(msg => {
      const role = msg.role === 'assistant' ? 'AI Interviewer' : 'My Answer';
      textContent += `[${role}]\n${msg.content}\n\n`;
    });

    const blob = new Blob([textContent], { type: 'text/plain;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `interview_script_${new Date().toISOString().slice(0,10)}.txt`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
  };

  const fetchFeedback = async () => {
    if (!sessionId) return;
    setFeedbackLoading(true);
    setFeedback('');
    try {
      const data = await fetchApi(`/interviews/${sessionId}/feedback`, {
        method: 'POST'
      });
      setFeedback(data.feedback);
    } catch (e: any) {
      setFeedback('Error: ' + e.message);
    } finally {
      setFeedbackLoading(false);
    }
  };

  if (appState === 'LOGIN') {
    return (
      <main className={styles.container}>
        <div className={styles.authBox}>
          <h1 className={styles.header}>Interview Pro</h1>
          <p className={styles.subtitle}>Enter your API keys to access</p>

          <div className={styles.inputGroup}>
            <label style={{ fontSize: '0.85rem', opacity: 0.7, marginBottom: '0.4rem', display: 'block' }}>
              Groq API Key <span style={{ color: 'var(--error-color)' }}>*</span>
            </label>
            <input
              type="password"
              placeholder="gsk_..."
              value={apiKey}
              onChange={e => setApiKey(e.target.value)}
              onKeyDown={e => e.key === 'Enter' && verifyApiKey(apiKey, googleTtsKey)}
            />
          </div>

          <div className={styles.inputGroup}>
            <label style={{ fontSize: '0.85rem', opacity: 0.7, marginBottom: '0.4rem', display: 'block' }}>
              Google TTS API Key <span style={{ opacity: 0.5, fontSize: '0.8rem' }}>(optional — for AI voice)</span>
            </label>
            <input
              type="password"
              placeholder="AIza..."
              value={googleTtsKey}
              onChange={e => setGoogleTtsKey(e.target.value)}
              onKeyDown={e => e.key === 'Enter' && verifyApiKey(apiKey, googleTtsKey)}
            />
            <p style={{ fontSize: '0.75rem', opacity: 0.45, marginTop: '0.4rem' }}>
              Without this key, browser built-in voice will be used instead.
            </p>
          </div>

          {errorMsg && <p style={{color: 'var(--error-color)', fontSize: '0.9rem'}}>{errorMsg}</p>}
          <button className={styles.btnPrimary} onClick={() => verifyApiKey(apiKey, googleTtsKey)} disabled={loading}>
            {loading ? <Loader2 className="animate-spin" /> : 'Start'}
          </button>
        </div>
      </main>
    );
  }

  const renderApiKeyWidget = () => (
    <div className={styles.apiKeyStatusWidget} onClick={() => setShowSettings(true)}>
      <div className={styles.statusItem}>
        <span className={`${styles.statusDot} ${groqValid ? styles.valid : styles.invalid}`}></span>
        Groq
      </div>
      <div className={styles.statusItem}>
        <span className={`${styles.statusDot} ${ttsValid ? styles.valid : styles.invalid}`}></span>
        TTS
      </div>
      <Settings size={14} style={{ marginLeft: '4px' }} />
    </div>
  );

  const renderSettingsModal = () => {
    if (!showSettings) return null;
    return (
      <div className={styles.modalOverlay}>
        <div className={styles.modalContent}>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
            <h2 className={styles.header} style={{ fontSize: '1.4rem', margin: 0 }}>API Settings</h2>
            <button onClick={() => setShowSettings(false)} style={{ background: 'none', border: 'none', color: 'var(--text-muted)', cursor: 'pointer' }}>
              <X size={24} />
            </button>
          </div>
          
          <div className={styles.inputGroup}>
            <label>Groq API Key</label>
            <input type="password" value={apiKey} onChange={e => setApiKey(e.target.value)} />
          </div>
          
          <div className={styles.inputGroup}>
            <label>Google TTS API Key (Optional)</label>
            <input type="password" value={googleTtsKey} onChange={e => setGoogleTtsKey(e.target.value)} />
          </div>

          {errorMsg && <p style={{color: 'var(--error-color)', fontSize: '0.9rem'}}>{errorMsg}</p>}
          
          <button className={styles.btnPrimary} onClick={() => verifyApiKey(apiKey, googleTtsKey)} disabled={loading}>
            {loading ? <Loader2 className="animate-spin" /> : 'Save & Verify'}
          </button>
        </div>
      </div>
    );
  };

  if (appState === 'SETUP') {
    return (
      <main className={styles.container}>
        {renderApiKeyWidget()}
        {renderSettingsModal()}
        <div className={styles.setupBox}>
          <h1 className={styles.header}>Context Setup</h1>
          <p className={styles.subtitle}>Upload your resume and expected Q&A to generate tailored interview questions from AI</p>
          
          <div className={styles.inputGroup}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '0.5rem' }}>
              <label style={{ marginBottom: 0 }}>Resume</label>
              <div>
                <input 
                  type="file" 
                  accept="application/pdf"
                  onChange={handlePdfUpload}
                  style={{ display: 'none' }}
                  id="pdf-upload"
                  disabled={pdfLoading}
                />
                <label htmlFor="pdf-upload" className={styles.btnSecondary} style={{ padding: '0.4rem 0.8rem', fontSize: '0.8rem', cursor: 'pointer', display: 'inline-flex', alignItems: 'center', gap: '4px', margin: 0 }}>
                  {pdfLoading ? <Loader2 size={14} className="animate-spin" /> : 'Upload PDF'}
                </label>
              </div>
            </div>
            <textarea 
              value={resume}
              onChange={e => setResume(e.target.value)}
              placeholder="Enter your resume text or work experience... Uploading a PDF will automatically fill this in."
            />
          </div>

          <div className={styles.inputGroup}>
            <label>Job Posting</label>
            <textarea 
              value={jobPosting}
              onChange={e => setJobPosting(e.target.value)}
              placeholder="Enter the job description of the position you're applying for..."
            />
          </div>

          <div className={styles.inputGroup}>
            <label>Expected Questions / Q&A Base</label>
            <textarea 
              value={qna}
              onChange={e => setQna(e.target.value)}
              placeholder="Enter the prepared questions and model answers..."
            />
          </div>

          <button className={styles.btnPrimary} onClick={saveContextsAndStart} disabled={loading}>
            {loading ? 'Starting Session...' : 'Start Interview'}
          </button>
        </div>
      </main>
    );
  }

  if (appState === 'SUMMARY') {
    return (
      <main className={styles.container}>
        {renderApiKeyWidget()}
        {renderSettingsModal()}
        <div className={styles.summaryBox}>
          <h1 className={styles.header}>Session Summary</h1>
          <p className={styles.subtitle}>Review your mock interview transcript or get AI feedback</p>
          
          <div className={styles.summaryControls}>
            <button className={styles.btnSecondary} onClick={downloadScript} style={{ display: 'flex', alignItems: 'center', gap: '6px' }}>
              <Download size={16} /> Save Script
            </button>
            <button className={styles.btnPrimary} onClick={fetchFeedback} disabled={feedbackLoading}>
              {feedbackLoading ? <><Loader2 className="animate-spin" size={16} style={{display:'inline', marginRight:'6px'}}/> Analyzing...</> : 'Get AI Feedback'}
            </button>
            <button className={styles.btnSecondary} onClick={() => setAppState('SETUP')}>
              Start New Session
            </button>
          </div>

          <div style={{ display: 'flex', gap: '1rem', flex: 1, overflow: 'hidden' }}>
            <div className={styles.chatArea} style={{ flex: 1, padding: '1rem', background: 'var(--surface-lighter)', borderRadius: '8px', border: '1px solid var(--border-color)' }}>
              <h3 style={{ fontSize: '1rem', marginBottom: '1rem', color: 'var(--text-primary)' }}>Transcript</h3>
              {messages.map((msg, i) => (
                <div key={i} className={`${styles.message} ${msg.role === 'assistant' ? styles.messageAssistant : styles.messageUser}`} style={{ maxWidth: '95%' }}>
                  <strong style={{opacity: 0.8}}>{msg.role === 'assistant' ? '🤖 AI Interviewer: ' : '👤 My Answer: '}</strong>
                  <br/>
                  <span style={{marginTop: '0.5rem', display: 'block'}}>{msg.content}</span>
                </div>
              ))}
            </div>
            
            {feedback && (
              <div className={styles.feedbackArea}>
                <h3 style={{ fontSize: '1rem', marginBottom: '1rem', color: 'var(--accent-primary)' }}>AI Feedback</h3>
                {feedback}
              </div>
            )}
          </div>
        </div>
      </main>
    );
  }

  return (
    <main className={styles.container}>
      {renderApiKeyWidget()}
      {renderSettingsModal()}
      <div className={styles.interviewBox}>
        <div style={{display: 'flex', justifyContent: 'space-between', alignItems: 'center'}}>
          <h1 className={styles.header} style={{fontSize: '1.4rem'}}>Mock Interview</h1>
          <div style={{display: 'flex', gap: '8px', alignItems: 'center'}}>
            {/* API Usage Badge */}
            {usageInfo !== null && (
              <div className={`${styles.usageBadge} ${
                usageInfo.questions_remaining > 20
                  ? styles.usageBadgeGood
                  : usageInfo.questions_remaining > 5
                  ? styles.usageBadgeWarn
                  : styles.usageBadgeCritical
              }`}>
                <span className={styles.usageBadgeDot} />
                <span>
                  {usageInfo.questions_remaining} / {usageInfo.tts.limit} questions left today
                </span>
              </div>
            )}
            {/* Google TTS character usage badge — only shown when Google TTS key is active */}
            {usageInfo?.google_tts && localStorage.getItem('google_tts_api_key') && (
              <div className={`${styles.usageBadge} ${
                usageInfo.google_tts.chars_remaining > 100_000
                  ? styles.usageBadgeGood
                  : usageInfo.google_tts.chars_remaining > 10_000
                  ? styles.usageBadgeWarn
                  : styles.usageBadgeCritical
              }`} title={`Google TTS: ${usageInfo.google_tts.chars_this_month.toLocaleString()} / ${usageInfo.google_tts.monthly_limit.toLocaleString()} chars used this month`}>
                <span className={styles.usageBadgeDot} />
                <span>🔊 {usageInfo.google_tts.chars_this_month.toLocaleString()} / 1M chars</span>
              </div>
            )}
            <button 
              className={styles.btnSecondary} 
              onClick={downloadScript} 
              title="Download Script" 
              disabled={messages.length === 0}
              style={{ display: 'flex', alignItems: 'center', gap: '6px', padding: '0.4rem 0.8rem' }}
            >
              <Download size={16} /> <span>Save Script</span>
            </button>
            <button className={styles.btnSecondary} onClick={() => {
              if (audioRef.current) { audioRef.current.pause(); audioRef.current = null; }
              setIsSpeaking(false);
              stopRecording();
              setAppState('SUMMARY');
            }}>End Session</button>
          </div>
        </div>
        
        <div className={styles.chatArea}>
          {messages.map((msg, i) => (
            <div key={i} className={`${styles.message} ${msg.role === 'assistant' ? styles.messageAssistant : styles.messageUser}`}>
              <strong style={{opacity: 0.8}}>{msg.role === 'assistant' ? '🤖 AI Interviewer: ' : '👤 My Answer: '}</strong>
              <br/>
              <span style={{marginTop: '0.5rem', display: 'block'}}>{msg.content}</span>
            </div>
          ))}
          {loading && (
            <div className={`${styles.message} ${styles.messageAssistant}`}>
              <Loader2 className="animate-spin" size={20} />
            </div>
          )}
          <div ref={chatEndRef} />
        </div>

        <div className={styles.transcriptPreview}>
          {transcript
            ? (
              <>
                <span>{transcript}</span>
                {silenceCountdown !== null && (
                  <span style={{
                    display: 'block',
                    marginTop: '0.5rem',
                    fontSize: '0.78rem',
                    color: 'var(--accent-primary)',
                    opacity: 0.85,
                    fontStyle: 'italic'
                  }}>
                    ⏱ Auto-sending in {silenceCountdown}s... (click 🎤 to cancel)
                  </span>
                )}
              </>
            )
            : (
              <span style={{opacity: 0.5}}>
                {isRecording
                  ? '🎙 Listening... speak your answer'
                  : isSpeaking || isLoadingAudio
                  ? '🤖 AI is speaking — mic opens automatically after'
                  : 'Mic opens automatically after AI speaks'}
              </span>
            )
          }
        </div>

        <div className={styles.controls}>
          {/* Mute toggle */}
          <button
            className={styles.micBtn}
            onClick={() => {
              const next = !isMuted;
              setIsMuted(next);
              if (next && audioRef.current) {
                audioRef.current.pause();
                audioRef.current = null;
                setIsSpeaking(false);
              }
            }}
            title={isMuted ? 'Unmute' : 'Mute AI Voice'}
            style={{ borderColor: isMuted ? 'var(--error-color)' : undefined, color: isMuted ? 'var(--error-color)' : undefined }}
          >
            {isMuted ? <VolumeX size={24} /> : <Volume2 size={24} />}
          </button>

          <button 
            className={`${styles.micBtn} ${isRecording ? styles.recording : ''}`}
            onClick={isRecording ? stopRecording : startRecording}
            disabled={loading || isSpeaking || isSubmitting}
          >
            {isRecording ? <MicOff size={28} /> : <Mic size={28} />}
          </button>
          
          <button 
            className={styles.btnPrimary} 
            style={{padding: '16px', borderRadius: '50%', display: 'flex'}}
            onClick={submitAnswer}
            disabled={!transcript || loading || isSubmitting}
          >
            <Send size={24} />
          </button>
        </div>

        {/* AI Voice Indicator */}
        {isLoadingAudio && !isSpeaking && (
          <p style={{ textAlign: 'center', fontSize: '0.85rem', color: 'var(--text-muted)', paddingBottom: '0.5rem' }}>
            <Loader2 className="animate-spin" size={14} style={{ display: 'inline-block', verticalAlign: 'middle', marginRight: '6px' }} />
            Preparing voice...
          </p>
        )}
        {isSpeaking && (
          <p style={{ textAlign: 'center', fontSize: '0.85rem', color: 'var(--accent-primary)', paddingBottom: '0.5rem' }}>
            🔊 AI Interviewer is speaking...
          </p>
        )}
        {ttsError && !isLoadingAudio && !isSpeaking && (
          <p style={{ textAlign: 'center', fontSize: '0.8rem', color: 'var(--text-muted)', paddingBottom: '0.5rem', opacity: 0.7 }}>
            ⚠️ Voice unavailable — text-only mode
          </p>
        )}
      </div>
    </main>
  );
}
