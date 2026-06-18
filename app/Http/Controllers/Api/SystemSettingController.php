<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\Crypt;

class SystemSettingController extends Controller
{
    public function getSettings(Request $request)
    {
        // Hanya superadmin
        if ($request->user()->email !== 'admin@gmail.com') {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $setting = SystemSetting::firstOrCreate([]);

        return response()->json([
            'success' => true,
            'data' => [
                'brand_name' => $setting->brand_name,
                'brand_logo' => $setting->brand_logo,
                'maintenance_mode' => (bool) $setting->maintenance_mode,
                'biteship_api_key' => $setting->biteship_api_key ? '***' : '',
                'xendit_api_key' => $setting->xendit_api_key ? '***' : '',
                'binderbyte_api_key' => $setting->binderbyte_api_key ? '***' : '',
                'google_client_id' => $setting->google_client_id ? '***' : '',
                'google_client_secret' => $setting->google_client_secret ? '***' : '',
                'google_redirect_uri' => $setting->google_redirect_uri,
            ]
        ]);
    }

    public function updateSettings(Request $request)
    {
        if ($request->user()->email !== 'admin@gmail.com') {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $setting = SystemSetting::firstOrCreate([]);

        if ($request->has('brand_name')) $setting->brand_name = $request->brand_name;
        if ($request->has('brand_logo')) $setting->brand_logo = $request->brand_logo;
        if ($request->has('maintenance_mode')) $setting->maintenance_mode = filter_var($request->maintenance_mode, FILTER_VALIDATE_BOOLEAN);
        
        if ($request->filled('biteship_api_key') && $request->biteship_api_key !== '***') {
            $setting->biteship_api_key = Crypt::encryptString($request->biteship_api_key);
        }
        if ($request->filled('xendit_api_key') && $request->xendit_api_key !== '***') {
            $setting->xendit_api_key = Crypt::encryptString($request->xendit_api_key);
        }
        if ($request->filled('binderbyte_api_key') && $request->binderbyte_api_key !== '***') {
            $setting->binderbyte_api_key = Crypt::encryptString($request->binderbyte_api_key);
        }
        if ($request->filled('google_client_id') && $request->google_client_id !== '***') {
            $setting->google_client_id = Crypt::encryptString($request->google_client_id);
        }
        if ($request->filled('google_client_secret') && $request->google_client_secret !== '***') {
            $setting->google_client_secret = Crypt::encryptString($request->google_client_secret);
        }
        if ($request->has('google_redirect_uri')) {
            $setting->google_redirect_uri = $request->google_redirect_uri;
        }

        $setting->save();

        return response()->json([
            'success' => true,
            'message' => 'Pengaturan sistem berhasil diperbarui.'
        ]);
    }
}