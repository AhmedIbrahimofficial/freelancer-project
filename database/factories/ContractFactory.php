<?php

namespace Database\Factories;

use App\Models\Contract;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contract>
 */
class ContractFactory extends Factory
{
    protected $model = Contract::class;

    public function definition(): array
    {
        return [
            'client_id'     => User::factory()->state(['role' => 'client']),
            'freelancer_id' => User::factory()->state(['role' => 'freelancer']),
            'title'         => fake()->sentence(4),
            'scope'         => fake()->paragraph(),
            'status'        => 'draft',
            'total_amount'  => fake()->randomFloat(2, 100, 10000),
            'currency'      => 'USD',
            'start_date'    => now()->addDay(),
            'end_date'      => now()->addMonth(),
            'terms'         => fake()->paragraph(),
        ];
    }

    /** Contract is in draft state (default). */
    public function draft(): static
    {
        return $this->state(['status' => 'draft']);
    }

    /** Contract has been sent, awaiting signatures. */
    public function pendingSignature(): static
    {
        return $this->state(['status' => 'pending_signature']);
    }

    /** Contract is fully signed and active. */
    public function active(): static
    {
        return $this->state(['status' => 'active']);
    }

    /** Contract is in a disputed state. */
    public function disputed(): static
    {
        return $this->state(['status' => 'disputed']);
    }

    /** Contract is completed. */
    public function completed(): static
    {
        return $this->state(['status' => 'completed']);
    }
}
