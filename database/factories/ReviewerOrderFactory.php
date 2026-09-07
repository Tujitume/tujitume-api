<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\ReviewerOrder;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ReviewerOrder>
 */
class ReviewerOrderFactory extends Factory
{
    protected $model = ReviewerOrder::class;

    public function definition(): array
    {
        return [
            'order_type'     => $this->faker->randomElement(['round_review', 'site_visit']),
            'fee_usd'        => $this->faker->randomFloat(2, 50, 500),
            'fee_kes'        => null,
            'currency'       => 'USD',
            'work_status'    => 'assigned',
            'payment_status' => 'unpaid',
            'deadline'       => now()->addDays(14),
        ];
    }

    public function delivered(): static
    {
        return $this->state(['work_status' => 'delivered', 'delivered_at' => now()]);
    }

    public function paid(): static
    {
        return $this->state(['payment_status' => 'completed', 'paid_at' => now()]);
    }
}
