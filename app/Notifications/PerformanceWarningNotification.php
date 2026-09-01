<?php

namespace App\Notifications;

use App\Enums\PerformanceMetric;
use App\Models\User;
use App\Notifications\Channels\ExpoPushChannel;
use Illuminate\Notifications\Notification;

/**
 * UC-22: notifies an agent (and their business's admins) when a rolling
 * performance metric drops below their configured target. $forAgent
 * distinguishes the two audiences' copy — the agent gets a direct "your
 * rate dropped" message, an admin gets a third-person "agent X's rate
 * dropped" one, from the same notification instance.
 */
class PerformanceWarningNotification extends Notification
{
    public function __construct(
        private readonly User $agent,
        private readonly PerformanceMetric $metric,
        private readonly float $actualPercentage,
        private readonly float $targetPercentage,
        private readonly bool $forAgent,
    ) {}

    /**
     * @return array<int, class-string>
     */
    public function via(User $notifiable): array
    {
        return [ExpoPushChannel::class];
    }

    /**
     * @return array{title: string, body: string, data: array<string, mixed>}
     */
    public function toExpoPush(User $notifiable): array
    {
        $metricLabel = match ($this->metric) {
            PerformanceMetric::CONFIRMATION_RATE => 'confirmation rate',
            PerformanceMetric::DELIVERY_SUCCESS_RATE => 'delivery success rate',
        };

        $body = $this->forAgent
            ? "Your {$metricLabel} is {$this->actualPercentage}%, below your {$this->targetPercentage}% target."
            : "{$this->agent->name}'s {$metricLabel} is {$this->actualPercentage}%, below their {$this->targetPercentage}% target.";

        return [
            'title' => 'Performance warning',
            'body' => $body,
            'data' => [
                'type' => 'performance_warning',
                'agent_id' => $this->agent->id,
                'metric' => $this->metric->value,
                'actual_percentage' => $this->actualPercentage,
                'target_percentage' => $this->targetPercentage,
            ],
        ];
    }
}
