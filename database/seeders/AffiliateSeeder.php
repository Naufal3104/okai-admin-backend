<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Affiliates;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\Hash;

class AffiliateSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Pastikan Role 'affiliate' sudah ada di database (Spatie)
        Role::firstOrCreate(['name' => 'customer']);

        // =========================================================
        // SKENARIO 1: AFFILIATOR STATUS "PENDING" (Belum di-ACC)
        // =========================================================
        $pendingUser = User::firstOrCreate(
            ['email' => 'calon.mitra@gmail.com'],
            [
                'name' => 'Joko Pending', // Username di tabel users
                'password' => Hash::make('password123'),
            ]
        );
        
        $pendingUser->assignRole('customer');

        Affiliates::firstOrCreate(
            ['user_id' => $pendingUser->id],
            [
                'full_name' => 'Joko Susanto',
                'phone' => '081233334444',
                'status' => 'pending',
                // affiliate_code dan social_media sengaja dikosongkan karena belum di-ACC
            ]
        );

        // =========================================================
        // SKENARIO 2: AFFILIATOR STATUS "ACTIVE" (Sudah di-ACC)
        // =========================================================
        $activeUser = User::firstOrCreate(
            ['email' => 'mitra.aktif@gmail.com'],
            [
                'name' => 'Siti Active', // Username di tabel users
                'password' => Hash::make('password123'),
            ]
        );

        $activeUser->assignRole('customer');

        Affiliates::firstOrCreate(
            ['user_id' => $activeUser->id],
            [
                'full_name' => 'Siti Aminah',
                'phone' => '089988887777',
                'social_media' => '@sitiaminah_okai',
                'affiliate_code' => 'OKAI-SITI99', // Sudah memiliki kode afiliasi karena aktif
                'commission_rate' => 10.00,
                'status' => 'active',
            ]
        );
    }
}