<?php

namespace Tests\Feature;

use App\Events\DisputeResolved;
use App\Models\Contract;
use App\Models\Dispute;
use App\Models\DisputeEvidence;
use App\Models\Milestone;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Tests for the dispute flow: evidence submission, locking, access control, and resolution.
 */
class DisputeFlowTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ───────────────────────────────────────────────────────

    /**
     * Build an open dispute on a submitted milestone, with the full contract context.
     */
    private function openDispute(): array
    {
        $client     = User::factory()->client()->create();
        $freelancer = User::factory()->freelancer()->create();
        $admin      = User::factory()->admin()->create();

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
            'reason'       => 'Work does not meet the spec.',
        ]);

        return compact('client', 'freelancer', 'admin', 'contract', 'milestone', 'dispute');
    }

    // ── Dispute locking: no approve/reject while open ─────────────────

    public function test_disputed_milestone_cannot_be_approved_while_dispute_is_open(): void
    {
        ['client' => $client, 'milestone' => $milestone] = $this->openDispute();

        $this->actingAs($client)
            ->postJson("/api/v1/milestones/{$milestone->id}/approve")
            ->assertStatus(422);
    }

    public function test_disputed_milestone_cannot_be_resubmitted_while_dispute_is_open(): void
    {
        ['freelancer' => $freelancer, 'milestone' => $milestone] = $this->openDispute();

        $this->actingAs($freelancer)
            ->postJson("/api/v1/milestones/{$milestone->id}/submit")
            ->assertStatus(422);
    }

    // ── Evidence submission access ────────────────────────────────────

    public function test_client_can_submit_evidence(): void
    {
        ['client' => $client, 'dispute' => $dispute] = $this->openDispute();

        $this->actingAs($client)
            ->postJson("/api/v1/disputes/{$dispute->id}/evidence", [
                'message' => 'Here is my supporting evidence.',
            ])
            ->assertStatus(201);

        $this->assertDatabaseHas('dispute_evidence', [
            'dispute_id' => $dispute->id,
            'user_id'    => $client->id,
        ]);
    }

    public function test_freelancer_can_submit_evidence(): void
    {
        ['freelancer' => $freelancer, 'dispute' => $dispute] = $this->openDispute();

        $this->actingAs($freelancer)
            ->postJson("/api/v1/disputes/{$dispute->id}/evidence", [
                'message' => 'Here is my counter-evidence.',
            ])
            ->assertStatus(201);

        $this->assertDatabaseHas('dispute_evidence', [
            'dispute_id' => $dispute->id,
            'user_id'    => $freelancer->id,
        ]);
    }

    public function test_outsider_cannot_submit_evidence(): void
    {
        ['dispute' => $dispute] = $this->openDispute();
        $outsider = User::factory()->create();

        $this->actingAs($outsider)
            ->postJson("/api/v1/disputes/{$dispute->id}/evidence", [
                'message' => 'I am not part of this.',
            ])
            ->assertStatus(403);

        $this->assertDatabaseMissing('dispute_evidence', [
            'dispute_id' => $dispute->id,
            'user_id'    => $outsider->id,
        ]);
    }

    public function test_evidence_cannot_be_submitted_to_a_resolved_dispute(): void
    {
        ['client' => $client, 'dispute' => $dispute] = $this->openDispute();

        $dispute->update([
            'status'           => 'resolved_client',
            'resolution_notes' => 'Resolved in favour of client.',
            'resolved_at'      => now(),
        ]);

        $this->actingAs($client)
            ->postJson("/api/v1/disputes/{$dispute->id}/evidence", [
                'message' => 'Adding evidence after resolution.',
            ])
            ->assertStatus(422);
    }

    public function test_evidence_submission_requires_message_or_file(): void
    {
        ['client' => $client, 'dispute' => $dispute] = $this->openDispute();

        $this->actingAs($client)
            ->postJson("/api/v1/disputes/{$dispute->id}/evidence", [])
            ->assertStatus(422);
    }

    // ── Evidence is append-only (no edit/delete via API) ─────────────

    public function test_no_update_route_exists_for_dispute_evidence(): void
    {
        ['client' => $client, 'dispute' => $dispute] = $this->openDispute();

        $evidence = DisputeEvidence::factory()->create([
            'dispute_id' => $dispute->id,
            'user_id'    => $client->id,
            'message'    => 'Original message.',
        ]);

        $response = $this->actingAs($client)
            ->patchJson("/api/v1/disputes/{$dispute->id}/evidence/{$evidence->id}", [
                'message' => 'Tampered message.',
            ]);

        // No update route registered — expect 404 or 405
        $this->assertContains($response->status(), [404, 405]);
        $this->assertDatabaseHas('dispute_evidence', [
            'id'      => $evidence->id,
            'message' => 'Original message.',
        ]);
    }

    public function test_no_delete_route_exists_for_dispute_evidence(): void
    {
        ['client' => $client, 'dispute' => $dispute] = $this->openDispute();

        $evidence = DisputeEvidence::factory()->create([
            'dispute_id' => $dispute->id,
            'user_id'    => $client->id,
            'message'    => 'Evidence that should be permanent.',
        ]);

        $response = $this->actingAs($client)
            ->deleteJson("/api/v1/disputes/{$dispute->id}/evidence/{$evidence->id}");

        // No delete route registered — expect 404 or 405
        $this->assertContains($response->status(), [404, 405]);
        $this->assertDatabaseHas('dispute_evidence', ['id' => $evidence->id]);
    }

    // ── Resolution: admin/mediator only ──────────────────────────────

    public function test_admin_can_resolve_a_dispute(): void
    {
        Event::fake([DisputeResolved::class]);
        ['admin' => $admin, 'dispute' => $dispute] = $this->openDispute();

        $this->actingAs($admin)
            ->patchJson("/api/v1/disputes/{$dispute->id}/resolve", [
                'status'           => 'resolved_client',
                'resolution_notes' => 'Client prevails — work did not meet spec.',
            ])
            ->assertOk()
            ->assertJsonPath('dispute.status', 'resolved_client');

        $this->assertDatabaseHas('disputes', [
            'id'     => $dispute->id,
            'status' => 'resolved_client',
        ]);
    }

    public function test_dispute_resolved_event_is_dispatched_on_resolution(): void
    {
        Event::fake([DisputeResolved::class]);
        ['admin' => $admin, 'dispute' => $dispute] = $this->openDispute();

        $this->actingAs($admin)
            ->patchJson("/api/v1/disputes/{$dispute->id}/resolve", [
                'status'           => 'resolved_freelancer',
                'resolution_notes' => 'Freelancer delivered as agreed.',
            ]);

        Event::assertDispatched(DisputeResolved::class, fn ($e) =>
            $e->dispute->id === $dispute->id
        );
    }

    public function test_regular_client_cannot_resolve_a_dispute(): void
    {
        Event::fake([DisputeResolved::class]);
        ['client' => $client, 'dispute' => $dispute] = $this->openDispute();

        $this->actingAs($client)
            ->patchJson("/api/v1/disputes/{$dispute->id}/resolve", [
                'status'           => 'resolved_client',
                'resolution_notes' => 'Self-resolving.',
            ])
            ->assertStatus(403);

        Event::assertNotDispatched(DisputeResolved::class);
    }

    public function test_regular_freelancer_cannot_resolve_a_dispute(): void
    {
        Event::fake([DisputeResolved::class]);
        ['freelancer' => $freelancer, 'dispute' => $dispute] = $this->openDispute();

        $this->actingAs($freelancer)
            ->patchJson("/api/v1/disputes/{$dispute->id}/resolve", [
                'status'           => 'resolved_freelancer',
                'resolution_notes' => 'I win.',
            ])
            ->assertStatus(403);

        Event::assertNotDispatched(DisputeResolved::class);
    }

    public function test_resolution_notes_are_required_when_resolving(): void
    {
        ['admin' => $admin, 'dispute' => $dispute] = $this->openDispute();

        $this->actingAs($admin)
            ->patchJson("/api/v1/disputes/{$dispute->id}/resolve", [
                'status' => 'resolved_client',
                // no resolution_notes
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('resolution_notes');
    }

    public function test_an_already_resolved_dispute_cannot_be_resolved_again(): void
    {
        Event::fake([DisputeResolved::class]);
        ['admin' => $admin, 'dispute' => $dispute] = $this->openDispute();

        $this->actingAs($admin)->patchJson("/api/v1/disputes/{$dispute->id}/resolve", [
            'status'           => 'resolved_client',
            'resolution_notes' => 'First resolution.',
        ])->assertOk();

        // Second resolution attempt
        $this->actingAs($admin)->patchJson("/api/v1/disputes/{$dispute->id}/resolve", [
            'status'           => 'resolved_freelancer',
            'resolution_notes' => 'Second resolution attempt.',
        ])->assertStatus(422);

        Event::assertDispatchedTimes(DisputeResolved::class, 1);
    }

    // ── Dispute detail access ─────────────────────────────────────────

    public function test_client_can_view_their_dispute(): void
    {
        ['client' => $client, 'dispute' => $dispute] = $this->openDispute();

        $this->actingAs($client)
            ->getJson("/api/v1/disputes/{$dispute->id}")
            ->assertOk()
            ->assertJsonPath('id', $dispute->id);
    }

    public function test_freelancer_can_view_their_dispute(): void
    {
        ['freelancer' => $freelancer, 'dispute' => $dispute] = $this->openDispute();

        $this->actingAs($freelancer)
            ->getJson("/api/v1/disputes/{$dispute->id}")
            ->assertOk();
    }

    public function test_outsider_cannot_view_a_dispute(): void
    {
        ['dispute' => $dispute] = $this->openDispute();
        $outsider = User::factory()->create();

        $this->actingAs($outsider)
            ->getJson("/api/v1/disputes/{$dispute->id}")
            ->assertStatus(403);
    }
}
