<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\TrafficAnalyticsRequest;
use App\Services\Monitoring\TrafficAnalyticsPeriod;
use App\Services\Monitoring\TrafficAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class TrafficAnalyticsController extends Controller
{
    public function __construct(private readonly TrafficAnalyticsService $analytics) {}

    public function overview(TrafficAnalyticsRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->analytics->overview(
            $request->user()->tenant_id,
            $this->period($request),
            $request->filters(),
        )]);
    }

    public function rankings(TrafficAnalyticsRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->analytics->rankSubscribers(
            $request->user()->tenant_id,
            $this->period($request),
            $request->validated('mode'),
            $request->validated('metric'),
            (int) $request->validated('top'),
            $request->filters(),
        )]);
    }

    public function subscriberHistory(TrafficAnalyticsRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->analytics->subscriberHistory(
            $request->user()->tenant_id,
            $this->period($request),
            $request->validated('mode'),
            $request->validated('identity'),
            $request->filters(),
        )]);
    }

    public function interfaceHistory(TrafficAnalyticsRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->analytics->interfaceHistory(
            $request->user()->tenant_id,
            $this->period($request),
            $request->filters(),
        )]);
    }

    public function peakHours(TrafficAnalyticsRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->analytics->peakHours(
            $request->user()->tenant_id,
            $this->period($request),
            $request->filters(),
        )]);
    }

    private function period(TrafficAnalyticsRequest $request): TrafficAnalyticsPeriod
    {
        return TrafficAnalyticsPeriod::from($request->validated('period'), Carbon::now('UTC'));
    }
}
