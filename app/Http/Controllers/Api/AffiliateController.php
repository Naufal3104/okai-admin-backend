<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Affiliates;
use App\Models\AffiliateCommissions;
use App\Models\WithdrawalRequest;
use App\Models\User; // <-- Pastikan model User di-import
use App\Models\Products;

class AffiliateController extends Controller
{
    // ==========================================
    // 1. TERIMA DATA DARI FORMULIR WEB PUBLIK KAMBI
    // ==========================================
    public function storeRequest(Request $request)
    {
        $user = $request->user();

        // Validasi: Cek apakah user sudah pernah mendaftar sebelumnya
        $existing = Affiliates::where('user_id', $user->id)->first();
        if ($existing) {
            return response()->json([
                'success' => false,
                'message' => 'Anda sudah mengajukan program kemitraan sebelumnya.'
            ], 400);
        }

        // Validasi Inputan Form
        $request->validate([
            'whatsapp_number' => 'required|string|max:20',
            'social_platform' => 'required|string|max:50',
            'social_username' => 'required|string|max:255',
            'promotional_plan' => 'required|string'
        ]);

        // Simpan ke Database
        $affiliate = Affiliates::create([
            'user_id' => $user->id,
            'full_name' => $user->name,
            'email' => $user->email,
            'phone' => $request->whatsapp_number,
            'social_platform' => $request->social_platform,
            'social_username' => $request->social_username,
            'promotional_plan' => $request->promotional_plan,
            'status' => 'pending',
            'commission_rate' => 15, // Default komisi, misal 15%
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Pengajuan berhasil dikirim dan sedang menunggu persetujuan.',
            'data' => $affiliate
        ], 201);
    }

    // ==========================================
    // 2. ADMIN: SETUJUI / TOLAK PENDAFTARAN
    // ==========================================
    public function updateAffiliateStatus(Request $request, string $id)
    {
        $affiliate = Affiliates::find($id);

        if (!$affiliate) {
            return response()->json(['success' => false, 'message' => 'Data mitra tidak ditemukan'], 404);
        }

        $request->validate([
            'status' => 'required|in:active,rejected',
        ]);

        $affiliate->status = $request->status;

        // JIKA ADMIN KLIK "TERIMA" (ACTIVE)
        if ($request->status === 'active') {

            // Generate kode unik jika belum ada (Misal: KMB-FAW1234)
            if (empty($affiliate->affiliate_code)) {
                $prefix = strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $affiliate->full_name), 0, 3));
                if (strlen($prefix) < 3)
                    $prefix = 'KMB';
                $affiliate->affiliate_code = 'KMB-' . $prefix . rand(1000, 9999);
            }

            // // Ubah Hak Akses (Role) User di tabel users menjadi 'affiliate'
            // $user = User::find($affiliate->user_id);
            // if ($user) {
            //     $user->role = 'affiliate';
            //     $user->save();
            // }
        }

        $affiliate->save();

        return response()->json([
            'success' => true,
            'message' => 'Status mitra berhasil diperbarui.'
        ]);
    }

    // ==========================================
    // DATA STATISTIK DAN LIST ADMIN LAINNYA
    // ==========================================
    public function getStats()
    {
        return response()->json([
            'success' => true,
            'data' => [
                'total_mitra' => Affiliates::count(),
                'total_referrals' => AffiliateCommissions::count(),
                'pending_commissions' => WithdrawalRequest::where('status', 'pending')->sum('amount'),
                'paid_commissions' => WithdrawalRequest::where('status', 'approved')->sum('amount'),
            ]
        ]);
    }

    public function getWithdrawals()
    {
        $requests = WithdrawalRequest::with('affiliate')
            ->where('status', 'pending')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $requests
        ]);
    }

    public function updateStatus(Request $request, string $id)
    {
        $request->validate([
            'status' => 'required|in:active,rejected'
        ]);

        // UBAH BAGIAN INI: Cari berdasarkan user_id, bukan id tabel affiliate
        $affiliate = Affiliates::where('id', $id)
            ->orWhere('user_id', $id) // Sebagai cadangan jika ID yang dikirim adalah ID User
            ->first();

        if (!$affiliate) {
            return response()->json([
                'success' => false,
                'message' => 'Data tidak ditemukan di tabel affiliates (ID: ' . $id . ')'
            ], 404);
        }

        $affiliate->status = $request->status;

        if ($request->status === 'active' && empty($affiliate->affiliate_code)) {
            $singkatanNama = strtoupper(substr(str_replace(' ', '', $affiliate->full_name), 0, 4));
            $affiliate->affiliate_code = 'OKAI-' . $singkatanNama . rand(10, 99);
        }

        $affiliate->save();

        return response()->json([
            'success' => true,
            'message' => 'Status mitra berhasil diperbarui!'
        ]);
    }

    public function getAffiliateList()
    {
        $affiliates = Affiliates::orderBy('created_at', 'desc')->get();

        return response()->json([
            'success' => true,
            'data' => $affiliates
        ]);
    }

    // ==========================================
    // FUNGSI BARU: CEK STATUS AFFILIATE USER
    // ==========================================
    public function checkUserStatus(Request $request)
    {
        $user = $request->user();

        // Cari data pendaftaran user ini di tabel affiliates
        $affiliate = Affiliates::where('user_id', $user->id)->first();

        // Kalau datanya nggak ada, berarti statusnya null (belum pernah daftar)
        $status = $affiliate ? $affiliate->status : null;

        return response()->json([
            'success' => true,
            'status' => $status,
            'data' => $affiliate // Kita kirim juga data lengkapnya siapa tau butuh nampilin kode referral di frontend
        ]);
    }

    // ==========================================
    // FUNGSI BARU: KATALOG PRODUK AFFILIATE
    // ==========================================
    public function getAvailableProducts()
    {
        // Menarik semua produk yang kolom is_affiliate_enabled nya bernilai true (1)
        // (Note: Bakal error SQL sampai temenmu selesai bikin kolom ini di database)
        $products = Products::where('is_affiliate_enabled', true)->get();

        return response()->json([
            'success' => true,
            'data' => $products
        ]);
    }

    public function show(string $id)
    {
        // 1. Menarik data afiliasi beserta relasinya menggunakan Eager Loading agar efisien
        $affiliate = Affiliates::with([
            'user',              // Mengambil data akun login terkait
            'socialMedia',       // Mengambil dari tabel affiliate_social_media
            'commissions'        // Mengambil dari tabel affiliate_commissions
        ])->find($id);

        if (!$affiliate) {
            return response()->json([
                'success' => false,
                'message' => 'Data profil mitra tidak ditemukan.'
            ], 404);
        }

        // 2. Mengemas dan merapikan data agar mudah dibaca oleh Frontend (React/Next.js)
        $formattedData = [
            'id' => $affiliate->id,
            'personal_info' => [
                'full_name' => $affiliate->full_name,
                // Jika email/phone kosong, coba ambil dari tabel users
                'email' => $affiliate->email ?? ($affiliate->user ? $affiliate->user->email : '-'),
                'phone' => $affiliate->phone ?? '-',
            ],
            'program_details' => [
                'affiliate_code' => $affiliate->affiliate_code ?? 'Belum memiliki kode',
                'commission_rate' => $affiliate->commission_rate . '%',
                'status' => ucfirst($affiliate->status),
                'joined_at' => $affiliate->created_at ? $affiliate->created_at->format('d M Y') : '-',
            ],
            'banking_info' => [
                'bank_name' => $affiliate->bank_name ?? '-',
                'account_number' => $affiliate->account_number ?? '-',
                'account_holder' => $affiliate->account_holder_name ?? '-',
            ],
            // 3. Merangkum data Sosial Media
            'social_media' => $affiliate->socialMedia->map(function ($social) {
                return [
                    'platform' => ucfirst($social->platform),
                    'username' => $social->username,
                    'url' => $social->url,
                    'plan' => $social->promotion_plan
                ];
            }),
            // 4. Kalkulasi otomatis ringkasan komisi
            'commission_summary' => [
                'total_earnings' => $affiliate->commissions->sum('commission_amount'),
                'pending' => $affiliate->commissions->where('status', 'pending')->sum('commission_amount'),
                'paid' => $affiliate->commissions->where('status', 'paid')->sum('commission_amount'),
            ]
        ];

        return response()->json([
            'success' => true,
            'data' => $formattedData
        ], 200);
    }
}