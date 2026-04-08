# Interview Pro 🎙️

**Interview Pro** is a personalized AI mock interview practice web application built with a Next.js frontend, a Laravel backend, and Google Gemini AI models.

Designed to simulate realistic interviews, the AI uses your provided `Resume` and `Q&A Knowledge Base` to generate context-aware questions. By utilizing the browser's built-in microphone API, you can respond naturally using your voice, allowing the AI to understand your answers and gracefully follow up with the next question.

---

## ✨ Features

- 🔒 **Master Password Gate**: A simple, single-password global authentication system perfect for portfolio showcases without the hassle of complex user registrations.
- 📄 **Dynamic Context Setup**: Register your resume text and prepared Q&As before starting. The AI utilizes this data as core prompt instructions to conduct a highly customized pressure interview.
- 🎙️ **Speech to Text (STT)**: Completely hands-free operation using the browser's native Web Speech API. Answer naturally with your voice by just pressing the microphone toggle.
- 🔊 **Neural TTS (Text to Speech)**: Integration with Google Gemini's latest `gemini-2.5-flash-preview-tts` model on the backend instantly converts AI responses into WAV audio, providing a natural, high-quality interviewer voice without external paid services.
- 🎨 **Premium UI/UX**: Built with vanilla CSS Modules (excluding Tailwind and external UI libraries) to showcase a custom design system featuring glassmorphism, dark mode, smooth pulse animations, and interactive elements.

---

## 🛠️ Tech Stack

### Frontend
- **Framework**: Next.js 15 (App Router)
- **Language**: TypeScript
- **Styling**: Vanilla CSS Modules (Custom Design System)
- **APIs**: Web Speech API (Recognition)
- **Icons**: Lucide-React

### Backend
- **Framework**: Laravel 11.x
- **Database**: SQLite
- **Authentication**: Custom Master Password Middleware (X-Master-Password Header)
- **AI Integration**: Google Gemini API (`gemini-2.5-flash`, `gemini-2.5-flash-preview-tts`)

---

## 🚀 How to Run Locally

This project is decoupled into standalone `frontend` and `backend` directories. Open two separate terminals to run them both concurrently.

### 1. Backend Setup

1. Navigate to the `backend` directory.
2. Setup environment variables (copy `.env.example` to `.env` and add the following required keys):
    ```env
    # backend/.env 
    GEMINI_API_KEY=YOUR_GOOGLE_AI_STUDIO_API_KEY
    APP_MASTER_PASSWORD=password # Set your desired master password here
    ```
3. Run database migrations (only required once):
    ```bash
    php artisan migrate
    ```
4. Start the Laravel development server:
    ```bash
    php artisan serve
    ```
   *The backend API will run on `http://localhost:8000` by default.*

### 2. Frontend Setup

1. Navigate to the `frontend` directory.
2. Install dependencies:
    ```bash
    npm install
    ```
3. (Optional) If your backend is running on a different port, set it in `.env.local`:
    ```env
    NEXT_PUBLIC_API_BASE=http://localhost:8000
    ```
4. Start the Next.js development server:
    ```bash
    npm run dev
    ```
   *The frontend will run on `http://localhost:3000` by default.*

### 3. Usage
Open `http://localhost:3000` in your browser. Enter the master password you configured in your backend `.env` file, allow microphone permissions, and start your mock interview!

---

## 👨‍💻 Developer
- **Developer**: ChrisToronto-dev
- **Contact**: chris.soohwan.lee@gmail.com
