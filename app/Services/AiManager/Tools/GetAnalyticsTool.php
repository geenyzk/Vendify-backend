<?php

namespace App\Services\AiManager\Tools;

use App\Http\Controllers\AnalyticsController;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Business analytics over a date range (revenue, profit, success rate, service
 * mix, top customers). Reuses the exact AnalyticsController computation the
 * admin dashboard uses, then trims the heavy per-day time-series so the model
 * gets the decision-useful aggregates without a wall of numbers.
 */
class GetAnalyticsTool extends AiTool
{
    public function name(): string
    {
        return 'get_analytics';
    }

    public function description(): string
    {
        return 'Get business analytics for a date range (default last 30 days): revenue, cost, profit, success rate, average transaction value, new signups, revenue by service type and by provider, funding vs spend, and top customers. Use for "how is business doing", trends, profitability, and growth questions.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'start_date' => ['type' => 'string', 'description' => 'Start date YYYY-MM-DD. Defaults to 30 days ago.'],
                'end_date' => ['type' => 'string', 'description' => 'End date YYYY-MM-DD. Defaults to today.'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ];
    }

    public function handle(array $arguments, User $actor): array
    {
        $request = Request::create('/', 'GET', array_filter([
            'start_date' => $arguments['start_date'] ?? null,
            'end_date' => $arguments['end_date'] ?? null,
        ]));

        $data = app(AnalyticsController::class)->index($request)->getData(true)['data'] ?? [];

        // Drop the daily label/value arrays — big and rarely needed for the
        // model's reasoning. Keep the summary and grouped aggregates.
        return [
            'period' => $data['period'] ?? null,
            'summary' => $data['summary'] ?? null,
            'by_service_type' => $data['by_service_type'] ?? [],
            'by_provider' => array_slice($data['by_provider'] ?? [], 0, 10),
            'funding_vs_spend' => $data['funding_vs_spend'] ?? null,
            'top_customers' => $data['top_customers'] ?? [],
            'note' => 'Per-day time-series omitted for brevity. All money is NGN. Profit is measured only over sales with a recorded vendor cost.',
        ];
    }
}
