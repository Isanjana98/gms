<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** SRS 15 - Dashboard. */
class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $dashboard) {}

    /**
     * GET /api/dashboard
     *
     * Everything the home screen needs in one call. Split across six
     * endpoints it would be six round trips on every page load, and the
     * cards are meant to be read as one consistent snapshot.
     */
    public function index(Request $request): JsonResponse
    {
        $branchId = $this->branchScope($request);
        $trendDays = min(max($request->integer('trend_days') ?: 30, 7), 90);

        return response()->json([
            'summary' => $this->dashboard->summary($branchId),
            'recent_check_ins' => $this->dashboard->recentCheckIns($branchId, 10),
            'attendance_trend' => $this->dashboard->attendanceTrend($branchId, $trendDays),
            'top_members' => $this->dashboard->topMembers($branchId, 30, 10),
            'expiring_soon' => $this->dashboard->expiringSoon($branchId, limit: 10),
        ]);
    }

    /** GET /api/dashboard/summary - just the counters, for polling. */
    public function summary(Request $request): JsonResponse
    {
        return response()->json($this->dashboard->summary($this->branchScope($request)));
    }
}
