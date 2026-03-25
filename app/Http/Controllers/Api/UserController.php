<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Auth\Events\Registered;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Auth\Events\Verified;
use Laravel\Socialite\Facades\Socialite;
use Illuminate\Support\Str;

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

    public function logout(Request $request)
    {
        // 1. Mematikan sesi pengguna saat ini di sisi server
        Auth::guard('web')->logout();

        // 2. Menghancurkan seluruh data sesi agar tidak bisa dipulihkan
        $request->session()->invalidate();

        // 3. Membuat token keamanan baru untuk mencegah serangan pembajakan
        $request->session()->regenerateToken();

        // 4. Memberikan konfirmasi ke React bahwa proses pemutusan berhasil
        return response()->json([
            'success' => true,
            'message' => 'Berhasil keluar dengan aman.'
        ], 200);
    }

    public function register(Request $request)
    {
        // 1. Validasi data dari React (password_confirmation diperlukan oleh aturan 'confirmed')
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
        ]);

        // 2. Buat pengguna baru di database
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        // 3. Berikan role 'customer' secara default menggunakan Spatie
        $user->assignRole('customer');

        // 4. Memicu sistem Laravel untuk mengirimkan email verifikasi
        event(new Registered($user));

        // 5. Kembalikan respons sukses ke React
        return response()->json([
            'success' => true,
            'message' => 'Registrasi berhasil. Silakan periksa email Anda untuk verifikasi.',
            'user' => $user
        ], 201);
    }

    public function verifyEmail(Request $request, $id, $hash)
    {
        // 1. Cari pengguna berdasarkan ID
        $user = User::find($id);

        // 2. Validasi apakah user ada dan hash cocok
        if (!$user || sha1($user->getEmailForVerification()) !== $hash) {
            return response()->json([
                'success' => false,
                'message' => 'Tautan verifikasi tidak valid atau sudah rusak.'
            ], 400);
        }

        // 3. Periksa apakah email sudah pernah diverifikasi sebelumnya
        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'success' => true,
                'message' => 'Email ini sudah diverifikasi sebelumnya.'
            ], 200);
        }

        // 4. Sahkan email di database dan picu event Verified
        if ($user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return response()->json([
            'success' => true,
            'message' => 'Email berhasil diverifikasi.'
        ], 200);
    }

    public function getGoogleUrl()
    {
        // Gunakan stateless() karena ini adalah API untuk React
        $url = Socialite::driver('google')->stateless()->redirect()->getTargetUrl();
        return response()->json([
            'url' => $url
        ]);
    }

    // Fungsi 2: Pintu masuk kembalinya pengguna dari Google
    public function handleGoogleCallback()
    {
        try {
            // Tangkap data pengguna dari Google
            $googleUser = Socialite::driver('google')->stateless()->user();

            // Cari pengguna berdasarkan email. Jika tidak ada, buat akun baru.
            $user = User::firstOrCreate(
                ['email' => $googleUser->getEmail()],
                [
                    'name' => $googleUser->getName(),
                    // Beri password acak yang sangat panjang (karena mereka login via Google)
                    'password' => Hash::make(Str::random(24)),
                    // Anggap email sudah terverifikasi karena berasal dari Google
                    'email_verified_at' => now(),
                ]
            );

            // Berikan hak akses 'customer' jika mereka adalah pengguna baru
            if (!$user->hasRole('customer')) {
                $user->assignRole('customer');
            }

            // Masukkan pengguna ke dalam sesi sistem (Login)
            Auth::login($user);

            // Perintahkan peramban (browser) untuk kembali ke halaman Dashboard React
            // Sesuaikan port 5173 dengan port React Anda
            return redirect('http://localhost:5173/dashboard');
        } catch (\Exception $e) {
            // Jika batal atau gagal, kembalikan ke halaman login dengan pesan error
            return redirect('http://localhost:5173/login?error=google_failed');
        }
    }
}
