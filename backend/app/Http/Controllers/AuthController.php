<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function verify(Request $request)
    {
        $password = env('APP_MASTER_PASSWORD');

        if ($request->input('password') === $password) {
            return response()->json(['success' => true]);
        }

        return response()->json(['success' => false, 'message' => 'Invalid password'], 401);
    }
}
