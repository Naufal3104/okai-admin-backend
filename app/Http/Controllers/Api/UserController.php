<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class UserController extends Controller
{
    public function login(Request $request)
    {
        // Tahap 1: Memeriksa kelengkapan data yang dikirim oleh React
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required'
        ]);

        // Tahap 2: Mencoba mencocokkan data dengan Database
        if (Auth::attempt($credentials)) {
            $user = Auth::user(); // Mengambil data pengguna yang berhasil cocok

            // Tahap 3: Memberikan balasan JSON yang sama persis dengan harapan React
            return response()->json([
                'success' => true,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ]
            ], 200);
        }

        // Tahap 4: Memberikan balasan gagal jika tidak cocok
        return response()->json([
            'success' => false,
            'message' => 'Email atau kata sandi tidak valid.'
        ], 401);
    }
}
