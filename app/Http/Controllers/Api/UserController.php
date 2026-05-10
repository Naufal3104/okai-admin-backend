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
use Illuminate\Support\Facades\Validator;
use App\Models\Affiliates;


class UserController extends Controller
{
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required'
        ]);

        if (Auth::attempt($credentials)) {
            $user = Auth::user();

            // 🚩 TAMBAHAN: Buat Token API menggunakan Laravel Sanctum
            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'success' => true,
                'token' => $token, // <-- Token dikirim ke React
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->getRoleNames()->first() ?? 'customer',
                ]
            ], 200);
        }

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
        // 1. Validasi data
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
        ]);

        // 2. Buat pengguna baru
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        // 3. Berikan role 'customer'
        $user->assignRole('customer');

        // 🚩 TAMBAHAN: Buat Token API agar setelah daftar bisa langsung auto-login
        $token = $user->createToken('auth_token')->plainTextToken;

        // 4. Memicu email verifikasi
        event(new Registered($user));

        // 5. Kembalikan respons beserta Token ke React
        return response()->json([
            'success' => true,
            'message' => 'Registrasi berhasil.',
            'token' => $token, // <-- Token dikirim ke React
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

    public function index(Request $request)
    {
        // 1. Tangkap kata kunci pencarian dari React
        $search = $request->query('search');

        // 2. Tarik data: Hanya ambil user dengan role super_admin atau admin
        // Serta saring berdasarkan nama atau email jika ada kata kunci search
        $users = User::role(['super_admin', 'admin'])
            ->when($search, function ($query, $search) {
                return $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', '%' . $search . '%')
                        ->orWhere('email', 'like', '%' . $search . '%');
                });
            })
            ->orderBy('id', 'desc')
            ->get();

        // 3. Format data untuk kebutuhan Frontend
        $formattedUsers = $users->map(function ($user) {
            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                // Menampilkan nama role pertama dengan format yang rapi (Contoh: Super Admin)
                'role' => ucwords(str_replace('_', ' ', $user->getRoleNames()->first() ?? 'No Role')),
                'status' => 'Active',
                'joined' => $user->created_at ? $user->created_at->format('d M Y') : 'Unknown',
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $formattedUsers
        ], 200);
    }


    public function show($id)
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json(['success' => false, 'message' => 'User tidak ditemukan'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $user
        ], 200);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
            'role' => 'required|string|in:admin,customer,affiliate,superadmin',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        // PAKAI syncRoles, JANGAN assignRole
        $user->syncRoles([$request->role]);

        if ($request->role === 'affiliate') {
            // Buat profil affiliate (seperti logic sebelumnya)
        }

        return response()->json(['success' => true, 'message' => 'Berhasil!']);
    }

    // 2. Fungsi UPDATE (Edit)
    public function update(Request $request, $id)
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json(['success' => false, 'message' => 'User tidak ditemukan'], 404);
        }

        // Proteksi: Superadmin tidak boleh diedit lewat sini
        if ($user->hasRole('superadmin')) {
            return response()->json(['success' => false, 'message' => 'Izin ditolak untuk akun ini.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'email' => 'sometimes|required|string|email|max:255|unique:users,email,' . $id,
            'password' => 'nullable|string|min:8',
            'role' => 'sometimes|required|string|in:admin,customer,affiliate,superadmin',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $validatedData = $validator->validated();

        // Update password jika diisi
        if (!empty($validatedData['password'])) {
            $validatedData['password'] = Hash::make($validatedData['password']);
        } else {
            unset($validatedData['password']);
        }

        $user->update($validatedData);

        // --- LOGIC ROLE & AFFILIATE PROFILE ---
        if (isset($validatedData['role'])) {
            $user->syncRoles([$validatedData['role']]);

            // Jika user berubah jadi affiliate, pastikan data di tabel affiliates ada
            if ($validatedData['role'] === 'affiliate') {
                // Cek apakah profil sudah ada, kalau belum ada baru buat
                Affiliates::firstOrCreate(
                    ['user_id' => $user->id],
                    [
                        'full_name' => $user->name,
                        'email' => $user->email,
                        'affiliate_code' => 'OKAI-' . strtoupper(Str::random(5)),
                        'commission_rate' => 10,
                        'status' => 'active',
                    ]
                );
            }
        }

        return response()->json(['success' => true, 'message' => 'User diperbarui!', 'data' => $user], 200);
    }

    // 3. Fungsi DESTROY (Hapus)
    public function destroy($id)
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json(['success' => false, 'message' => 'User tidak ditemukan'], 404);
        }

        // 🚩 PERBAIKAN 3: Hanya lindungi Super Admin dari penghapusan
        if ($user->hasRole('superadmin')) {
            return response()->json(['success' => false, 'message' => 'Izin ditolak.'], 403);
        }

        $user->delete();
        return response()->json(['success' => true, 'message' => 'User berhasil dihapus!'], 200);
    }
}
