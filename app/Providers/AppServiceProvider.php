<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Config;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('system_settings')) {
                $setting = \App\Models\SystemSetting::first();
                if ($setting) {
                    if ($setting->xendit_api_key) {
                        try {
                            $xenditKey = \Illuminate\Support\Facades\Crypt::decryptString($setting->xendit_api_key);
                            config(['services.xendit.secret_key' => $xenditKey]);
                            // Tetapkan ke env agar env() di controller juga bisa membaca (opsional tapi aman)
                            putenv('XENDIT_SECRET_KEY=' . $xenditKey);
                        } catch (\Exception $e) {}
                    }
                    if ($setting->biteship_api_key) {
                        try {
                            $biteshipKey = \Illuminate\Support\Facades\Crypt::decryptString($setting->biteship_api_key);
                            config(['services.biteship.api_key' => $biteshipKey]);
                        } catch (\Exception $e) {}
                    }
                    if ($setting->binderbyte_api_key) {
                        try {
                            $binderbyteKey = \Illuminate\Support\Facades\Crypt::decryptString($setting->binderbyte_api_key);
                            config(['services.binderbyte.api_key' => $binderbyteKey]);
                        } catch (\Exception $e) {}
                    }
                    if ($setting->google_client_id) {
                        try {
                            $googleKey = \Illuminate\Support\Facades\Crypt::decryptString($setting->google_client_id);
                            config(['services.google.client_id' => $googleKey]);
                        } catch (\Exception $e) {}
                    }
                    if ($setting->google_client_secret) {
                        try {
                            $googleSecret = \Illuminate\Support\Facades\Crypt::decryptString($setting->google_client_secret);
                            config(['services.google.client_secret' => $googleSecret]);
                        } catch (\Exception $e) {}
                    }
                    if ($setting->google_redirect_uri) {
                        // Tidak di enkripsi karena bukan secret key
                        config(['services.google.redirect' => $setting->google_redirect_uri]);
                    }
                }
            }
        } catch (\Exception $e) {
            // Ignore error during migrations
        }

        VerifyEmail::createUrlUsing(function ($notifiable) {
            // Buat tautan URL sementara (signed URL) dari Laravel
            $temporarySignedURL = URL::temporarySignedRoute(
                'verification.verify',
                Carbon::now()->addMinutes(Config::get('auth.verification.expire', 60)),
                [
                    'id' => $notifiable->getKey(),
                    'hash' => sha1($notifiable->getEmailForVerification()),
                ]
            );

            // Pisahkan parameter keamanan dari URL Laravel
            $parsedUrl = parse_url($temporarySignedURL);
            $query = $parsedUrl['query'] ?? '';

            // Gabungkan parameter tersebut ke URL React Anda
            // Ubah port 5173 sesuai dengan port frontend React Anda yang aktif
            return "http://localhost:5173/verify-email/{$notifiable->getKey()}/" . sha1($notifiable->getEmailForVerification()) . "?{$query}";
        });
    }
}
