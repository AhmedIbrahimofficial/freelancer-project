<?php

namespace Database\Factories;

use App\Models\Contract;
use App\Models\Milestone;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Milestone>
 */
class MilestoneFactory extends Factory
{
    protected $model = Milestone::class;

    public function definition(): array
    {
        return [
            'contract_id'      => Contract::factory(),
            'title'            => fake()->sentence(3),
            'description'      => fake()->paragraph(),
            'amount'           => fake()->randomFloat(2, 50, 2000),
            'due_date'         => now()->addWeeks(2),
            'order'            => 1,
            'status'           => 'pending',
            'submitted_at'     => null,
            'approved_at'      => null,
            'submission_notes' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(['status' => 'pending']);
    }

    public function inProgress(): static
    {
        return $this->state(['status' => 'in_progress']);
    }

    public function submitted(): static
    {
        return $this->state([
            'status'       => 'submitted',
            'submitted_at' => now(),
        ]);
    }

    public function approved(): static
    {
        return $this->state([
            'status'      => 'approved',
            'submitted_at' => now()->subHour(),
            'approved_at'  => now(),
        ]);
    }

    public function disputed(): static
    {
        return $this->state(['status' => 'disputed']);
    }

    public function released(): static
    {
        return $this->state([
            'status'      => 'released',
            'submitted_at' => now()->subHours(2),
            'approved_at'  => now()->subHour(),
        ]);
    }
}
