<?php

namespace Tests\Feature;

use App\Events\MilestoneApproved;
use App\Events\MilestoneSubmitted;
use App\Models\Contract;
use App\Models\Dispute;
use App\Models\Milestone;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Tests for the milestone submission, approval, and dispute lifecycle.
 */
class MilestoneFlowTest extends TestCase
{
    use RefreshDatabase;

    // ── Helper: build an active contract with a milestone ─────────────

    private function activeContractWithMilestone(string $milestoneStatus = 'pending'): array
    {
        $client     = User::factory()->client()->create();
        $freelancer = User::factory()->freelancer()->create();

        $contract = Contract::factory()->active()->create([
            'client_id'    => $client->id,
            'freelancer_id' => $freelancer->id,
        ]);

        $milestone = Milestone::factory()->create([
            'contract_id' => $contract->id,
            'status'      => $milestoneStatus,
        ]);

        if ($milestoneStatus === 'submitted') {
            $milestone->update(['submitted_at' => now()]);
        }

        return compact('client', 'freelancer', 'contract', 'milestone');
    }

    // ── Submission rules ─────────────────────────────────────────────

    public function test_freelancer_can_submit_a_pending_milestone(): void
    {
        ['freelancer' => $freelancer, 'milestone' => $milestone] = $this->activeContractWithMilestone('pending');

        $this->actingAs($freelancer)
            ->postJson("/api/v1/milestones/{$milestone->id}/submit", [
                'submission_notes' => 'Work is done.',
            ])
            ->assertOk()
            ->assertJsonPath('milestone.status', 'submitted');

        $this->assertDatabaseHas('milestones', ['id' => $milestone->id, 'status' => 'submitted']);
    }

    public function test_client_cannot_submit_a_milestone(): void
    {
        ['client' => $client, 'milestone' => $milestone] = $this->activeContractWithMilestone('pending');

        $this->actingAs($client)
            ->postJson("/api/v1/milestones/{$milestone->id}/submit")
            ->assertStatus(403);
    }

    public function test_outsider_cannot_submit_a_milestone(): void
    {
        ['milestone' => $milestone] = $this->activeContractWithMilestone('pending');
        $outsider = User::factory()->freelancer()->create();

        $this->actingAs($outsider)
            ->postJson("/api/v1/milestones/{$milestone->id}/submit")
            ->assertStatus(403);
    }

    public function test_milestone_submitted_event_is_dispatched(): void
    {
        Event::fake([MilestoneSubmitted::class]);
        ['freelancer' => $freelancer, 'milestone' => $milestone] = $this->activeContractWithMilestone('pending');

        $this->actingAs($freelancer)
            ->postJson("/api/v1/milestones/{$milestone->id}/submit");

        Event::assertDispatched(MilestoneSubmitted::class, fn ($e) =>
            $e->milestone->id === $milestone->id
        );
    }

    public function test_already_submitted_milestone_cannot_be_submitted_again(): void
    {
        ['freelancer' => $freelancer, 'milestone' => $milestone] = $this->activeContractWithMilestone('submitted');

        $this->actingAs($freelancer)
            ->postJson("/api/v1/milestones/{$milestone->id}/submit")
            ->assertStatus(422);
    }

    // ── Approval rules ────────────────────────────────────────────────

    public function test_client_can_approve_a_submitted_milestone(): void
    {
        ['client' => $client, 'milestone' => $milestone] = $this->activeContractWithMilestone('submitted');

        $this->actingAs($client)
            ->postJson("/api/v1/milestones/{$milestone->id}/approve")
            ->assertOk()
            ->assertJsonPath('milestone.status', 'approved');

        $this->assertDatabaseHas('milestones', ['id' => $milestone->id, 'status' => 'approved']);
    }

    public function test_freelancer_cannot_approve_a_milestone(): void
    {
        ['freelancer' => $freelancer, 'milestone' => $milestone] = $this->activeContractWithMilestone('submitted');

        $this->actingAs($freelancer)
            ->postJson("/api/v1/milestones/{$milestone->id}/approve")
            ->assertStatus(403);
    }

    public function test_milestone_approved_event_is_dispatched(): void
    {
        Event::fake([MilestoneApproved::class]);
        ['client' => $client, 'milestone' => $milestone] = $this->activeContractWithMilestone('submitted');

        $this->actingAs($client)
            ->postJson("/api/v1/milestones/{$milestone->id}/approve");

        Event::assertDispatched(MilestoneApproved::class, fn ($e) =>
            $e->milestone->id === $milestone->id
        );
    }

    public function test_approved_event_is_not_dispatched_if_approval_fails(): void
    {
        Event::fake([MilestoneApproved::class]);
        ['client' => $client, 'milestone' => $milestone] = $this->activeContractWithMilestone('pending');

        // Cannot approve a 'pending' milestone
        $this->actingAs($client)
            ->postJson("/api/v1/milestones/{$milestone->id}/approve")
            ->assertStatus(422);

        Event::assertNotDispatched(MilestoneApproved::class);
    }

    public function test_an_approved_milestone_cannot_be_approved_again(): void
    {
        ['client' => $client, 'milestone' => $milestone] = $this->activeContractWithMilestone('approved');

        $this->actingAs($client)
            ->postJson("/api/v1/milestones/{$milestone->id}/approve")
            ->assertStatus(422);
    }

    // ── Dispute rules on milestones ───────────────────────────────────

    public function test_an_approved_milestone_cannot_be_disputed(): void
    {
        ['client' => $client, 'milestone' => $milestone] = $this->activeContractWithMilestone('approved');

        $this->actingAs($client)
            ->postJson("/api/v1/milestones/{$milestone->id}/dispute", [
                'reason' => 'I changed my mind.',
            ])
            ->assertStatus(422);
    }

    public function test_a_pending_milestone_cannot_be_disputed(): void
    {
        ['client' => $client, 'milestone' => $milestone] = $this->activeContractWithMilestone('pending');

        $this->actingAs($client)
            ->postJson("/api/v1/milestones/{$milestone->id}/dispute", [
                'reason' => 'Pre-emptive dispute.',
            ])
            ->assertStatus(422);
    }

    public function test_a_submitted_milestone_can_be_disputed_by_client(): void
    {
        ['client' => $client, 'milestone' => $milestone] = $this->activeContractWithMilestone('submitted');

        $this->actingAs($client)
            ->postJson("/api/v1/milestones/{$milestone->id}/dispute", [
                'reason' => 'Work does not match the spec.',
            ])
            ->assertStatus(201);

        $this->assertDatabaseHas('milestones', ['id' => $milestone->id, 'status' => 'disputed']);
    }

    public function test_a_submitted_milestone_can_be_disputed_by_freelancer(): void
    {
        ['freelancer' => $freelancer, 'milestone' => $milestone] = $this->activeContractWithMilestone('submitted');

        $this->actingAs($freelancer)
            ->postJson("/api/v1/milestones/{$milestone->id}/dispute", [
                'reason' => 'Scope was changed without agreement.',
            ])
            ->assertStatus(201);
    }

    public function test_raising_a_dispute_locks_the_milestone(): void
    {
        ['client' => $client, 'freelancer' => $freelancer, 'milestone' => $milestone]
            = $this->activeContractWithMilestone('submitted');

        // Raise dispute
        $this->actingAs($client)->postJson("/api/v1/milestones/{$milestone->id}/dispute", [
            'reason' => 'Quality issues.',
        ])->assertStatus(201);

        // Milestone is now 'disputed' — client cannot approve it
        $this->actingAs($client)
            ->postJson("/api/v1/milestones/{$milestone->id}/approve")
            ->assertStatus(422);

        // Freelancer cannot re-submit it either
        $this->actingAs($freelancer)
            ->postJson("/api/v1/milestones/{$milestone->id}/submit")
            ->assertStatus(422);
    }

    public function test_cannot_open_duplicate_dispute_on_same_milestone(): void
    {
        ['client' => $client, 'milestone' => $milestone, 'contract' => $contract]
            = $this->activeContractWithMilestone('submitted');

        // First dispute
        $this->actingAs($client)->postJson("/api/v1/milestones/{$milestone->id}/dispute", [
            'reason' => 'First issue.',
        ])->assertStatus(201);

        // Second dispute on the same milestone
        $this->actingAs($client)->postJson("/api/v1/milestones/{$milestone->id}/dispute", [
            'reason' => 'Another issue.',
        ])->assertStatus(422);
    }
}
