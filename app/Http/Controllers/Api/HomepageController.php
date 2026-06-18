<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\HomepageSetting;
use Illuminate\Support\Facades\DB;

class HomepageController extends Controller
{
    public function index()
    {
        // 1. Tarik data pengaturan homepage (Hero Section)
        $settings = HomepageSetting::first();
        if (!$settings) {
            $settings = [
                'hero_badge' => '100% Organik & Premium',
                'hero_title_1' => 'Lebih Sehat,',
                'hero_title_2' => 'Lebih Mudah',
                'hero_highlight' => 'Dicerna.',
                'hero_description' => 'Tingkatkan imunitas dan penuhi nutrisi harian keluarga dengan kebaikan murni susu kambing etawa pilihan dari KAMBI.',
                'hero_button_text' => 'Beli Sekarang',
                'hero_button_link' => '/shop',
                'hero_image_url' => null,
                'hero_product_title' => 'KAMBI Etawa Premium',
                'hero_product_subtitle' => 'Box 200g (10 Sachet)'
            ];
        }

        // 2. Tarik data promosi yang sedang aktif
        $promotions = DB::table('promotions')->where('is_active', 1)->get();

        // 3. Tarik data Landing Contents (Edukasi & Affiliate)
        $landingContents = DB::table('landing_contents')->get();

        return response()->json([
            'success' => true,
            'data' => [
                'settings' => $settings,
                'promotions' => $promotions,
                'landing_contents' => $landingContents // 👈 Data baru kita kirim ke React
            ]
        ]);
    }

    public function update(Request $request)
    {
        // Cari data setting pertama, kalau belum ada kita buat instansiasi baru
        $settings = HomepageSetting::first();
        if (!$settings) {
            $settings = new HomepageSetting();
        }

        // Isi semua data dari request kecuali file gambar
        $settings->fill($request->except('image'));

        // Jika ada upload gambar baru, simpan ke storage
        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('homepage', 'public');
            // Simpan URL lengkap gambar ke database
            $settings->hero_image_url = url('storage/' . $path);
        }

        $settings->save();

        return response()->json([
            'success' => true,
            'message' => 'Mantap! Pengaturan Homepage berhasil diperbarui.',
            'data' => $settings
        ]);
    }
}