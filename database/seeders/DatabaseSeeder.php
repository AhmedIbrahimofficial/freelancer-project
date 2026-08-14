<?php

namespace Database\Seeders;

use App\Models\Contract;
use App\Models\ContractSignature;
use App\Models\Dispute;
use App\Models\Milestone;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // ── Accounts ──────────────────────────────────────────────────────────
        $admin = User::firstOrCreate(
            ['email' => 'admin@freelancer-protect.test'],
            [
                'name'     => 'Admin User',
                'password' => Hash::make('password'),
                'role'     => 'admin',
            ]
        );

        $client = User::firstOrCreate(
            ['email' => 'client@freelancer-protect.test'],
            [
                'name'     => 'Demo Client',
                'password' => Hash::make('password'),
                'role'     => 'client',
            ]
        );

        $freelancer = User::firstOrCreate(
            ['email' => 'freelancer@freelancer-protect.test'],
            [
                'name'     => 'Demo Freelancer',
                'password' => Hash::make('password'),
                'role'     => 'freelancer',
            ]
        );

        $this->command->info("Accounts ready:");
        $this->command->info("  admin      → admin@freelancer-protect.test / password");
        $this->command->info("  client     → client@freelancer-protect.test / password  (id: {$client->id})");
        $this->command->info("  freelancer → freelancer@freelancer-protect.test / password  (id: {$freelancer->id})");

        // ── Demo Contract: fully signed and active ────────────────────────────
        $activeContract = Contract::create([
            'client_id'    => $client->id,
            'freelancer_id' => $freelancer->id,
            'title'        => 'Demo — Design system rebuild',
            'scope'        => "Audit existing marketing surfaces, define tokens (colour, type, spacing), and deliver 12 documented components in Figma plus a React implementation.\n\nOut of scope: Backend work, copywriting, ongoing maintenance after final milestone approval.",
            'status'       => 'active',
            'total_amount' => 4500.00,
            'currency'     => 'USD',
            'start_date'   => now()->toDateString(),
            'end_date'     => now()->addMonths(2)->toDateString(),
            'terms'        => "Deliverables are reviewed within 5 business days of submission.\nApproved milestones are released from escrow within 24 hours.\nEither party may open a dispute within 14 days of a milestone submission.",
        ]);

        ContractSignature::create([
            'contract_id' => $activeContract->id,
            'user_id'     => $client->id,
            'signed_name' => $client->name,
            'ip_address'  => '127.0.0.1',
            'user_agent'  => 'Seeder',
            'signed_at'   => now()->subDays(3),
        ]);

        ContractSignature::create([
            'contract_id' => $activeContract->id,
            'user_id'     => $freelancer->id,
            'signed_name' => $freelancer->name,
            'ip_address'  => '127.0.0.1',
            'user_agent'  => 'Seeder',
            'signed_at'   => now()->subDays(2),
        ]);

        $m1 = Milestone::create([
            'contract_id' => $activeContract->id,
            'title'       => 'Token foundation & audit',
            'description' => 'Written audit of existing surfaces plus a token sheet covering colour, type and spacing.',
            'amount'      => 1500.00,
            'due_date'    => now()->addWeeks(2)->toDateString(),
            'order'       => 1,
            'status'      => 'pending',
        ]);

        $m2 = Milestone::create([
            'contract_id' => $activeContract->id,
            'title'       => 'Component library in Figma',
            'description' => '12 documented components with variants, states and usage notes.',
            'amount'      => 1800.00,
            'due_date'    => now()->addWeeks(5)->toDateString(),
            'order'       => 2,
            'status'      => 'pending',
        ]);

        $m3 = Milestone::create([
            'contract_id' => $activeContract->id,
            'title'       => 'React implementation',
            'description' => '8 primitives implemented in React with Storybook coverage.',
            'amount'      => 1200.00,
            'due_date'    => now()->addWeeks(8)->toDateString(),
            'order'       => 3,
            'status'      => 'pending',
        ]);

        $this->command->info("Active contract created: {$activeContract->id}");
        $this->command->info("  Milestone 1 id: {$m1->id}");
        $this->command->info("  Milestone 2 id: {$m2->id}");
        $this->command->info("  Milestone 3 id: {$m3->id}");

        // ── Demo Contract: pending signature ──────────────────────────────────
        $pendingContract = Contract::create([
            'client_id'    => $client->id,
            'freelancer_id' => $freelancer->id,
            'title'        => 'Demo — Awaiting freelancer signature',
            'scope'        => 'Logistics dashboard audit — 9 dashboard views, prioritised issue list and a 1-hour walkthrough.',
            'status'       => 'pending_signature',
            'total_amount' => 2800.00,
            'currency'     => 'USD',
            'terms'        => "Audit delivered as a single document within 14 days of funding.\nEscrow is funded before work begins.",
        ]);

        ContractSignature::create([
            'contract_id' => $pendingContract->id,
            'user_id'     => $client->id,
            'signed_name' => $client->name,
            'ip_address'  => '127.0.0.1',
            'user_agent'  => 'Seeder',
            'signed_at'   => now()->subHour(),
        ]);

        Milestone::create([
            'contract_id' => $pendingContract->id,
            'title'       => 'Audit report & walkthrough',
            'description' => 'Prioritised issue list with severity ratings and a recorded walkthrough.',
            'amount'      => 2800.00,
            'due_date'    => now()->addWeeks(3)->toDateString(),
            'order'       => 1,
            'status'      => 'pending',
        ]);

        $this->command->info("Pending-signature contract created: {$pendingContract->id}");
        $this->command->info("");
        $this->command->info("Test the full flow:");
        $this->command->info("  1. POST /api/v1/login  {email: freelancer@..., password: password}");
        $this->command->info("  2. POST /api/v1/contracts/{$pendingContract->id}/sign  {signed_name: Demo Freelancer}");
        $this->command->info("  3. POST /api/v1/milestones/{$m1->id}/submit  (as freelancer)");
        $this->command->info("  4. POST /api/v1/milestones/{$m1->id}/approve  (as client)");
        $this->command->info("  5. POST /api/v1/milestones/{$m2->id}/submit then /dispute  (to test dispute flow)");
    }
}
