<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Affiliates;
use App\Models\AffiliateCommissions;
use App\Models\WithdrawalRequest;
// use App\Models\User;
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
            'status' => 'pending',
            'commission_rate' => 15, // Default komisi, misal 15%
        ]);

        \App\Models\AffiliateSocialMedias::create([
            'affiliate_id' => $affiliate->id,
            'platform' => $request->social_platform,
            'username' => $request->social_username,
            'promotion_plan' => $request->promotional_plan,
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

    public function updateWithdrawalStatus(Request $request,string $id)
    {
        // 1. Validasi input (hanya boleh 'approved' atau 'rejected')
        $request->validate([
            'status' => 'required|in:approved,rejected'
        ]);

        // 2. Cari data penarikan berdasarkan ID
        $withdrawal = WithdrawalRequest::find($id);

        if (!$withdrawal) {
            return response()->json([
                'success' => false, 
                'message' => 'Data permintaan penarikan tidak ditemukan.'
            ], 404);
        }

        // 3. Ubah status dan simpan
        $withdrawal->status = $request->status;
        $withdrawal->save();

        // 💡 Fakta Menarik: Karena di fungsi checkUserStatus kita ngitung saldo pakai 
        // whereIn('status', ['pending', 'approved']), 
        // kalau statusnya kamu ubah jadi 'rejected', saldonya akan otomatis balik (nggak jadi kepotong)!

        return response()->json([
            'success' => true,
            'message' => 'Status penarikan berhasil diubah menjadi ' . $request->status
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
        $affiliate = Affiliates::where('user_id', $user->id)->first();

        // 1. Jika belum terdaftar/belum aktif, return status saja
        if (!$affiliate || $affiliate->status !== 'active') {
            return response()->json([
                'success' => true,
                'data' => [
                    'is_affiliate' => false,
                    'status' => $affiliate ? $affiliate->status : 'not_registered'
                ]
            ]);
        }

        // 2. Kalkulasi saldo (Sama seperti sebelumnya)
        $totalCommission = AffiliateCommissions::where('affiliate_id', $affiliate->id)->sum('commission_amount'); 
        $totalWithdrawn = WithdrawalRequest::where('affiliate_id', $affiliate->id)->whereIn('status', ['pending', 'approved'])->sum('amount');
        $availableBalance = $totalCommission - $totalWithdrawn;

        // 3. AMBIL AKTIVITAS (Logika ini dipindahkan ke sini agar bisa diakses saat 'active')
        $recentWithdrawals = WithdrawalRequest::where('affiliate_id', $affiliate->id)
            ->orderBy('created_at', 'desc')->take(5)->get()
            ->map(fn($w) => [
                'id' => 'w_'.$w->id, 'type' => 'withdrawal', 'title' => 'Penarikan Dana',
                'amount' => $w->amount, 'status' => $w->status, 'date' => $w->created_at
            ]);

        $recentCommissions = AffiliateCommissions::where('affiliate_id', $affiliate->id)
            ->orderBy('created_at', 'desc')->take(5)->get()
            ->map(fn($c) => [
                'id' => 'c_'.$c->id, 'type' => 'commission', 'title' => 'Komisi Masuk',
                'amount' => $c->commission_amount, 'status' => 'paid', 'date' => $c->created_at
            ]);

        $activities = $recentCommissions->concat($recentWithdrawals)
                        ->sortByDesc('date')->take(5)->values();

        // 4. Kirim response
        $affiliate->is_affiliate = true;
        $affiliate->total_commission = (int) $totalCommission;
        $affiliate->available_balance = (int) $availableBalance;
        $affiliate->recent_activities = $activities; // Sekarang sudah pasti ada

        return response()->json([
            'success' => true,
            'data' => $affiliate
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

    public function requestWithdrawal(Request $request)
    {
        // 1. Validasi inputan dari React
        $request->validate([
            'amount' => 'required|numeric|min:50000',
            'bank_name' => 'required|string',
            'account_number' => 'required|string',
        ]);

        $user = $request->user();
        $affiliate = Affiliates::where('user_id', $user->id)->first();

        if (!$affiliate) {
            return response()->json(['success' => false, 'message' => 'Anda bukan affiliate.'], 403);
        }

        // 2. Hitung ulang saldo aslinya untuk mencegah kecurangan (hack nominal di frontend)
        $totalCommission = AffiliateCommissions::where('affiliate_id', $affiliate->id)->sum('commission_amount');
        
        // ⚠️ PENTING: Cek phpMyAdmin kamu, pastikan nama kolom untuk nominal di tabel withdrawal_requests adalah 'amount'. 
        // Jika beda, ubah kata 'amount' di bawah ini sesuai database-mu!
        $totalWithdrawn = WithdrawalRequest::where('affiliate_id', $affiliate->id)
                            ->whereIn('status', ['pending', 'approved'])
                            ->sum('amount');

        $availableBalance = $totalCommission - $totalWithdrawn;

        // 3. Cek apakah saldonya cukup?
        if ($request->amount > $availableBalance) {
            return response()->json([
                'success' => false, 
                'message' => 'Saldo Anda tidak mencukupi untuk penarikan ini.'
            ], 400);
        }

        // 4. Simpan ke database
        // ⚠️ PENTING KEDUA: Pastikan nama kolom 'bank_name' dan 'account_number' juga sudah sesuai 
        // dengan yang ada di tabel withdrawal_requests kamu!
        WithdrawalRequest::create([
            'affiliate_id' => $affiliate->id,
            'amount' => $request->amount,
            'bank_name' => $request->bank_name,
            'account_name' => $affiliate->full_name,
            'account_number' => $request->account_number,
            'status' => 'pending'
        ]);

        return response()->json([
            'success' => true, 
            'message' => 'Penarikan berhasil diajukan'
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