<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default performance targets
    |--------------------------------------------------------------------------
    |
    | Seeded as business-wide PerformanceTarget rows (user_id null) when a
    | business is created, and used as the read-side fallback for businesses
    | created before that seeding existed.
    |
    | An agent with no target row of their own is measured against these —
    | see AgentPerformanceEvaluator::targetsFor(), which prefers an
    | agent-specific row and falls back to the business-wide one.
    |
    */

    'defaults' => [
        'confirmation_rate' => (float) env('PERFORMANCE_DEFAULT_CONFIRMATION_RATE', 80),
        'delivery_success_rate' => (float) env('PERFORMANCE_DEFAULT_DELIVERY_SUCCESS_RATE', 90),
    ],

    /*
    |--------------------------------------------------------------------------
    | Evaluation window
    |--------------------------------------------------------------------------
    |
    | Minimum orders an agent must have handled in the target's period
    | before a rate is judged — a rate from three orders is not evidence.
    | Mirrors the performance_targets column default.
    |
    */

    'min_orders_for_evaluation' => (int) env('PERFORMANCE_MIN_ORDERS_FOR_EVALUATION', 10),

    /*
    |--------------------------------------------------------------------------
    | Default evaluation period
    |--------------------------------------------------------------------------
    |
    | The rolling window a target is measured over when neither the agent's
    | own row nor the business-wide row specifies one. Must be a value of
    | App\Enums\PerformanceTargetPeriod: daily, weekly or monthly.
    |
    */

    'default_period' => env('PERFORMANCE_DEFAULT_PERIOD', 'weekly'),

];
