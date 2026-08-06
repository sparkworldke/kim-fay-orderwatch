<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Sales\SalesPortfolioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalesPortfolioController extends Controller
{
    public function __construct(
        private readonly SalesPortfolioService $portfolio,
    ) {}

    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);
        $mode = (string) $request->input('mode', 'auto');

        $month = $request->input('month');
        $month = is_string($month) && $month !== '' ? $month : null;
        if ($month !== null && ! preg_match('/^\d{4}-\d{2}$/', $month)) {
            return response()->json(['message' => 'month must use YYYY-MM format.'], 422);
        }

        $compare = (string) $request->input('compare', 'mom');

        try {
            return response()->json($this->portfolio->summary($user, $mode, $month, $compare));
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Unable to build portfolio summary: '.$e->getMessage(),
                'code' => 'PORTFOLIO_SUMMARY_FAILED',
            ], 500);
        }
    }

    public function orders(Request $request): JsonResponse
    {
        $user = $request->user();
        $mode = (string) $request->input('mode', 'auto');
        $status = $request->input('status');
        $page = max(1, $request->integer('page', 1));
        $perPage = min(100, max(5, $request->integer('per_page', 25)));

        return response()->json($this->portfolio->orders(
            $user,
            $mode,
            is_string($status) ? $status : null,
            $page,
            $perPage,
        ));
    }

    public function backorders(Request $request): JsonResponse
    {
        $user = $request->user();
        $mode = (string) $request->input('mode', 'auto');
        $page = max(1, $request->integer('page', 1));
        $perPage = min(100, max(5, $request->integer('per_page', 25)));

        return response()->json($this->portfolio->backorders($user, $mode, $page, $perPage));
    }

    public function itemsNotOrdered(Request $request): JsonResponse
    {
        $user = $request->user();
        $mode = (string) $request->input('mode', 'auto');
        $days = min(180, max(30, $request->integer('days', 60)));

        return response()->json($this->portfolio->itemsNotOrdered($user, $mode, $days));
    }
}
