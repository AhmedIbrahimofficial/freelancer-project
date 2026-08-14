<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Dispute;
use App\Models\Milestone;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Tests for activity log entries created by signing, milestone approval, and dispute actions.
 *
 * spatie/laravel-activitylog is installed but activity() calls must be added to
 * the controllers/listeners for these assertions to pass. The tests define the
 * EXPECTED behaviour — run them after wiring up activity logging to confirm it's correct.
 *
 * Pattern to add in controllers/listeners:
 *
 *   activity('contracts')
 *       ->performedOn($contract)
 *       ->causedBy($user)
 *       ->withProperties(['signed_name' => $request->signed_name])
 *       ->log('signed');
 */
class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    // ── Contract signing ─────────────────────────────────────────────

    public function test_signing_a_contract_creates_an_activity_log_entry(): void
    {
        $client     = User::factory()->client()->create(['name' => 'Alice Johnson']);
        $freelancer = User::factory()->freelancer()->create(['name' => 'Bob Smith']);

        $contract = Contract::factory()->pendingSignature()->create([
            'client_id'    => $client->id,
            'freelancer_id' => $freelancer->id,
        ]);

        $this->actingAs($client)->postJson("/api/v1/contracts/{$contract->id}/sign", [
            'signed_name' => 'Alice Johnson',
        ]);

        $this->assertDatabaseHas('activity_log', [
            'log_name'     => 'contracts',
            'description'  => 'signed',
            'subject_type' => Contract::class,
            'subject_id'   => $contract->id,
            'causer_type'  => User::class,
            'causer_id'    => $client->id,
        ]);
    }

    public function test_both_signing_actions_each_create_a_log_entry(): void
    {
        $client     = User::factory()->client()->create(['name' => 'Alice Johnson']);
        $freelancer = User::factory()->freelancer()->create(['name' => 'Bob Smith']);

        $contract = Contract::factory()->pendingSignature()->create([
            'client_id'    => $client->id,
            'freelancer_id' => $freelancer->id,
        ]);

        $this->actingAs($client)->postJson("/api/v1/contracts/{$contract->id}/sign", [
            'signed_name' => 'Alice Johnson',
        ]);
        $this->actingAs($freelancer)->postJson("/api/v1/contracts/{$contract->id}/sign", [
            'signed_name' => 'Bob Smith',
        ]);

        $this->assertSame(
            2,
            Activity::where('description', 'signed')
                ->where('subject_type', Contract::class)
                ->where('subject_id', $contract->id)
                ->count()
        );
    }

    // ── Milestone approval ────────────────────────────────────────────

    public function test_approving_a_milestone_creates_an_activity_log_entry(): void
    {
        $client     = User::factory()->client()->create();
        $freelancer = User::factory()->freelancer()->create();

        $contract = Contract::factory()->active()->create([
            'client_id'    => $client->id,
            'freelancer_id' => $freelancer->id,
        ]);

        $milestone = Milestone::factory()->submitted()->create([
            'contract_id' => $contract->id,
        ]);

        $this->actingAs($client)
            ->postJson("/api/v1/milestones/{$milestone->id}/approve");

        $this->assertDatabaseHas('activity_log', [
            'log_name'     => 'milestones',
            'description'  => 'approved',
            'subject_type' => Milestone::class,
            'subject_id'   => $milestone->id,
            'causer_type'  => User::class,
            'causer_id'    => $client->id,
        ]);
    }

    // ── Dispute raised ────────────────────────────────────────────────

    public function test_raising_a_dispute_creates_an_activity_log_entry(): void
    {
        $client     = User::factory()->client()->create();
        $freelancer = User::factory()->freelancer()->create();

        $contract = Contract::factory()->active()->create([
            'client_id'    => $client->id,
            'freelancer_id' => $freelancer->id,
        ]);

        $milestone = Milestone::factory()->submitted()->create([
            'contract_id' => $contract->id,
        ]);

        $this->actingAs($client)->postJson("/api/v1/milestones/{$milestone->id}/dispute", [
            'reason' => 'Work does not meet the spec.',
        ]);

        $this->assertDatabaseHas('activity_log', [
            'log_name'    => 'disputes',
            'description' => 'raised',
            'causer_type' => User::class,
            'causer_id'   => $client->id,
        ]);
    }

    // ── Dispute resolved ──────────────────────────────────────────────

    public function test_resolving_a_dispute_creates_an_activity_log_entry(): void
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
            'reason'       => 'Dispute reason.',
        ]);

        $this->actingAs($admin)->patchJson("/api/v1/disputes/{$dispute->id}/resolve", [
            'status'           => 'resolved_client',
            'resolution_notes' => 'Client prevails.',
        ]);

        $this->assertDatabaseHas('activity_log', [
            'log_name'     => 'disputes',
            'description'  => 'resolved',
            'subject_type' => Dispute::class,
            'subject_id'   => $dispute->id,
            'causer_type'  => User::class,
            'causer_id'    => $admin->id,
        ]);
    }

    // ── Milestone submitted ───────────────────────────────────────────

    public function test_submitting_a_milestone_creates_an_activity_log_entry(): void
    {
        $client     = User::factory()->client()->create();
        $freelancer = User::factory()->freelancer()->create();

        $contract = Contract::factory()->active()->create([
            'client_id'    => $client->id,
            'freelancer_id' => $freelancer->id,
        ]);

        $milestone = Milestone::factory()->pending()->create([
            'contract_id' => $contract->id,
        ]);

        $this->actingAs($freelancer)
            ->postJson("/api/v1/milestones/{$milestone->id}/submit", [
                'submission_notes' => 'All done.',
            ]);

        $this->assertDatabaseHas('activity_log', [
            'log_name'     => 'milestones',
            'description'  => 'submitted',
            'subject_type' => Milestone::class,
            'subject_id'   => $milestone->id,
            'causer_type'  => User::class,
            'causer_id'    => $freelancer->id,
        ]);
    }
}
