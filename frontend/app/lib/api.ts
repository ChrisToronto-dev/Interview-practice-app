export const API_BASE = 'http://localhost:8000/api';

const FETCH_TIMEOUT_MS = 35_000; // 35 seconds

export async function fetchApi(endpoint: string, options: RequestInit = {}) {
  const apiKey = localStorage.getItem('groq_api_key') || '';
  const googleTtsKey = localStorage.getItem('google_tts_api_key') || '';

  const headers: Record<string, string> = {
    'Accept': 'application/json',
    'X-Groq-Api-Key': apiKey,
    ...(googleTtsKey ? { 'X-Google-TTS-Key': googleTtsKey } : {}),
    ...((options.headers as Record<string, string>) || {})
  };

  if (!(options.body instanceof FormData) && !headers['Content-Type']) {
    headers['Content-Type'] = 'application/json';
  }

  // Abort controller for timeout
  const controller = new AbortController();
  const timeoutId = setTimeout(() => controller.abort(), FETCH_TIMEOUT_MS);

  try {
    const response = await fetch(`${API_BASE}${endpoint}`, {
      ...options,
      headers,
      signal: controller.signal,
    });

    if (!response.ok) {
      if (response.status === 401) {
        throw new Error('UNAUTHORIZED');
      }
      const errorData = await response.json().catch(() => ({}));
      throw new Error(errorData.message || errorData.error || `Server error (${response.status}). Please try again.`);
    }

    return response.json();
  } catch (err: any) {
    if (err.name === 'AbortError') {
      throw new Error('Request timed out. The AI is taking too long — please try again.');
    }
    if (err.message === 'Failed to fetch') {
      throw new Error('Network error. Please check your connection and make sure the server is running.');
    }
    throw err;
  } finally {
    clearTimeout(timeoutId);
  }
}
