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
        $password = env('APP_MASTER_PASSWORD');

        if ($request->header('X-Master-Password') !== $password) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
