<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DashboardController extends Controller
{
    public function index()
    {
        try {
            // 1. TOP STATS
            $revenue = DB::table('orders')->where('status', 'paid')->sum('total_price') ?? 0;
            
            // Asumsi komisi affiliate = 10% dari total pesanan affiliate yang lunas (Bisa disesuaikan nanti)
            $affiliateRevenue = DB::table('orders')->whereNotNull('affiliate_id')->where('status', 'paid')->sum('total_price') ?? 0;
            $komisiAfiliasi = $affiliateRevenue * 0.10; 

            $totalStock = DB::table('product_warehouses')->sum('stock') ?? 0;
            
            // Pengiriman aktif (Asumsi status pesanan = shipped)
            $activeShipments = DB::table('orders')->where('status', 'shipped')->count();

            // 2. SALES CHART (Grafik 6 Bulan Terakhir)
            $salesData = [];
            for ($i = 5; $i >= 0; $i--) {
                $start = Carbon::now()->subMonths($i)->startOfMonth();
                $end = Carbon::now()->subMonths($i)->endOfMonth();
                $rev = DB::table('orders')
                    ->where('status', 'paid')
                    ->whereBetween('created_at', [$start, $end])
                    ->sum('total_price') ?? 0;
                
                $salesData[] = [
                    'name' => $start->translatedFormat('M'), // Tampil: Jan, Feb, dst
                    'sales' => (int) $rev
                ];
            }

            // 3. RECENT ACTIVITY LOGS (Mengambil 4 Pesanan Terbaru)
            $recentOrders = DB::table('orders')
                ->leftJoin('users', 'orders.user_id', '=', 'users.id')
                ->select('orders.*', 'users.name as user_name')
                ->orderByDesc('orders.created_at')
                ->take(4)
                ->get();

            $activityLogs = [];
            foreach ($recentOrders as $order) {
                $isPaid = $order->status === 'paid';
                $activityLogs[] = [
                    'id' => $order->id,
                    'user' => $order->user_name ?? 'Guest',
                    'action' => $isPaid ? 'Payment Confirmed' : 'New Order Received',
                    'target' => $order->invoice_no ?? 'ORD-' . $order->id,
                    'time' => Carbon::parse($order->created_at)->diffForHumans(),
                    'type' => $isPaid ? 'success' : 'new'
                ];
            }

            // Fallback jika log kosong
            if (count($activityLogs) === 0) {
                $activityLogs[] = [
                    'id' => 999, 'user' => 'System', 'action' => 'System Initialized', 
                    'target' => 'OKAI Store', 'time' => 'Just now', 'type' => 'system'
                ];
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'stats' => [
                        'revenue' => $revenue,
                        'komisi' => $komisiAfiliasi,
                        'stock' => $totalStock,
                        'shipments' => $activeShipments
                    ],
                    'chart' => $salesData,
                    'logs' => $activityLogs
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }
}