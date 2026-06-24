<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\Schema;

class CheckMaintenanceMode
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (Schema::hasTable('system_settings')) {
            $setting = SystemSetting::first();
            if ($setting && $setting->maintenance_mode) {
                
                // Allow Super Admin to bypass
                $user = $request->user('sanctum');
                if ($user && $user->hasRole('super_admin')) {
                    return $next($request);
                }

                // If path is related to login/auth, allow it so admin can login
                if ($request->is('api/login') || $request->is('api/auth/*')) {
                    return $next($request);
                }

                return response()->json([
                    'success' => false,
                    'message' => 'Sistem sedang dalam perbaikan / maintenance. Silakan kembali lagi nanti.'
                ], 503);
            }
        }

        return $next($request);
    }
}