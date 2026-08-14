<?php

namespace Tests\Feature;

use App\Events\ContractSigned;
use App\Models\Contract;
use App\Models\ContractSignature;
use App\Models\User;
use Database\Factories\ContractFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Tests for contract creation, signing constraints, and signature record integrity.
 */
class ContractSigningTest extends TestCase
{
    use RefreshDatabase;

    // ── Contract creation ─────────────────────────────────────────────

    public function test_client_can_create_a_contract(): void
    {
        $client     = User::factory()->client()->create();
        $freelancer = User::factory()->freelancer()->create();

        $response = $this->actingAs($client)
            ->postJson('/api/v1/contracts', [
                'freelancer_id' => $freelancer->id,
                'title'         => 'Build a landing page',
                'scope'         => 'Full responsive redesign of the homepage.',
                'total_amount'  => 1500.00,
                'currency'      => 'USD',
                'start_date'    => now()->addDay()->toDateString(),
                'end_date'      => now()->addMonth()->toDateString(),
                'terms'         => 'Payment on milestone completion.',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('status', 'draft')
            ->assertJsonPath('client_id', $client->id)
            ->assertJsonPath('freelancer_id', $freelancer->id);

        $this->assertDatabaseHas('contracts', [
            'client_id'    => $client->id,
            'freelancer_id' => $freelancer->id,
            'status'       => 'draft',
        ]);
    }

    public function test_new_contract_status_is_draft_not_active(): void
    {
        $client     = User::factory()->client()->create();
        $freelancer = User::factory()->freelancer()->create();

        $this->actingAs($client)->postJson('/api/v1/contracts', [
            'freelancer_id' => $freelancer->id,
            'title'         => 'Draft contract',
            'scope'         => 'Some scope',
            'total_amount'  => 500.00,
        ]);

        $this->assertDatabaseMissing('contracts', ['status' => 'active']);
    }

    // ── Status gate: cannot be "active" until BOTH parties have signed ─

    public function test_contract_stays_pending_after_only_one_signature(): void
    {
        $client     = User::factory()->client()->create(['name' => 'Alice Johnson']);
        $freelancer = User::factory()->freelancer()->create(['name' => 'Bob Smith']);

        $contract = Contract::factory()->pendingSignature()->create([
            'client_id'    => $client->id,
            'freelancer_id' => $freelancer->id,
        ]);

        // Only the client signs
        $this->actingAs($client)->postJson("/api/v1/contracts/{$contract->id}/sign", [
            'signed_name' => 'Alice Johnson',
        ])->assertOk();

        $this->assertDatabaseHas('contracts', [
            'id'     => $contract->id,
            'status' => 'pending_signature',
        ]);
    }

    public function test_contract_becomes_active_once_both_parties_have_signed(): void
    {
        $client     = User::factory()->client()->create(['name' => 'Alice Johnson']);
        $freelancer = User::factory()->freelancer()->create(['name' => 'Bob Smith']);

        $contract = Contract::factory()->pendingSignature()->create([
            'client_id'    => $client->id,
            'freelancer_id' => $freelancer->id,
        ]);

        $this->actingAs($client)->postJson("/api/v1/contracts/{$contract->id}/sign", [
            'signed_name' => 'Alice Johnson',
        ])->assertOk();

        $this->actingAs($freelancer)->postJson("/api/v1/contracts/{$contract->id}/sign", [
            'signed_name' => 'Bob Smith',
        ])->assertOk();

        $this->assertDatabaseHas('contracts', [
            'id'     => $contract->id,
            'status' => 'active',
        ]);
    }

    // ── Signature name validation ─────────────────────────────────────

    /**
     * The sign endpoint does NOT currently validate the name against the user's account name
     * (it accepts any non-empty string). This test documents the current behaviour and flags
     * where name-matching enforcement should be added.
     *
     * If name-matching is later enforced, change the expectation to assertStatus(422).
     */
    public function test_signing_with_mismatched_name_is_currently_permitted(): void
    {
        $client     = User::factory()->client()->create(['name' => 'Alice Johnson']);
        $freelancer = User::factory()->freelancer()->create(['name' => 'Bob Smith']);

        $contract = Contract::factory()->pendingSignature()->create([
            'client_id'    => $client->id,
            'freelancer_id' => $freelancer->id,
        ]);

        // Name does not match user's account name — currently accepted
        $response = $this->actingAs($client)->postJson("/api/v1/contracts/{$contract->id}/sign", [
            'signed_name' => 'Completely Wrong Name',
        ]);

        // Document current behaviour: 200 OK (update to 422 once enforcement is added)
        $response->assertOk();
    }

    public function test_signing_requires_a_signed_name(): void
    {
        $client    = User::factory()->client()->create();
        $freelancer = User::factory()->freelancer()->create();
        $contract  = Contract::factory()->pendingSignature()->create([
            'client_id'    => $client->id,
            'freelancer_id' => $freelancer->id,
        ]);

        $this->actingAs($client)
            ->postJson("/api/v1/contracts/{$contract->id}/sign", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('signed_name');
    }

    // ── Signature record integrity ────────────────────────────────────

    public function test_signature_record_stores_required_fields(): void
    {
        $client     = User::factory()->client()->create(['name' => 'Alice Johnson']);
        $freelancer = User::factory()->freelancer()->create(['name' => 'Bob Smith']);

        $contract = Contract::factory()->pendingSignature()->create([
            'client_id'    => $client->id,
            'freelancer_id' => $freelancer->id,
        ]);

        $this->actingAs($client)->postJson("/api/v1/contracts/{$contract->id}/sign", [
            'signed_name' => 'Alice Johnson',
        ])->assertOk();

        $sig = ContractSignature::where([
            'contract_id' => $contract->id,
            'user_id'     => $client->id,
        ])->firstOrFail();

        $this->assertEquals($client->id, $sig->user_id);
        $this->assertEquals('Alice Johnson', $sig->signed_name);
        $this->assertNotNull($sig->signed_at);
        $this->assertNotNull($sig->ip_address);
    }

    public function test_a_user_cannot_sign_the_same_contract_twice(): void
    {
        $client     = User::factory()->client()->create(['name' => 'Alice Johnson']);
        $freelancer = User::factory()->freelancer()->create(['name' => 'Bob Smith']);

        $contract = Contract::factory()->pendingSignature()->create([
            'client_id'    => $client->id,
            'freelancer_id' => $freelancer->id,
        ]);

        $this->actingAs($client)->postJson("/api/v1/contracts/{$contract->id}/sign", [
            'signed_name' => 'Alice Johnson',
        ])->assertOk();

        // Second attempt
        $this->actingAs($client)->postJson("/api/v1/contracts/{$contract->id}/sign", [
            'signed_name' => 'Alice Johnson',
        ])->assertStatus(422);
    }

    public function test_a_draft_contract_cannot_be_signed(): void
    {
        $client     = User::factory()->client()->create();
        $freelancer = User::factory()->freelancer()->create();
        $contract   = Contract::factory()->draft()->create([
            'client_id'    => $client->id,
            'freelancer_id' => $freelancer->id,
        ]);

        $this->actingAs($client)->postJson("/api/v1/contracts/{$contract->id}/sign", [
            'signed_name' => $client->name,
        ])->assertStatus(422);
    }

    // ── Edit lock: contract cannot be edited after signing ────────────

    /**
     * No PATCH/PUT endpoint exists on the contracts resource (by design — once sent,
     * a contract is immutable). This test confirms the route does not exist and
     * returns 404/405, preventing accidental edits after signing.
     */
    public function test_no_edit_route_exists_for_contracts_after_signing(): void
    {
        $client     = User::factory()->client()->create(['name' => 'Alice Johnson']);
        $freelancer = User::factory()->freelancer()->create(['name' => 'Bob Smith']);
        $contract   = Contract::factory()->pendingSignature()->create([
            'client_id'    => $client->id,
            'freelancer_id' => $freelancer->id,
        ]);

        // Sign
        $this->actingAs($client)->postJson("/api/v1/contracts/{$contract->id}/sign", [
            'signed_name' => 'Alice Johnson',
        ]);

        // Attempt PATCH — should return 404 or 405 (route not registered)
        $response = $this->actingAs($client)
            ->patchJson("/api/v1/contracts/{$contract->id}", ['title' => 'Hacked Title']);

        $this->assertContains($response->status(), [404, 405]);
        $this->assertDatabaseMissing('contracts', ['title' => 'Hacked Title']);
    }

    // ── Third-party cannot sign ───────────────────────────────────────

    public function test_a_non_party_user_cannot_sign_a_contract(): void
    {
        $client     = User::factory()->client()->create();
        $freelancer = User::factory()->freelancer()->create();
        $outsider   = User::factory()->create();

        $contract = Contract::factory()->pendingSignature()->create([
            'client_id'    => $client->id,
            'freelancer_id' => $freelancer->id,
        ]);

        $this->actingAs($outsider)
            ->postJson("/api/v1/contracts/{$contract->id}/sign", [
                'signed_name' => $outsider->name,
            ])
            ->assertStatus(403);
    }

    // ── ContractSigned event dispatched ──────────────────────────────

    public function test_contract_signed_event_is_dispatched_on_each_signature(): void
    {
        Event::fake([ContractSigned::class]);

        $client     = User::factory()->client()->create(['name' => 'Alice Johnson']);
        $freelancer = User::factory()->freelancer()->create(['name' => 'Bob Smith']);

        $contract = Contract::factory()->pendingSignature()->create([
            'client_id'    => $client->id,
            'freelancer_id' => $freelancer->id,
        ]);

        $this->actingAs($client)->postJson("/api/v1/contracts/{$contract->id}/sign", [
            'signed_name' => 'Alice Johnson',
        ]);

        Event::assertDispatched(ContractSigned::class, fn ($e) =>
            $e->contract->id === $contract->id && $e->signedBy->id === $client->id
        );
    }
}
