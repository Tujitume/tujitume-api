<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\Programs\Rounds\ReviewerApplicationAssignment;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ReviewerApplicationAssignment>
 */
class ReviewerApplicationAssignmentFactory extends Factory
{
    protected $model = ReviewerApplicationAssignment::class;

    public function definition(): array
    {
        return [
            'status'      => 'assigned',
            'assigned_at' => now(),
        ];
    }

    public function inReview(): static
    {
        return $this->state(['status' => 'in_review', 'started_at' => now()]);
    }

    public function completed(): static
    {
        return $this->state(['status' => 'completed', 'completed_at' => now()]);
    }
}
