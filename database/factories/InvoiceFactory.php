<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\Invoice;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    public function definition(): array
    {
        $periodStart = Carbon::now()->startOfMonth()->subMonths(rand(0, 6));
        $periodEnd = $periodStart->copy()->endOfMonth();

        return [
            'business_id' => Business::factory(),
            'user_id' => User::factory(),
            'invoice_number' => 'INV-'.strtoupper(Str::random(8)),
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodEnd->toDateString(),
            'total_amount' => $this->faker->randomFloat(2, 100, 5000),
            'status' => 'draft',
            'notes' => null,
        ];
    }

    public function issued(): static
    {
        return $this->state(['status' => 'issued']);
    }

    public function paid(): static
    {
        return $this->state(['status' => 'paid']);
    }

    public function cancelled(): static
    {
        return $this->state(['status' => 'cancelled']);
    }
}
