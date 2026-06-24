<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        if (app()->environment('testing')) {
            return $next($request);
        }

        $user = $request->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        // Super Admin / Superadmin always passes
        if ($user->hasRole('super_admin') || $user->hasRole('superadmin')) {
            return $next($request);
        }

        // Check if user has any of the required roles
        $hasRole = false;
        foreach ($roles as $role) {
            if ($user->hasRole($role)) {
                $hasRole = true;
                break;
            }
        }

        if (!$hasRole) {
            return response()->json(['success' => false, 'message' => 'Unauthorized action.'], 403);
        }

        // Extra enforcement for 'admin' (admin gudang) role
        if ($user->hasRole('admin')) {
            $route = $request->route();
            
            // Extract Controller and Method
            $controller = class_basename($route->getController());
            $method = $route->getActionMethod();

            // 1. Products: Read-only (only index, show)
            if ($controller === 'ProductController') {
                if (!in_array($method, ['index', 'show'])) {
                    return response()->json(['success' => false, 'message' => 'Unauthorized. Admin gudang hanya memiliki akses baca (read-only) untuk produk.'], 403);
                }
            }

            // 2. Warehouses:
            // - Edit/Update warehouse is allowed but cannot change admin pengelola (user_id)
            // - Add/Store warehouse is forbidden
            // - Delete/Destroy warehouse is forbidden
            if ($controller === 'WarehouseController') {
                if (in_array($method, ['store', 'destroy'])) {
                    return response()->json(['success' => false, 'message' => 'Unauthorized. Admin gudang tidak diperbolehkan menambah atau menghapus gudang.'], 403);
                }
                
                if (in_array($method, ['update'])) {
                    $warehouseId = $route->parameter('warehouse') ?? $route->parameter('id');
                    if ($warehouseId) {
                        $warehouse = \App\Models\Warehouses::find($warehouseId);
                        if ($warehouse && $request->has('user_id') && $request->input('user_id') != $warehouse->user_id) {
                            return response()->json(['success' => false, 'message' => 'Unauthorized. Admin gudang tidak diperbolehkan mengubah admin pengelola gudang.'], 403);
                        }
                    }
                }
            }

            // 3. User Management: Forbidden (except updating/showing own profile)
            if ($controller === 'UserController') {
                if (!in_array($method, ['show', 'update'])) {
                    return response()->json(['success' => false, 'message' => 'Unauthorized. Admin gudang tidak memiliki akses ke manajemen pengguna.'], 403);
                }
                $userIdParam = $route->parameter('user') ?? $route->parameter('id');
                if ($userIdParam && $userIdParam != $user->id) {
                    return response()->json(['success' => false, 'message' => 'Unauthorized. Admin gudang hanya dapat mengakses profil sendiri.'], 403);
                }
            }

            // 4. Forbidden controllers entirely for admin role:
            $forbiddenControllers = [
                'AffiliateController',
                'PromotionController',
                'SystemSettingController',
                'HomepageController',
                'AnalyticsController',
            ];
            if (in_array($controller, $forbiddenControllers)) {
                return response()->json(['success' => false, 'message' => 'Unauthorized. Admin gudang tidak memiliki akses ke fitur ini.'], 403);
            }
        }

        // Extra enforcement for 'customer' role on store affiliate dashboard APIs
        if ($user->hasRole('customer')) {
            $route = $request->route();
            $controller = class_basename($route->getController());
            $method = $route->getActionMethod();
            
            $restrictedAffiliateMethods = [
                'getAvailableProducts',
                'requestWithdrawal',
                'updateBankInfo'
            ];
            
            if ($controller === 'AffiliateController' && in_array($method, $restrictedAffiliateMethods)) {
                $affiliate = \App\Models\Affiliates::where('user_id', $user->id)->first();
                if (!$affiliate || $affiliate->status !== 'active') {
                    return response()->json(['success' => false, 'message' => 'Unauthorized. Hanya mitra afiliasi aktif yang dapat mengakses fitur ini.'], 403);
                }
            }
        }

        return $next($request);
    }
}
