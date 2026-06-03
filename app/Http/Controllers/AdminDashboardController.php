<?php

namespace App\Http\Controllers;

use App\Models\AdminSetting;
use App\Models\Apartment;
use App\Models\IdentityVerification;
use App\Models\Lease;
use App\Models\PaymentOrder;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminDashboardController extends Controller
{
    /**
     * Get real-time stats for dashboard widgets
     */
    public function getRealtimeStats(): JsonResponse
    {
        return $this->successResponse([
            'online_users' => User::where('last_active_at', '>=', now()->subMinutes(5))->count(),
            'pending_tasks' => [
                'verifications' => IdentityVerification::where('overall_status', 'pending')->count(),
                'apartments_pending' => Apartment::where('status', 'pending')->count(),
                'refunds_pending' => PaymentOrder::where('status', 'refund_pending')->count(),
            ],
            'today_stats' => [
                'new_users' => User::whereDate('created_at', today())->count(),
                'new_apartments' => Apartment::whereDate('created_at', today())->count(),
                'revenue' => PaymentOrder::where('status', 'completed')
                    ->whereDate('created_at', today())
                    ->sum('amount'),
                'applications' => Lease::whereDate('created_at', today())->count(),
            ],
        ]);
    }
}
