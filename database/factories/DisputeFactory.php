<?php

namespace Database\Factories;

use App\Models\Contract;
use App\Models\Dispute;
use App\Models\Milestone;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Dispute>
 */
class DisputeFactory extends Factory
{
    protected $model = Dispute::class;

    public function definition(): array
    {
        $contract = Contract::factory()->active()->create();

        return [
            'contract_id'          => $contract->id,
            'milestone_id'         => null,
            'raised_by'            => $contract->client_id,
            'assigned_mediator_id' => null,
            'status'               => 'open',
            'reason'               => fake()->paragraph(),
            'resolution_notes'     => null,
            'resolved_at'          => null,
        ];
    }

    public function open(): static
    {
        return $this->state(['status' => 'open']);
    }

    public function underReview(): static
    {
        return $this->state(['status' => 'under_review']);
    }

    public function resolvedForClient(): static
    {
        return $this->state([
            'status'           => 'resolved_client',
            'resolution_notes' => fake()->paragraph(),
            'resolved_at'      => now(),
        ]);
    }

    public function resolvedForFreelancer(): static
    {
        return $this->state([
            'status'           => 'resolved_freelancer',
            'resolution_notes' => fake()->paragraph(),
            'resolved_at'      => now(),
        ]);
    }
}
