<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MasterPasswordMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $apiKey = $request->header('X-Gemini-Api-Key');

        if (!$apiKey || !str_starts_with($apiKey, 'AIza')) {
            return response()->json(['message' => 'Unauthorized: Invalid or missing Gemini API Key'], 401);
        }

        return $next($request);
    }
}
