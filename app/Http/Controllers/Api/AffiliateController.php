<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Affiliates;
use App\Models\AffiliateCommissions;
use App\Models\WithdrawalRequest;
use App\Models\Products;
use Illuminate\Support\Facades\DB;

class AffiliateController extends Controller
{
    public function storeRequest(Request $request)
    {
        $user = $request->user();

        $existing = Affiliates::where('user_id', $user->id)->first();
        if ($existing) {
            return response()->json([
                'success' => false,
                'message' => 'Anda sudah mengajukan program kemitraan sebelumnya.'
            ], 400);
        }

        $request->validate([
            'whatsapp_number' => 'required|string|max:20',
            'social_platform' => 'required|string|max:50',
            'social_username' => 'required|string|max:255',
            'promotional_plan' => 'required|string'
        ]);

        $affiliate = Affiliates::create([
            'user_id' => $user->id,
            'full_name' => $user->name,
            'email' => $user->email,
            'phone' => $request->whatsapp_number,
            'status' => 'pending',
            'commission_rate' => 15, 
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

        if ($request->status === 'active') {
            if (empty($affiliate->affiliate_code)) {
                $prefix = strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $affiliate->full_name), 0, 3));
                if (strlen($prefix) < 3)
                    $prefix = 'KMB';
                $affiliate->affiliate_code = 'KMB-' . $prefix . rand(1000, 9999);
            }
        }

        $affiliate->save();

        return response()->json([
            'success' => true,
            'message' => 'Status mitra berhasil diperbarui.'
        ]);
    }

    public function getStats()
    {
        return response()->json([
            'success' => true,
            'data' => [
                'total_mitra' => Affiliates::count(),
                'total_referrals' => AffiliateCommissions::count(),
                'pending_commissions' => WithdrawalRequest::whereIn('status', ['pending', 'approved'])->sum('amount'),
                'paid_commissions' => WithdrawalRequest::where('status', 'paid')->sum('amount'),
            ]
        ]);
    }

    public function getWithdrawals()
    {
        $requests = WithdrawalRequest::with(['affiliate', 'affiliate.user'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $requests
        ]);
    }

    public function updateWithdrawalStatus(Request $request,string $id)
    {
        $request->validate([
            'status' => 'required|in:approved,rejected'
        ]);

        $withdrawal = WithdrawalRequest::find($id);

        if (!$withdrawal) {
            return response()->json([
                'success' => false, 
                'message' => 'Data permintaan penarikan tidak ditemukan.'
            ], 404);
        }

        $withdrawal->status = $request->status;
        $withdrawal->save();

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

        $affiliate = Affiliates::where('id', $id)
            ->orWhere('user_id', $id) 
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
        $affiliates = Affiliates::with('socialMedia')->orderBy('created_at', 'desc')->get();
        
        $data = $affiliates->map(function ($aff) {
            $firstSocial = $aff->socialMedia->first();
            $aff->setAttribute('social_platform', $firstSocial ? $firstSocial->platform : '-');
            $aff->setAttribute('social_username', $firstSocial ? $firstSocial->username : '-');
            $aff->setAttribute('promotional_plan', $firstSocial ? $firstSocial->promotion_plan : '-');
            return $aff;
        });

        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }

    public function checkUserStatus(Request $request)
    {
        $user = $request->user();
        $affiliate = Affiliates::where('user_id', $user->id)->first();

        if (!$affiliate || $affiliate->status !== 'active') {
            return response()->json([
                'success' => true,
                'data' => [
                    'is_affiliate' => false,
                    'status' => $affiliate ? $affiliate->status : 'not_registered'
                ]
            ]);
        }

        $totalCommission = AffiliateCommissions::where('affiliate_id', $affiliate->id)->sum('commission_amount'); 
        $totalWithdrawn = WithdrawalRequest::where('affiliate_id', $affiliate->id)->whereIn('status', ['pending', 'approved', 'paid'])->sum('amount');
        $availableBalance = $totalCommission - $totalWithdrawn;

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

        $sevenDaysAgo = \Carbon\Carbon::today()->subDays(6);
        
        $rawChartData = AffiliateCommissions::select(
                \Illuminate\Support\Facades\DB::raw('DATE(created_at) as date'),
                \Illuminate\Support\Facades\DB::raw('SUM(commission_amount) as total')
            )
            ->where('affiliate_id', $affiliate->id)
            ->where('created_at', '>=', $sevenDaysAgo)
            ->groupBy('date')
            ->orderBy('date', 'asc')
            ->pluck('total', 'date')
            ->toArray();

        $weeklyChartData = [];
        for ($i = 6; $i >= 0; $i--) {
            $dateObj = \Carbon\Carbon::today()->subDays($i);
            $dateString = $dateObj->format('Y-m-d');
            
            $dayName = [
                'Sun' => 'Min', 'Mon' => 'Sen', 'Tue' => 'Sel', 'Wed' => 'Rab', 
                'Thu' => 'Kam', 'Fri' => 'Jum', 'Sat' => 'Sab'
            ][$dateObj->format('D')];

            if ($i == 0) $dayName = 'Hari Ini';

            $weeklyChartData[] = [
                'name' => $dayName,
                'k' => isset($rawChartData[$dateString]) ? (float) $rawChartData[$dateString] : 0
            ];
        }

        // 🔥 AMBIL DATA HIT PER PRODUK DARI TABEL affiliate_products 🔥
        $productClicks = DB::table('affiliate_products')
            ->join('products', 'affiliate_products.product_id', '=', 'products.id')
            ->where('affiliate_products.affiliate_id', $affiliate->id)
            ->where('affiliate_products.clicks', '>', 0) // Hanya tampilkan yang pernah di-klik
            ->select('products.name', 'products.image_url', 'affiliate_products.clicks') // 👈 SEKARANG NARIK GAMBAR JUGA
            ->orderByDesc('affiliate_products.clicks')
            ->get();

        $affiliate->is_affiliate = true;
        $affiliate->total_commission = (int) $totalCommission;
        $affiliate->available_balance = (int) $availableBalance;
        $affiliate->total_clicks = $affiliate->total_clicks ?? 0; 
        $affiliate->recent_activities = $activities; 
        $affiliate->weekly_chart_data = $weeklyChartData; 
        $affiliate->product_clicks = $productClicks; // 👈 KIRIM KE REACT

        return response()->json([
            'success' => true,
            'data' => $affiliate
        ]);
    }

    public function getAvailableProducts()
    {
        $products = Products::where('is_affiliate_enabled', true)->get();

        return response()->json([
            'success' => true,
            'data' => $products
        ]);
    }

    public function requestWithdrawal(Request $request)
    {
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

        $totalCommission = AffiliateCommissions::where('affiliate_id', $affiliate->id)->sum('commission_amount');
        
        $totalWithdrawn = WithdrawalRequest::where('affiliate_id', $affiliate->id)
                            ->whereIn('status', ['pending', 'approved', 'paid'])
                            ->sum('amount');

        $availableBalance = $totalCommission - $totalWithdrawn;

        if ($request->amount > $availableBalance) {
            return response()->json([
                'success' => false, 
                'message' => 'Saldo Anda tidak mencukupi untuk penarikan ini.'
            ], 400);
        }

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
        $affiliate = Affiliates::with([
            'user',              
            'socialMedia',       
            'commissions'        
        ])->find($id);

        if (!$affiliate) {
            return response()->json([
                'success' => false,
                'message' => 'Data profil mitra tidak ditemukan.'
            ], 404);
        }

        $formattedData = [
            'id' => $affiliate->id,
            'personal_info' => [
                'full_name' => $affiliate->full_name,
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
            'social_media' => $affiliate->socialMedia->map(function ($social) {
                return [
                    'platform' => ucfirst($social->platform),
                    'username' => $social->username,
                    'url' => $social->url,
                    'plan' => $social->promotion_plan
                ];
            }),
            'commission_summary' => [
                'total_earnings' => $affiliate->commissions->sum('commission_amount'),
                'pending' => $affiliate->commissions->whereIn('status', ['pending', 'approved'])->sum('commission_amount'),
                'paid' => $affiliate->commissions->where('status', 'paid')->sum('commission_amount'),
            ]
        ];

        return response()->json([
            'success' => true,
            'data' => $formattedData
        ], 200);
    }

    // ==========================================
    // REKAM KLIK LINK AFILIASI (PER PRODUK MENGGUNAKAN affiliate_products)
    // ==========================================
    public function trackClick(Request $request)
    {
        $request->validate([
            'ref' => 'required|string',
            'product_id' => 'required|integer' // 👈 Wajib ada product_id dari frontend
        ]);

        $affiliate = Affiliates::where('affiliate_code', $request->ref)->first();

        if ($affiliate) {
            // Hit global (lama) di tabel affiliates tetap jalan
            $affiliate->increment('total_clicks');
            
            // Hit per produk (baru) disimpan ke affiliate_products
            $clickRecord = DB::table('affiliate_products')
                ->where('affiliate_id', $affiliate->id)
                ->where('product_id', $request->product_id)
                ->first();

            if ($clickRecord) {
                // Jika sudah ada, tinggal tambah +1
                DB::table('affiliate_products')
                    ->where('id', $clickRecord->id)
                    ->increment('clicks');
            } else {
                // Jika produk belum pernah diklik sama sekali, buat baris baru
                DB::table('affiliate_products')->insert([
                    'affiliate_id' => $affiliate->id,
                    'product_id' => $request->product_id,
                    'clicks' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            
            return response()->json(['success' => true, 'message' => 'Klik berhasil direkam']);
        }

        return response()->json(['success' => false], 404);
    }

    public function validateCode(Request $request)
    {
        try {
            $code = $request->code;
            
            if (!$code) {
                return response()->json(['success' => false, 'message' => 'Kode referral wajib diisi.']);
            }

            $affiliate = Affiliates::where('affiliate_code', $code)->where('status', 'active')->first();

            if (!$affiliate) {
                return response()->json(['success' => false, 'message' => 'Kode referral tidak ditemukan atau tidak aktif.']);
            }

            // Cek jika user sedang login dan mencoba pakai kode sendiri
            $user = $request->user('sanctum');
            if ($user && $affiliate->user_id == $user->id) {
                return response()->json(['success' => false, 'message' => 'Anda tidak bisa menggunakan kode referral milik sendiri.']);
            }

            return response()->json([
                'success' => true, 
                'message' => 'Kode referral berhasil diterapkan.',
                'data' => [
                    'affiliate_id' => $affiliate->id,
                    'full_name' => $affiliate->full_name
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false, 
                'message' => 'Kesalahan server: ' . $e->getMessage()
            ], 500);
        }
    }

    public function updateBankInfo(Request $request)
    {
        $request->validate([
            'bank_name' => 'required|string|max:255',
            'account_number' => 'required|string|max:255',
            'account_holder_name' => 'required|string|max:255',
        ]);

        $user = $request->user();
        $affiliate = Affiliates::where('user_id', $user->id)->first();

        if (!$affiliate) {
            return response()->json([
                'success' => false,
                'message' => 'Anda bukan affiliate.'
            ], 403);
        }

        $affiliate->update([
            'bank_name' => $request->bank_name,
            'account_number' => $request->account_number,
            'account_holder_name' => $request->account_holder_name,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Informasi rekening bank berhasil diperbarui.',
            'data' => $affiliate
        ]);
    }

    public function markWithdrawalAsPaid(string $id)
    {
        $withdrawal = WithdrawalRequest::with('affiliate')->find($id);

        if (!$withdrawal) {
            return response()->json([
                'success' => false, 
                'message' => 'Data tidak ditemukan.'
            ], 404);
        }

        if ($withdrawal->status !== 'approved') {
            return response()->json([
                'success' => false, 
                'message' => 'Hanya penarikan berstatus approved yang bisa ditandai paid.'
            ], 400);
        }

        $affiliate = $withdrawal->affiliate;

        if (empty($affiliate->account_number) || empty($affiliate->bank_name) || empty($affiliate->account_holder_name)) {
            return response()->json([
                'success' => false, 
                'message' => 'Data rekening mitra belum lengkap! Harap lengkapi Nama Bank, Nomor Rekening, dan Nama Pemilik Rekening di profil mitra terlebih dahulu.'
            ], 400);
        }

        DB::transaction(function () use ($withdrawal, $affiliate) {
            $withdrawal->status = 'paid';
            $withdrawal->save();

            if (str_contains($withdrawal->admin_note, 'Otomatis dari Pesanan')) {
                $parts = explode(' ', $withdrawal->admin_note);
                $invoiceNo = end($parts);
                $order = \App\Models\Orders::where('invoice_no', $invoiceNo)->first();
                if ($order) {
                    \App\Models\AffiliateCommissions::where('order_id', $order->id)
                        ->where('affiliate_id', $affiliate->id)
                        ->update(['status' => 'paid']);
                }
            } else {
                \App\Models\AffiliateCommissions::where('affiliate_id', $affiliate->id)
                    ->where('status', 'approved')
                    ->update(['status' => 'paid']);
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Status pencairan berhasil diubah menjadi PAID.'
        ]);
    }
}