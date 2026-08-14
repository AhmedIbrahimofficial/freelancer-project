<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Dispute;
use App\Models\Milestone;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for role-based access control and contract party authorization.
 *
 * The app uses a `role` column (freelancer/client/admin) backed by
 * User::isAdmin(), isClient(), isFreelancer() helpers. Spatie
 * laravel-permission can be used for fine-grained permissions in addition
 * to this, but these tests cover the role-level gates enforced by controllers.
 */
class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    // ── Unauthenticated access ────────────────────────────────────────

    public function test_unauthenticated_user_cannot_access_dashboard(): void
    {
        $this->getJson('/api/v1/dashboard')->assertStatus(401);
    }

    public function test_unauthenticated_user_cannot_view_a_contract(): void
    {
        $contract = Contract::factory()->active()->create();

        $this->getJson("/api/v1/contracts/{$contract->id}")->assertStatus(401);
    }

    // ── Non-party access to contracts ─────────────────────────────────

    public function test_outsider_cannot_view_contract_detail(): void
    {
        $client     = User::factory()->client()->create();
        $freelancer = User::factory()->freelancer()->create();
        $outsider   = User::factory()->create();

        $contract = Contract::factory()->active()->create([
            'client_id'    => $client->id,
            'freelancer_id' => $freelancer->id,
        ]);

        $this->actingAs($outsider)
            ->getJson("/api/v1/contracts/{$contract->id}")
            ->assertStatus(403);
    }

    public function test_client_party_can_view_contract_detail(): void
    {
        $client     = User::factory()->client()->create();
        $freelancer = User::factory()->freelancer()->create();

        $contract = Contract::factory()->active()->create([
            'client_id'    => $client->id,
            'freelancer_id' => $freelancer->id,
        ]);

        $this->actingAs($client)
            ->getJson("/api/v1/contracts/{$contract->id}")
            ->assertOk();
    }

    public function test_freelancer_party_can_view_contract_detail(): void
    {
        $client     = User::factory()->client()->create();
        $freelancer = User::factory()->freelancer()->create();

        $contract = Contract::factory()->active()->create([
            'client_id'    => $client->id,
            'freelancer_id' => $freelancer->id,
        ]);

        $this->actingAs($freelancer)
            ->getJson("/api/v1/contracts/{$contract->id}")
            ->assertOk();
    }

    public function test_admin_can_view_any_contract(): void
    {
        $admin    = User::factory()->admin()->create();
        $contract = Contract::factory()->active()->create();

        $this->actingAs($admin)
            ->getJson("/api/v1/contracts/{$contract->id}")
            ->assertOk();
    }

    // ── Non-party cannot view disputes ───────────────────────────────

    public function test_outsider_cannot_view_dispute_detail(): void
    {
        $client     = User::factory()->client()->create();
        $freelancer = User::factory()->freelancer()->create();
        $outsider   = User::factory()->create();

        $contract = Contract::factory()->disputed()->create([
            'client_id'    => $client->id,
            'freelancer_id' => $freelancer->id,
        ]);

        $milestone = Milestone::factory()->disputed()->create([
            'contract_id' => $contract->id,
        ]);

        $dispute = Dispute::create([
            'contract_id'  => $contract->id,
            'milestone_id' => $milestone->id,
            'raised_by'    => $client->id,
            'status'       => 'open',
            'reason'       => 'Dispute reason.',
        ]);

        $this->actingAs($outsider)
            ->getJson("/api/v1/disputes/{$dispute->id}")
            ->assertStatus(403);
    }

    // ── Role-based contract creation ──────────────────────────────────

    /**
     * Only clients and admins can create contracts (enforced by CreateContractRequest::authorize()).
     * A user with the freelancer role is correctly rejected with 403.
     */
    public function test_freelancer_role_cannot_create_a_contract(): void
    {
        $freelancer  = User::factory()->freelancer()->create();
        $anotherUser = User::factory()->freelancer()->create();

        $this->actingAs($freelancer)->postJson('/api/v1/contracts', [
            'freelancer_id' => $anotherUser->id,
            'title'         => 'Reverse contract',
            'scope'         => 'Scope',
            'total_amount'  => 200.00,
        ])->assertStatus(403);
    }

    /**
     * A user with the client role can create a contract.
     */
    public function test_client_role_can_create_a_contract(): void
    {
        $client     = User::factory()->client()->create();
        $freelancer = User::factory()->freelancer()->create();

        $this->actingAs($client)->postJson('/api/v1/contracts', [
            'freelancer_id' => $freelancer->id,
            'title'         => 'Valid contract',
            'scope'         => 'Scope of work.',
            'total_amount'  => 500.00,
        ])->assertStatus(201);
    }

    // ── Only client can send a contract ──────────────────────────────

    public function test_freelancer_cannot_send_a_contract(): void
    {
        $client     = User::factory()->client()->create();
        $freelancer = User::factory()->freelancer()->create();

        $contract = Contract::factory()->draft()->create([
            'client_id'    => $client->id,
            'freelancer_id' => $freelancer->id,
        ]);

        $this->actingAs($freelancer)
            ->postJson("/api/v1/contracts/{$contract->id}/send")
            ->assertStatus(403);

        $this->assertDatabaseHas('contracts', ['id' => $contract->id, 'status' => 'draft']);
    }

    public function test_client_can_send_their_own_draft_contract(): void
    {
        $client     = User::factory()->client()->create();
        $freelancer = User::factory()->freelancer()->create();

        $contract = Contract::factory()->draft()->create([
            'client_id'    => $client->id,
            'freelancer_id' => $freelancer->id,
        ]);

        $this->actingAs($client)
            ->postJson("/api/v1/contracts/{$contract->id}/send")
            ->assertOk();
    }

    // ── Only admin can resolve disputes ──────────────────────────────

    public function test_client_role_cannot_resolve_dispute(): void
    {
        $client     = User::factory()->client()->create();
        $freelancer = User::factory()->freelancer()->create();

        $contract = Contract::factory()->disputed()->create([
            'client_id'    => $client->id,
            'freelancer_id' => $freelancer->id,
        ]);

        $milestone = Milestone::factory()->disputed()->create([
            'contract_id' => $contract->id,
        ]);

        $dispute = Dispute::create([
            'contract_id'  => $contract->id,
            'milestone_id' => $milestone->id,
            'raised_by'    => $client->id,
            'status'       => 'open',
            'reason'       => 'Issue.',
        ]);

        $this->actingAs($client)
            ->patchJson("/api/v1/disputes/{$dispute->id}/resolve", [
                'status'           => 'resolved_client',
                'resolution_notes' => 'Self-resolved.',
            ])
            ->assertStatus(403);
    }

    public function test_freelancer_role_cannot_resolve_dispute(): void
    {
        $client     = User::factory()->client()->create();
        $freelancer = User::factory()->freelancer()->create();

        $contract = Contract::factory()->disputed()->create([
            'client_id'    => $client->id,
            'freelancer_id' => $freelancer->id,
        ]);

        $milestone = Milestone::factory()->disputed()->create([
            'contract_id' => $contract->id,
        ]);

        $dispute = Dispute::create([
            'contract_id'  => $contract->id,
            'milestone_id' => $milestone->id,
            'raised_by'    => $client->id,
            'status'       => 'open',
            'reason'       => 'Issue.',
        ]);

        $this->actingAs($freelancer)
            ->patchJson("/api/v1/disputes/{$dispute->id}/resolve", [
                'status'           => 'resolved_freelancer',
                'resolution_notes' => 'I win.',
            ])
            ->assertStatus(403);
    }

    public function test_admin_role_can_resolve_dispute(): void
    {
        $admin      = User::factory()->admin()->create();
        $client     = User::factory()->client()->create();
        $freelancer = User::factory()->freelancer()->create();

        $contract = Contract::factory()->disputed()->create([
            'client_id'    => $client->id,
            'freelancer_id' => $freelancer->id,
        ]);

        $milestone = Milestone::factory()->disputed()->create([
            'contract_id' => $contract->id,
        ]);

        $dispute = Dispute::create([
            'contract_id'  => $contract->id,
            'milestone_id' => $milestone->id,
            'raised_by'    => $client->id,
            'status'       => 'open',
            'reason'       => 'Issue.',
        ]);

        $this->actingAs($admin)
            ->patchJson("/api/v1/disputes/{$dispute->id}/resolve", [
                'status'           => 'resolved_split',
                'resolution_notes' => 'Split the difference.',
            ])
            ->assertOk();
    }

    // ── Only admin can trigger AI summaries ──────────────────────────

    public function test_non_admin_cannot_trigger_ai_summary(): void
    {
        $client     = User::factory()->client()->create();
        $freelancer = User::factory()->freelancer()->create();

        $contract = Contract::factory()->disputed()->create([
            'client_id'    => $client->id,
            'freelancer_id' => $freelancer->id,
        ]);

        $dispute = Dispute::create([
            'contract_id' => $contract->id,
            'raised_by'   => $client->id,
            'status'      => 'open',
            'reason'      => 'Issue.',
        ]);

        $this->actingAs($client)
            ->postJson("/api/v1/disputes/{$dispute->id}/ai-summary")
            ->assertStatus(403);
    }

    // ── Dashboard scoping ─────────────────────────────────────────────

    public function test_dashboard_only_returns_contracts_for_the_authenticated_user(): void
    {
        $client     = User::factory()->client()->create();
        $otherClient = User::factory()->client()->create();

        $myContract    = Contract::factory()->active()->create(['client_id' => $client->id]);
        $otherContract = Contract::factory()->active()->create(['client_id' => $otherClient->id]);

        $response = $this->actingAs($client)
            ->getJson('/api/v1/dashboard')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->toArray();

        $this->assertContains($myContract->id, $ids);
        $this->assertNotContains($otherContract->id, $ids);
    }
}
