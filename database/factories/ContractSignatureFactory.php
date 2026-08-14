<?php

namespace Database\Factories;

use App\Models\Contract;
use App\Models\ContractSignature;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContractSignature>
 */
class ContractSignatureFactory extends Factory
{
    protected $model = ContractSignature::class;

    public function definition(): array
    {
        return [
            'contract_id' => Contract::factory(),
            'user_id'     => User::factory(),
            'signed_name' => fake()->name(),
            'ip_address'  => fake()->ipv4(),
            'user_agent'  => fake()->userAgent(),
            'signed_at'   => now(),
        ];
    }
}
