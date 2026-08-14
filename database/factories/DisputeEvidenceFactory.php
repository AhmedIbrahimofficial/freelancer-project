<?php

namespace Database\Factories;

use App\Models\Dispute;
use App\Models\DisputeEvidence;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DisputeEvidence>
 */
class DisputeEvidenceFactory extends Factory
{
    protected $model = DisputeEvidence::class;

    public function definition(): array
    {
        return [
            'dispute_id' => Dispute::factory(),
            'user_id'    => User::factory(),
            'message'    => fake()->paragraph(),
            'file_path'  => null,
            'file_name'  => null,
            'file_mime'  => null,
            'file_size'  => null,
        ];
    }
}
