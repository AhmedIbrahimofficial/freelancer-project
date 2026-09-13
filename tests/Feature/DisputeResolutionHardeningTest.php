<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Dispute;
use App\Models\EscrowBalance;
use App\Models\Milestone;
use App\Models\PaymentAccount;
use App\Models\Transaction;
use App\Models\User;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Stripe\Refund;
use Stripe\Transfer;
use Tests\TestCase;

/**
 * Final hardening tests covering:
 *  - execute-resolution: refund, transfer, split, idempotency, authorization
 *  - splitAmounts() precision and edge cases
 *  - availableAmount() BCMath precision
 *  - release() dispute guard and Stripe failure handling
 *  - NotifyMilestoneApproved dispute guard
 *  - Webhook idempotency (charge.refunded, transfer.created, duplicate delivery)
 *  - Concurrency (sequential simulation with state inspection)
 *  - Phase 8: refund path does not require freelancer account
 */
class DisputeResolutionHardeningTest extends TestCase
{
    use RefreshDatabase;

    // ── Shared fixture helpers ────────────────────────────────────────────────

    private function makeAdmin(): User
    {
        return User::factory()->admin()->create();
    }

    private function makeClient(): User
    {
        return User::factory()->client()->create();
    }

    private function makeFreelancer(bool $withStripeAccount = true): User
    {
        $freelancer = User::factory()->freelancer()->create();

        if ($withStripeAccount) {
            PaymentAccount::create([
                'user_id'           => $freelancer->id,
                'stripe_account_id' => 'acct_test_' . $freelancer->id,
                'status'            => 'active',
                'payout_enabled'    => true,
                'charges_enabled'   => true,
            ]);
        }

        return $freelancer;
    }

    /**
     * Build a full dispute scenario ready for execute-resolution.
     * Returns all relevant models.
     */
    private function resolvedDispute(
        string $resolution = 'resolved_client',
        ?float $freelancerPercent = null,
        string $paymentIntentId = 'pi_test_escrow',
        float  $heldAmount = 100.00,
    ): array {
        $client     = $this->makeClient();
        $freelancer = $this->makeFreelancer();
        $admin      = $this->makeAdmin();

        $contract = Contract::factory()->active()->create([
            'client_id'     => $client->id,
            'freelancer_id' => $freelancer->id,
            'total_amount'  => $heldAmount,
            'currency'      => 'USD',
        ]);

        $milestone = Milestone::factory()->create([
            'contract_id' => $contract->id,
            'amount'      => $heldAmount,
            'status'      => 'disputed',
        ]);

        $escrow = EscrowBalance::create([
            'contract_id'              => $contract->id,
            'held_amount'              => $heldAmount,
            'released_amount'          => 0,
            'refunded_amount'          => 0,
            'currency'                 => 'USD',
            'status'                   => 'funded',
            'stripe_payment_intent_id' => $paymentIntentId,
        ]);

        $disputeData = [
            'contract_id'      => $contract->id,
            'milestone_id'     => $milestone->id,
            'raised_by'        => $client->id,
            'status'           => $resolution,
            'reason'           => 'Test dispute.',
            'resolution_notes' => 'Admin resolved.',
            'resolved_at'      => now(),
        ];

        if ($resolution === 'resolved_split' && $freelancerPercent !== null) {
            $disputeData['split_freelancer_percent'] = $freelancerPercent;
            $disputeData['split_state']              = 'pending';
        }

        $dispute = Dispute::create($disputeData);

        return compact('client', 'freelancer', 'admin', 'contract', 'milestone', 'escrow', 'dispute');
    }

    private function fakeTransfer(string $id = 'tr_hardening_001'): Transfer
    {
        return Transfer::constructFrom([
            'id'       => $id,
            'object'   => 'transfer',
            'amount'   => 10000,
            'currency' => 'usd',
        ]);
    }

    private function fakeRefund(string $id = 're_hardening_001'): Refund
    {
        return Refund::constructFrom([
            'id'     => $id,
            'object' => 'refund',
            'amount' => 10000,
            'status' => 'succeeded',
        ]);
    }

    private function buildFakeStripeEvent(string $type, array $objectData): \Stripe\Event
    {
        return \Stripe\Event::constructFrom([
            'id'       => 'evt_harden_' . uniqid(),
            'object'   => 'event',
            'type'     => $type,
            'data'     => ['object' => $objectData],
            'livemode' => false,
        ]);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // PHASE 4A — execute-resolution: full REFUND path
    // ═════════════════════════════════════════════════════════════════════════

    public function test_execute_resolution_full_refund_creates_transaction_and_marks_milestone_released(): void
    {
        ['admin' => $admin, 'dispute' => $dispute, 'escrow' => $escrow, 'milestone' => $milestone,
         'contract' => $contract] = $this->resolvedDispute('resolved_client');

        $stripe = $this->mock(StripeService::class);
        $stripe->shouldReceive('refundPaymentIntent')
            ->once()
            ->with('pi_test_escrow', 10000, \Mockery::type('string'))
            ->andReturn($this->fakeRefund('re_refund_full_001'));

        $response = $this->actingAs($admin)
            ->postJson("/api/v1/disputes/{$dispute->id}/execute-resolution");

        $response->assertOk()
            ->assertJsonPath('action', 'refund')
            ->assertJsonPath('refund_id', 're_refund_full_001');

        $this->assertDatabaseHas('transactions', [
            'contract_id'      => $contract->id,
            'type'             => 'refund',
            'stripe_reference' => 're_refund_full_001',
            'status'           => 'pending',
        ]);

        $this->assertDatabaseHas('milestones', [
            'id'     => $milestone->id,
            'status' => 'released',
        ]);

        $this->assertDatabaseHas('disputes', [
            'id'                         => $dispute->id,
            'resolution_stripe_reference' => 're_refund_full_001',
        ]);

        $this->assertNotNull($dispute->fresh()->resolution_executed_at);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // PHASE 4B — execute-resolution: full TRANSFER path
    // ═════════════════════════════════════════════════════════════════════════

    public function test_execute_resolution_full_transfer_creates_transaction_and_marks_milestone_released(): void
    {
        ['admin' => $admin, 'dispute' => $dispute, 'escrow' => $escrow, 'milestone' => $milestone,
         'contract' => $contract, 'freelancer' => $freelancer] = $this->resolvedDispute('resolved_freelancer');

        $stripe = $this->mock(StripeService::class);
        $stripe->shouldReceive('createTransfer')
            ->once()
            ->with(10000, 'USD', 'acct_test_' . $freelancer->id, $milestone->id, \Mockery::type('string'))
            ->andReturn($this->fakeTransfer('tr_transfer_full_001'));

        $response = $this->actingAs($admin)
            ->postJson("/api/v1/disputes/{$dispute->id}/execute-resolution");

        $response->assertOk()
            ->assertJsonPath('action', 'transfer')
            ->assertJsonPath('transfer_id', 'tr_transfer_full_001');

        $this->assertDatabaseHas('transactions', [
            'contract_id'        => $contract->id,
            'type'               => 'release',
            'stripe_transfer_id' => 'tr_transfer_full_001',
            'status'             => 'pending',
        ]);

        $this->assertDatabaseHas('milestones', [
            'id'     => $milestone->id,
            'status' => 'released',
        ]);

        $this->assertNotNull($dispute->fresh()->resolution_executed_at);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // PHASE 4C — execute-resolution: SPLIT path
    // ═════════════════════════════════════════════════════════════════════════

    public function test_execute_resolution_split_70_30_creates_both_transactions(): void
    {
        ['admin' => $admin, 'dispute' => $dispute, 'escrow' => $escrow, 'contract' => $contract,
         'milestone' => $milestone, 'freelancer' => $freelancer]
            = $this->resolvedDispute('resolved_split', 70, 'pi_split_escrow', 100.00);

        $stripe = $this->mock(StripeService::class);
        $stripe->shouldReceive('createTransfer')
            ->once()
            ->with(7000, 'USD', 'acct_test_' . $freelancer->id, $milestone->id, \Mockery::type('string'))
            ->andReturn($this->fakeTransfer('tr_split_070'));

        $stripe->shouldReceive('refundPaymentIntent')
            ->once()
            ->with('pi_split_escrow', 3000, \Mockery::type('string'))
            ->andReturn($this->fakeRefund('re_split_030'));

        $response = $this->actingAs($admin)
            ->postJson("/api/v1/disputes/{$dispute->id}/execute-resolution");

        $response->assertOk()
            ->assertJsonPath('action', 'split')
            ->assertJsonPath('freelancer_percent', '70.00')
            ->assertJsonPath('transfer_id', 'tr_split_070')
            ->assertJsonPath('refund_id', 're_split_030')
            ->assertJsonPath('freelancer_amount', 70)
            ->assertJsonPath('client_amount', 30);

        // Both transactions created
        $this->assertDatabaseHas('transactions', [
            'contract_id'        => $contract->id,
            'type'               => 'release',
            'stripe_transfer_id' => 'tr_split_070',
        ]);
        $this->assertDatabaseHas('transactions', [
            'contract_id'      => $contract->id,
            'type'             => 'refund',
            'stripe_reference' => 're_split_030',
        ]);

        // Escrow accounting: released=70, refunded=30
        $escrow->refresh();
        $this->assertEquals('70.00', $escrow->released_amount);
        $this->assertEquals('30.00', $escrow->refunded_amount);
        $this->assertEquals('0.00', $escrow->availableAmount());

        // Split state = complete
        $this->assertDatabaseHas('disputes', [
            'id'          => $dispute->id,
            'split_state' => 'complete',
        ]);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // PHASE 4D — execute-resolution: idempotency
    // ═════════════════════════════════════════════════════════════════════════

    public function test_execute_resolution_second_call_returns_cached_result_without_calling_stripe(): void
    {
        ['admin' => $admin, 'dispute' => $dispute] = $this->resolvedDispute('resolved_client');

        $stripe = $this->mock(StripeService::class);
        // Stripe MUST only be called once across both HTTP requests
        $stripe->shouldReceive('refundPaymentIntent')
            ->once()
            ->andReturn($this->fakeRefund('re_idempotent_001'));

        // First call
        $this->actingAs($admin)
            ->postJson("/api/v1/disputes/{$dispute->id}/execute-resolution")
            ->assertOk();

        // Second call — must NOT call Stripe again
        $response = $this->actingAs($admin)
            ->postJson("/api/v1/disputes/{$dispute->id}/execute-resolution");

        $response->assertOk()
            ->assertJsonPath('message', 'Resolution already executed. No action taken.');

        // Only one refund transaction must exist
        $this->assertEquals(
            1,
            Transaction::where('contract_id', $dispute->contract_id)->where('type', 'refund')->count()
        );
    }

    // ═════════════════════════════════════════════════════════════════════════
    // PHASE 4E — execute-resolution: non-admin gets 403
    // ═════════════════════════════════════════════════════════════════════════

    public function test_non_admin_cannot_execute_resolution(): void
    {
        ['client' => $client, 'freelancer' => $freelancer, 'dispute' => $dispute]
            = $this->resolvedDispute('resolved_client');

        $this->mock(StripeService::class)
            ->shouldNotReceive('refundPaymentIntent');

        $this->actingAs($client)
            ->postJson("/api/v1/disputes/{$dispute->id}/execute-resolution")
            ->assertStatus(403);

        $this->actingAs($freelancer)
            ->postJson("/api/v1/disputes/{$dispute->id}/execute-resolution")
            ->assertStatus(403);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // PHASE 4F — Concurrency: sequential simulation with state inspection
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * True concurrent HTTP requests cannot be simulated in a single PHP process.
     * This test verifies the atomic execution claim mechanism behaviorally:
     *
     * The 'claiming' state is written atomically in a short DB transaction BEFORE
     * Stripe is called. A second request that arrives while the first is 'claiming'
     * receives a 409 immediately — without waiting for Stripe.
     *
     * The second scenario (first completes before second arrives) is tested by
     * the idempotency test: the 'complete' execution_state causes an early 200 return.
     *
     * What cannot be verified in a single-process test:
     *  - Two goroutine/process-level simultaneous DB lock acquisitions
     *
     * The lockForUpdate() + claiming state combination means:
     *  1. Only one process can write 'claiming' — the lock enforces this on PostgreSQL/MySQL
     *  2. After lock release, 'claiming' is visible to all subsequent requests
     *  3. If the process crashes mid-Stripe, 'claiming' remains visible as a stuck state
     *     (better than silent duplication)
     */
    public function test_concurrent_execution_claim_prevents_second_stripe_call(): void
    {
        ['admin' => $admin, 'dispute' => $dispute] = $this->resolvedDispute('resolved_client');

        // Stripe must NOT be called — the claiming guard returns 409 before reaching Stripe
        $stripe = $this->mock(StripeService::class);
        $stripe->shouldNotReceive('refundPaymentIntent');
        $stripe->shouldNotReceive('createTransfer');

        // Simulate: another process has atomically claimed execution (written 'claiming')
        $dispute->update(['execution_state' => 'claiming']);

        $response = $this->actingAs($admin)
            ->postJson("/api/v1/disputes/{$dispute->id}/execute-resolution");

        // Second request sees 'claiming' → 409, no Stripe call
        $response->assertStatus(409)
            ->assertJsonPath('status', 'claiming');

        // No transaction created
        $this->assertDatabaseMissing('transactions', [
            'contract_id' => $dispute->contract_id,
            'type'        => 'refund',
        ]);

        // Dispute still in 'claiming' state — not falsely marked complete
        $this->assertDatabaseHas('disputes', [
            'id'              => $dispute->id,
            'execution_state' => 'claiming',
        ]);
    }

    public function test_second_execute_resolution_after_first_completes_calls_stripe_exactly_once(): void
    {
        ['admin' => $admin, 'dispute' => $dispute] = $this->resolvedDispute('resolved_client');

        $stripe = $this->mock(StripeService::class);
        $stripe->shouldReceive('refundPaymentIntent')
            ->once() // exactly once across all calls
            ->andReturn($this->fakeRefund('re_concurrency_001'));

        // Simulate "first request completes"
        $this->actingAs($admin)
            ->postJson("/api/v1/disputes/{$dispute->id}/execute-resolution")
            ->assertOk();

        // Simulate "second concurrent request arrives after first completed"
        $this->actingAs($admin)
            ->postJson("/api/v1/disputes/{$dispute->id}/execute-resolution")
            ->assertOk()
            ->assertJsonPath('message', 'Resolution already executed. No action taken.');

        // Verify DB: exactly one refund transaction
        $this->assertEquals(
            1,
            Transaction::where('contract_id', $dispute->contract_id)->where('type', 'refund')->count()
        );

        // Verify DB: resolution_executed_at set exactly once (same timestamp, one record)
        $this->assertEquals(1, Dispute::where('id', $dispute->id)
            ->whereNotNull('resolution_executed_at')
            ->count());
    }

    // ═════════════════════════════════════════════════════════════════════════
    // PHASE 4G — Split validation
    // ═════════════════════════════════════════════════════════════════════════

    public function test_resolved_split_requires_freelancer_percent(): void
    {
        ['admin' => $admin, 'dispute' => $dispute] = $this->resolvedDispute('open');
        // Reset to open so we can test resolve endpoint
        $dispute->update(['status' => 'open', 'resolved_at' => null]);

        $this->actingAs($admin)
            ->patchJson("/api/v1/disputes/{$dispute->id}/resolve", [
                'status'           => 'resolved_split',
                'resolution_notes' => 'No percent provided.',
                // freelancer_percent intentionally absent
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('freelancer_percent');
    }

    public function test_resolved_split_rejects_null_freelancer_percent(): void
    {
        ['admin' => $admin, 'dispute' => $dispute] = $this->resolvedDispute('open');
        $dispute->update(['status' => 'open', 'resolved_at' => null]);

        $this->actingAs($admin)
            ->patchJson("/api/v1/disputes/{$dispute->id}/resolve", [
                'status'             => 'resolved_split',
                'resolution_notes'   => 'Null percent.',
                'freelancer_percent' => null,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('freelancer_percent');
    }

    public function test_resolved_split_rejects_zero_percent(): void
    {
        ['admin' => $admin, 'dispute' => $dispute] = $this->resolvedDispute('open');
        $dispute->update(['status' => 'open', 'resolved_at' => null]);

        $this->actingAs($admin)
            ->patchJson("/api/v1/disputes/{$dispute->id}/resolve", [
                'status'             => 'resolved_split',
                'resolution_notes'   => 'Zero percent.',
                'freelancer_percent' => 0,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('freelancer_percent');
    }

    public function test_resolved_split_rejects_100_percent(): void
    {
        ['admin' => $admin, 'dispute' => $dispute] = $this->resolvedDispute('open');
        $dispute->update(['status' => 'open', 'resolved_at' => null]);

        $this->actingAs($admin)
            ->patchJson("/api/v1/disputes/{$dispute->id}/resolve", [
                'status'             => 'resolved_split',
                'resolution_notes'   => '100 percent.',
                'freelancer_percent' => 100,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('freelancer_percent');
    }

    public function test_resolved_split_rejects_negative_percent(): void
    {
        ['admin' => $admin, 'dispute' => $dispute] = $this->resolvedDispute('open');
        $dispute->update(['status' => 'open', 'resolved_at' => null]);

        $this->actingAs($admin)
            ->patchJson("/api/v1/disputes/{$dispute->id}/resolve", [
                'status'             => 'resolved_split',
                'resolution_notes'   => 'Negative.',
                'freelancer_percent' => -10,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('freelancer_percent');
    }

    public function test_resolved_split_rejects_malformed_string_percent(): void
    {
        ['admin' => $admin, 'dispute' => $dispute] = $this->resolvedDispute('open');
        $dispute->update(['status' => 'open', 'resolved_at' => null]);

        $this->actingAs($admin)
            ->patchJson("/api/v1/disputes/{$dispute->id}/resolve", [
                'status'             => 'resolved_split',
                'resolution_notes'   => 'Malformed.',
                'freelancer_percent' => 'seventy',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('freelancer_percent');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('validSplitPercentages')]
    public function test_resolved_split_accepts_valid_percentages(int|float $percent): void
    {
        ['admin' => $admin, 'dispute' => $dispute] = $this->resolvedDispute('open');
        $dispute->update(['status' => 'open', 'resolved_at' => null]);

        $this->actingAs($admin)
            ->patchJson("/api/v1/disputes/{$dispute->id}/resolve", [
                'status'             => 'resolved_split',
                'resolution_notes'   => "Valid percent: {$percent}",
                'freelancer_percent' => $percent,
            ])
            ->assertOk();
    }

    public static function validSplitPercentages(): array
    {
        return [
            'minimum 1%'     => [1],
            'even 50%'       => [50],
            'common 70%'     => [70],
            'maximum 99%'    => [99],
            'decimal 66.67%' => [66.67],
            'decimal 33.33%' => [33.33],
        ];
    }

    // ═════════════════════════════════════════════════════════════════════════
    // PHASE 4H — splitAmounts() unit tests
    // ═════════════════════════════════════════════════════════════════════════

    private function splitDispute(float $percent): Dispute
    {
        return Dispute::make(['split_freelancer_percent' => $percent]);
    }

    public function test_split_amounts_70_30(): void
    {
        $amounts = $this->splitDispute(70)->splitAmounts(10000);
        $this->assertSame(7000, $amounts['freelancer_cents']);
        $this->assertSame(3000, $amounts['client_cents']);
        $this->assertSame(10000, $amounts['freelancer_cents'] + $amounts['client_cents']);
    }

    public function test_split_amounts_50_50(): void
    {
        $amounts = $this->splitDispute(50)->splitAmounts(10000);
        $this->assertSame(5000, $amounts['freelancer_cents']);
        $this->assertSame(5000, $amounts['client_cents']);
        $this->assertSame(10000, $amounts['freelancer_cents'] + $amounts['client_cents']);
    }

    public function test_split_amounts_1_99(): void
    {
        $amounts = $this->splitDispute(1)->splitAmounts(10000);
        $this->assertSame(100, $amounts['freelancer_cents']);
        $this->assertSame(9900, $amounts['client_cents']);
        $this->assertSame(10000, $amounts['freelancer_cents'] + $amounts['client_cents']);
    }

    public function test_split_amounts_99_1(): void
    {
        $amounts = $this->splitDispute(99)->splitAmounts(10000);
        $this->assertSame(9900, $amounts['freelancer_cents']);
        $this->assertSame(100, $amounts['client_cents']);
        $this->assertSame(10000, $amounts['freelancer_cents'] + $amounts['client_cents']);
    }

    public function test_split_amounts_rounding_floor_gives_client_the_extra_cent(): void
    {
        // 33.33% of 1000 cents = 333.3 cents → floor = 333
        // client gets 667 (the extra cent goes to client, not freelancer)
        $amounts = $this->splitDispute(33.33)->splitAmounts(1000);
        $this->assertSame(333, $amounts['freelancer_cents']);
        $this->assertSame(667, $amounts['client_cents']);
        $this->assertSame(1000, $amounts['freelancer_cents'] + $amounts['client_cents']);
    }

    public function test_split_amounts_total_always_preserved(): void
    {
        // Test many percentages — total must always equal input
        $totals = [1, 99, 100, 333, 1000, 9999, 10000, 10001];
        $percents = [1, 10, 33.33, 50, 66.67, 70, 99];

        foreach ($totals as $total) {
            foreach ($percents as $percent) {
                $amounts = $this->splitDispute($percent)->splitAmounts($total);
                $this->assertSame(
                    $total,
                    $amounts['freelancer_cents'] + $amounts['client_cents'],
                    "Total not preserved for {$percent}% of {$total} cents"
                );
            }
        }
    }

    public function test_split_amounts_tiny_amount_1_percent_of_1_cent_is_zero_for_freelancer(): void
    {
        // 1% of 1 cent = floor(0.01) = 0.
        // Pure math: freelancer gets 0, client gets 1.
        // There is NO minimum-cent override — that would violate the stated percentage.
        // If an admin splits 1 cent with 1% to freelancer, the result is 0 to freelancer
        // and the full 1 cent refunded to client.
        $amounts = $this->splitDispute(1)->splitAmounts(1);
        $this->assertSame(0, $amounts['freelancer_cents']);
        $this->assertSame(1, $amounts['client_cents']);
        $this->assertSame(1, $amounts['freelancer_cents'] + $amounts['client_cents']);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // PHASE 4I — release() blocked when dispute exists
    // ═════════════════════════════════════════════════════════════════════════

    public function test_release_is_blocked_when_milestone_has_any_dispute(): void
    {
        $client     = $this->makeClient();
        $freelancer = $this->makeFreelancer();

        $contract = Contract::factory()->active()->create([
            'client_id'     => $client->id,
            'freelancer_id' => $freelancer->id,
        ]);

        $milestone = Milestone::factory()->create([
            'contract_id' => $contract->id,
            'amount'      => 100.00,
            'status'      => 'approved', // approved but has a dispute
        ]);

        Dispute::create([
            'contract_id'  => $contract->id,
            'milestone_id' => $milestone->id,
            'raised_by'    => $client->id,
            'status'       => 'resolved_client', // even if resolved, client cannot release
            'reason'       => 'Test.',
            'resolution_notes' => 'Resolved.',
            'resolved_at'  => now(),
        ]);

        $this->mock(StripeService::class)->shouldNotReceive('createTransfer');

        $this->actingAs($client)
            ->postJson("/api/v1/milestones/{$milestone->id}/release")
            ->assertStatus(422);

        // No transfer transaction created
        $this->assertDatabaseMissing('transactions', [
            'contract_id' => $contract->id,
            'type'        => 'release',
        ]);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // PHASE 4J — release() Stripe failure => 502, no DB record
    // ═════════════════════════════════════════════════════════════════════════

    public function test_release_returns_502_and_creates_no_transaction_when_stripe_fails(): void
    {
        $client     = $this->makeClient();
        $freelancer = $this->makeFreelancer();

        $contract = Contract::factory()->active()->create([
            'client_id'     => $client->id,
            'freelancer_id' => $freelancer->id,
        ]);

        $milestone = Milestone::factory()->create([
            'contract_id' => $contract->id,
            'amount'      => 100.00,
            'status'      => 'approved',
        ]);

        $stripe = $this->mock(StripeService::class);
        $stripe->shouldReceive('createTransfer')
            ->once()
            ->andThrow(\Stripe\Exception\InvalidRequestException::factory(
                'Insufficient funds in Stripe balance.'
            ));

        $response = $this->actingAs($client)
            ->postJson("/api/v1/milestones/{$milestone->id}/release");

        $response->assertStatus(502);

        // DB must be clean — no transfer transaction
        $this->assertDatabaseMissing('transactions', [
            'contract_id' => $contract->id,
            'type'        => 'release',
        ]);

        // Milestone must still be 'approved' — not changed on failure
        $this->assertDatabaseHas('milestones', [
            'id'     => $milestone->id,
            'status' => 'approved',
        ]);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // PHASE 4K — NotifyMilestoneApproved skips disputed milestone
    // ═════════════════════════════════════════════════════════════════════════

    public function test_auto_release_listener_skips_milestone_with_existing_dispute(): void
    {
        $client     = $this->makeClient();
        $freelancer = $this->makeFreelancer();

        $contract = Contract::factory()->active()->create([
            'client_id'     => $client->id,
            'freelancer_id' => $freelancer->id,
        ]);

        $milestone = Milestone::factory()->create([
            'contract_id' => $contract->id,
            'amount'      => 100.00,
            'status'      => 'submitted',
        ]);

        // Create a dispute on this milestone
        Dispute::create([
            'contract_id'  => $contract->id,
            'milestone_id' => $milestone->id,
            'raised_by'    => $client->id,
            'status'       => 'open',
            'reason'       => 'Dispute before approval.',
        ]);

        // Stripe must NOT be called
        $stripe = $this->mock(StripeService::class);
        $stripe->shouldReceive('isConfigured')->andReturn(true)->byDefault();
        $stripe->shouldNotReceive('createTransfer');

        // Force milestone to 'approved' to trigger the listener
        $milestone->update(['status' => 'approved', 'approved_at' => now()]);

        // Dispatch the MilestoneApproved event directly
        \App\Events\MilestoneApproved::dispatch($milestone->fresh());

        // No release transaction
        $this->assertDatabaseMissing('transactions', [
            'contract_id' => $contract->id,
            'type'        => 'release',
        ]);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // PHASE 4L — charge.refunded webhook
    // ═════════════════════════════════════════════════════════════════════════

    public function test_charge_refunded_webhook_marks_pending_refund_transaction_completed(): void
    {
        $client   = $this->makeClient();
        $contract = Contract::factory()->active()->create(['client_id' => $client->id]);

        Transaction::create([
            'contract_id'      => $contract->id,
            'initiated_by'     => $client->id,
            'type'             => 'refund',
            'amount'           => 50.00,
            'currency'         => 'USD',
            'stripe_reference' => 're_webhook_001',
            'status'           => 'pending',
        ]);

        $mock = $this->mock(StripeService::class);
        $mock->shouldReceive('constructWebhookEvent')
            ->once()
            ->andReturn($this->buildFakeStripeEvent('charge.refunded', [
                'id'      => 'ch_test_001',
                'object'  => 'charge',
                'refunds' => [
                    'object' => 'list',
                    'data'   => [
                        ['id' => 're_webhook_001', 'object' => 'refund', 'amount' => 5000],
                    ],
                ],
            ]));

        $this->postJson('/api/v1/webhooks/stripe', [], ['Stripe-Signature' => 'test'])
            ->assertOk();

        $this->assertDatabaseHas('transactions', [
            'stripe_reference' => 're_webhook_001',
            'status'           => 'completed',
        ]);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // PHASE 4M — duplicate webhook delivery is idempotent
    // ═════════════════════════════════════════════════════════════════════════

    public function test_duplicate_transfer_created_webhook_does_not_double_update(): void
    {
        $client   = $this->makeClient();
        $contract = Contract::factory()->active()->create(['client_id' => $client->id]);
        $milestone = Milestone::factory()->create(['contract_id' => $contract->id]);

        Transaction::create([
            'contract_id'        => $contract->id,
            'milestone_id'       => $milestone->id,
            'initiated_by'       => $client->id,
            'type'               => 'release',
            'amount'             => 100.00,
            'currency'           => 'USD',
            'stripe_transfer_id' => 'tr_dup_001',
            'status'             => 'pending',
        ]);

        $event = $this->buildFakeStripeEvent('transfer.created', [
            'id'     => 'tr_dup_001',
            'object' => 'transfer',
            'amount' => 10000,
        ]);

        $mock = $this->mock(StripeService::class);
        $mock->shouldReceive('constructWebhookEvent')->twice()->andReturn($event);

        // First delivery
        $this->postJson('/api/v1/webhooks/stripe', [], ['Stripe-Signature' => 'x'])->assertOk();
        // Second delivery (duplicate/retry)
        $this->postJson('/api/v1/webhooks/stripe', [], ['Stripe-Signature' => 'x'])->assertOk();

        // Status is still 'completed' — not double-updated to something else
        $this->assertDatabaseHas('transactions', [
            'stripe_transfer_id' => 'tr_dup_001',
            'status'             => 'completed',
        ]);

        // Exactly one transaction record
        $this->assertEquals(
            1,
            Transaction::where('stripe_transfer_id', 'tr_dup_001')->count()
        );
    }

    public function test_duplicate_charge_refunded_webhook_does_not_create_extra_transactions(): void
    {
        $client   = $this->makeClient();
        $contract = Contract::factory()->active()->create(['client_id' => $client->id]);

        Transaction::create([
            'contract_id'      => $contract->id,
            'initiated_by'     => $client->id,
            'type'             => 'refund',
            'amount'           => 100.00,
            'currency'         => 'USD',
            'stripe_reference' => 're_dup_001',
            'status'           => 'pending',
        ]);

        $chargeData = [
            'id'      => 'ch_dup_test',
            'object'  => 'charge',
            'refunds' => [
                'object' => 'list',
                'data'   => [['id' => 're_dup_001', 'object' => 'refund', 'amount' => 10000]],
            ],
        ];

        $event = $this->buildFakeStripeEvent('charge.refunded', $chargeData);
        $mock  = $this->mock(StripeService::class);
        $mock->shouldReceive('constructWebhookEvent')->twice()->andReturn($event);

        $this->postJson('/api/v1/webhooks/stripe', [], ['Stripe-Signature' => 'x'])->assertOk();
        $this->postJson('/api/v1/webhooks/stripe', [], ['Stripe-Signature' => 'x'])->assertOk();

        // Still exactly one transaction — no duplicate created
        $this->assertEquals(1, Transaction::where('stripe_reference', 're_dup_001')->count());
        $this->assertDatabaseHas('transactions', [
            'stripe_reference' => 're_dup_001',
            'status'           => 'completed',
        ]);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // PHASE 4N — transfer.created webhook (already in StripeEscrowFlowTest,
    //            this confirms the status filter works correctly)
    // ═════════════════════════════════════════════════════════════════════════

    public function test_transfer_created_webhook_only_updates_matching_transaction(): void
    {
        $client   = $this->makeClient();
        $contract = Contract::factory()->active()->create(['client_id' => $client->id]);
        $milestone = Milestone::factory()->create(['contract_id' => $contract->id]);

        // A pending transaction we want updated
        Transaction::create([
            'contract_id'        => $contract->id,
            'milestone_id'       => $milestone->id,
            'initiated_by'       => $client->id,
            'type'               => 'release',
            'amount'             => 100.00,
            'currency'           => 'USD',
            'stripe_transfer_id' => 'tr_target_001',
            'status'             => 'pending',
        ]);

        // An unrelated transaction that must NOT be updated
        Transaction::create([
            'contract_id'        => $contract->id,
            'milestone_id'       => $milestone->id,
            'initiated_by'       => $client->id,
            'type'               => 'release',
            'amount'             => 200.00,
            'currency'           => 'USD',
            'stripe_transfer_id' => 'tr_other_001',
            'status'             => 'pending',
        ]);

        $mock = $this->mock(StripeService::class);
        $mock->shouldReceive('constructWebhookEvent')->once()->andReturn(
            $this->buildFakeStripeEvent('transfer.created', [
                'id'     => 'tr_target_001',
                'object' => 'transfer',
                'amount' => 10000,
            ])
        );

        $this->postJson('/api/v1/webhooks/stripe', [], ['Stripe-Signature' => 'x'])->assertOk();

        // Only the target transaction updated
        $this->assertDatabaseHas('transactions', ['stripe_transfer_id' => 'tr_target_001', 'status' => 'completed']);
        $this->assertDatabaseHas('transactions', ['stripe_transfer_id' => 'tr_other_001', 'status' => 'pending']);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // PHASE 6 — Failure recovery scenarios
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Stripe transfer succeeds → DB write fails.
     *
     * LIMITATION: This scenario cannot be fully reproduced in a feature test
     * using RefreshDatabase + SQLite because mocking DB::transaction() would
     * prevent all other DB operations in the same request (including Eloquent
     * model fetches that happen before the Stripe call).
     *
     * What IS verified:
     * - The system DOES call Stripe (confirmed by mock)
     * - The idempotency key prevents a second Stripe call on retry
     *
     * What CANNOT be verified in this test:
     * - Log::critical fires with the transfer_id
     * - The 500 response contains the transfer_id
     *
     * Production verification:
     * - Integration test with real PostgreSQL
     * - Inject a mock that throws after Stripe succeeds inside the DB::transaction closure
     * - Monitor Sentry/logs for the "manual reconciliation required" message
     *
     * The code path (DisputeController::executeResolution transfer action) IS covered by
     * PHASE 4B (happy path) and PHASE 4D (idempotency). The DB failure branch is
     * a documented gap that requires a real database with transactional semantics.
     */
    public function test_execute_resolution_idempotency_key_prevents_duplicate_stripe_call_on_retry(): void
    {
        // Simulates: first call succeeds (Stripe + DB), retry returns cached result.
        // Stripe MUST only be called once even if the endpoint is called twice.
        ['admin' => $admin, 'dispute' => $dispute] = $this->resolvedDispute('resolved_freelancer');

        $stripe = $this->mock(StripeService::class);
        $stripe->shouldReceive('createTransfer')
            ->once() // called exactly once, never on retry
            ->andReturn($this->fakeTransfer('tr_idempotent_002'));

        // First call
        $this->actingAs($admin)
            ->postJson("/api/v1/disputes/{$dispute->id}/execute-resolution")
            ->assertOk();

        // Retry — Stripe MUST NOT be called again (idempotency guard)
        $this->actingAs($admin)
            ->postJson("/api/v1/disputes/{$dispute->id}/execute-resolution")
            ->assertOk()
            ->assertJsonPath('message', 'Resolution already executed. No action taken.');
    }

    /**
     * Split: transfer succeeds, refund fails → partial_failure state.
     * Retry must skip the transfer leg and only retry the refund.
     */
    public function test_split_partial_failure_retry_only_calls_refund_not_transfer(): void
    {
        ['admin' => $admin, 'dispute' => $dispute, 'milestone' => $milestone, 'freelancer' => $freelancer]
            = $this->resolvedDispute('resolved_split', 70, 'pi_partial_escrow', 100.00);

        // Use a call counter to make the first refund call throw, the second succeed.
        $refundCallCount = 0;
        $fakeRefund      = $this->fakeRefund('re_partial_retry_001');

        $stripe = $this->mock(StripeService::class);

        // Transfer called exactly once (first request). Never on retry.
        $stripe->shouldReceive('createTransfer')
            ->once()
            ->with(7000, 'USD', 'acct_test_' . $freelancer->id, $milestone->id, \Mockery::type('string'))
            ->andReturn($this->fakeTransfer('tr_partial_001'));

        // Refund: first call throws (→ partial_failure), retry returns refund.
        // On retry, the controller recovers original client_cents (3000) from the
        // split transfer transaction, not from availableAmount() which would be wrong.
        $stripe->shouldReceive('refundPaymentIntent')
            ->once()
            ->with('pi_partial_escrow', 3000, \Mockery::type('string'))
            ->andReturnUsing(function () use (&$refundCallCount) {
                $refundCallCount++;
                throw \Stripe\Exception\InvalidRequestException::factory('Refund failed on first attempt.');
            });

        $stripe->shouldReceive('refundPaymentIntent')
            ->once()
            ->with('pi_partial_escrow', 3000, \Mockery::type('string'))
            ->andReturnUsing(function () use (&$refundCallCount, $fakeRefund) {
                $refundCallCount++;
                return $fakeRefund;
            });

        // ── First call: transfer OK, refund fails ─────────────────────────────
        $firstResponse = $this->actingAs($admin)
            ->postJson("/api/v1/disputes/{$dispute->id}/execute-resolution");

        $firstResponse->assertStatus(502);

        $dispute->refresh();
        $this->assertEquals('partial_failure', $dispute->split_state);
        $this->assertEquals('tr_partial_001', $dispute->split_transfer_id);

        // ── Retry: only refund runs, transfer skipped ─────────────────────────
        $retryResponse = $this->actingAs($admin)
            ->postJson("/api/v1/disputes/{$dispute->id}/execute-resolution");

        // Debug: show full response if not 200
        if ($retryResponse->status() !== 200) {
            $this->fail('Retry returned ' . $retryResponse->status() . ': ' . $retryResponse->getContent());
        }

        $retryResponse->assertOk()
            ->assertJsonPath('action', 'split');

        // Exactly one release (transfer leg, first call) and one refund (retry)
        $this->assertEquals(1, Transaction::where('contract_id', $dispute->contract_id)
            ->where('type', 'release')->count());
        $this->assertEquals(1, Transaction::where('contract_id', $dispute->contract_id)
            ->where('type', 'refund')->count());

        // Refund was called exactly twice: once (failed) + once (succeeded on retry)
        $this->assertEquals(2, $refundCallCount, 'Refund must have been attempted exactly twice total: once failed, once succeeded');

        $this->assertDatabaseHas('disputes', ['id' => $dispute->id, 'split_state' => 'complete']);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // PHASE 7 — Webhook idempotency: payment_intent.succeeded only targets deposit
    // ═════════════════════════════════════════════════════════════════════════

    public function test_payment_intent_succeeded_only_updates_deposit_type_transactions(): void
    {
        $client   = $this->makeClient();
        $contract = Contract::factory()->active()->create(['client_id' => $client->id]);

        // A deposit transaction (should be updated to completed)
        Transaction::create([
            'contract_id'      => $contract->id,
            'initiated_by'     => $client->id,
            'type'             => 'deposit',
            'amount'           => 100.00,
            'currency'         => 'USD',
            'stripe_reference' => 'pi_succeed_001',
            'status'           => 'pending',
        ]);

        // A release transaction with a transfer_id — stripe_reference is NULL
        // (release transactions are tracked by stripe_transfer_id, not stripe_reference)
        $milestone = Milestone::factory()->create(['contract_id' => $contract->id]);
        Transaction::create([
            'contract_id'        => $contract->id,
            'milestone_id'       => $milestone->id,
            'initiated_by'       => $client->id,
            'type'               => 'release',
            'amount'             => 50.00,
            'currency'           => 'USD',
            'stripe_transfer_id' => 'tr_unrelated_001',
            'status'             => 'pending',
        ]);

        $mock = $this->mock(StripeService::class);
        $mock->shouldReceive('constructWebhookEvent')->once()->andReturn(
            $this->buildFakeStripeEvent('payment_intent.succeeded', [
                'id'     => 'pi_succeed_001',
                'object' => 'payment_intent',
                'amount' => 10000,
            ])
        );

        $this->postJson('/api/v1/webhooks/stripe', [], ['Stripe-Signature' => 'x'])->assertOk();

        // Deposit updated to completed
        $this->assertDatabaseHas('transactions', [
            'stripe_reference' => 'pi_succeed_001',
            'type'             => 'deposit',
            'status'           => 'completed',
        ]);

        // Release untouched — payment_intent.succeeded handler only targets deposits
        $this->assertDatabaseHas('transactions', [
            'stripe_transfer_id' => 'tr_unrelated_001',
            'type'               => 'release',
            'status'             => 'pending',
        ]);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // PHASE 8 — Refund does NOT require freelancer Connect account
    // ═════════════════════════════════════════════════════════════════════════

    public function test_execute_resolution_refund_succeeds_without_freelancer_connect_account(): void
    {
        $client     = $this->makeClient();
        // Freelancer has NO Stripe account
        $freelancer = $this->makeFreelancer(withStripeAccount: false);
        $admin      = $this->makeAdmin();

        $contract = Contract::factory()->active()->create([
            'client_id'     => $client->id,
            'freelancer_id' => $freelancer->id,
            'total_amount'  => 100.00,
            'currency'      => 'USD',
        ]);

        $milestone = Milestone::factory()->create([
            'contract_id' => $contract->id,
            'amount'      => 100.00,
            'status'      => 'disputed',
        ]);

        EscrowBalance::create([
            'contract_id'              => $contract->id,
            'held_amount'              => 100.00,
            'released_amount'          => 0,
            'refunded_amount'          => 0,
            'currency'                 => 'USD',
            'status'                   => 'funded',
            'stripe_payment_intent_id' => 'pi_no_acct_001',
        ]);

        $dispute = Dispute::create([
            'contract_id'      => $contract->id,
            'milestone_id'     => $milestone->id,
            'raised_by'        => $client->id,
            'status'           => 'resolved_client',
            'reason'           => 'Test.',
            'resolution_notes' => 'Client wins.',
            'resolved_at'      => now(),
        ]);

        $stripe = $this->mock(StripeService::class);
        // refundPaymentIntent must be called — no freelancer account check needed for refund
        $stripe->shouldReceive('refundPaymentIntent')
            ->once()
            ->andReturn($this->fakeRefund('re_no_acct_001'));

        // Must NOT require createTransfer
        $stripe->shouldNotReceive('createTransfer');

        $response = $this->actingAs($admin)
            ->postJson("/api/v1/disputes/{$dispute->id}/execute-resolution");

        $response->assertOk()
            ->assertJsonPath('action', 'refund');
    }

    // ═════════════════════════════════════════════════════════════════════════
    // PHASE 9 — Mediator authorization (documented behavior)
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Current implementation: ONLY admin role can execute resolutions.
     * The comment "Only admins and mediators" in resolve() is misleading —
     * both resolve() and executeResolution() only check isAdmin().
     * There is no mediator role in the current system.
     * This test documents and verifies the current (admin-only) behavior.
     */
    public function test_resolve_and_execute_require_admin_role_not_mediator_role(): void
    {
        // A user with role 'freelancer' assigned as mediator in DB
        $client     = $this->makeClient();
        $freelancer = $this->makeFreelancer();
        $assignedMediator = User::factory()->freelancer()->create(); // not admin

        $contract = Contract::factory()->disputed()->create([
            'client_id'     => $client->id,
            'freelancer_id' => $freelancer->id,
        ]);

        $milestone = Milestone::factory()->disputed()->create(['contract_id' => $contract->id]);

        $dispute = Dispute::create([
            'contract_id'          => $contract->id,
            'milestone_id'         => $milestone->id,
            'raised_by'            => $client->id,
            'assigned_mediator_id' => $assignedMediator->id, // assigned but not admin
            'status'               => 'open',
            'reason'               => 'Test.',
        ]);

        // The assigned mediator cannot resolve because they are not admin
        $this->actingAs($assignedMediator)
            ->patchJson("/api/v1/disputes/{$dispute->id}/resolve", [
                'status'           => 'resolved_client',
                'resolution_notes' => 'Mediator trying to resolve.',
            ])
            ->assertStatus(403);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // PHASE 1 (unit) — availableAmount() BCMath precision
    // ═════════════════════════════════════════════════════════════════════════

    public function test_available_amount_100_minus_70_minus_30_is_exactly_zero(): void
    {
        $escrow = new EscrowBalance([
            'held_amount'     => '100.00',
            'released_amount' => '70.00',
            'refunded_amount' => '30.00',
        ]);

        $this->assertSame('0.00', $escrow->availableAmount());
    }

    public function test_available_amount_unusual_decimal_amounts(): void
    {
        // 100.01 - 33.33 - 33.33 = 33.35 (exact)
        $escrow = new EscrowBalance([
            'held_amount'     => '100.01',
            'released_amount' => '33.33',
            'refunded_amount' => '33.33',
        ]);

        $this->assertSame('33.35', $escrow->availableAmount());
    }

    public function test_available_amount_zero_balance(): void
    {
        $escrow = new EscrowBalance([
            'held_amount'     => '0.00',
            'released_amount' => '0.00',
            'refunded_amount' => '0.00',
        ]);

        $this->assertSame('0.00', $escrow->availableAmount());
    }

    public function test_available_amount_negative_clamped_to_zero(): void
    {
        // Data inconsistency guard — released + refunded > held should not return negative
        $escrow = new EscrowBalance([
            'held_amount'     => '50.00',
            'released_amount' => '40.00',
            'refunded_amount' => '20.00', // total 60 > 50
        ]);

        $this->assertSame('0.00', $escrow->availableAmount());
    }

    public function test_available_amount_full_held_no_movements(): void
    {
        $escrow = new EscrowBalance([
            'held_amount'     => '1234.56',
            'released_amount' => '0.00',
            'refunded_amount' => '0.00',
        ]);

        $this->assertSame('1234.56', $escrow->availableAmount());
    }

    public function test_available_amount_returns_string_not_float(): void
    {
        $escrow = new EscrowBalance([
            'held_amount'     => '100.00',
            'released_amount' => '30.00',
            'refunded_amount' => '20.00',
        ]);

        $result = $escrow->availableAmount();
        $this->assertIsString($result);
        $this->assertSame('50.00', $result);
    }

    public function test_available_amount_in_cents_conversion_is_exact(): void
    {
        // The BCMath cents conversion used in controllers
        $escrow = new EscrowBalance([
            'held_amount'     => '100.00',
            'released_amount' => '70.00',
            'refunded_amount' => '0.00',
        ]);

        $cents = (int) bcmul($escrow->availableAmount(), '100', 0);
        $this->assertSame(3000, $cents);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // reconcile-claiming endpoint tests
    // ═════════════════════════════════════════════════════════════════════════
    public function test_reconcile_claiming_non_admin_gets_403(): void
    {
        ['client' => $client, 'dispute' => $dispute] = $this->resolvedDispute('resolved_client');
        $dispute->update(['execution_state' => 'claiming']);

        $this->mock(StripeService::class)->shouldNotReceive('findRefundByIdempotencyKey');

        $this->actingAs($client)
            ->postJson("/api/v1/disputes/{$dispute->id}/reconcile-claiming")
            ->assertStatus(403);
    }

    public function test_reconcile_claiming_on_non_claiming_dispute_returns_no_action(): void
    {
        ['admin' => $admin, 'dispute' => $dispute] = $this->resolvedDispute('resolved_client');
        // execution_state = 'idle' (default)

        $this->mock(StripeService::class)->shouldNotReceive('findRefundByIdempotencyKey');

        $this->actingAs($admin)
            ->postJson("/api/v1/disputes/{$dispute->id}/reconcile-claiming")
            ->assertOk()
            ->assertJsonPath('execution_state', 'idle');
    }

    public function test_reconcile_claiming_refund_found_on_stripe_marks_complete(): void
    {
        ['admin' => $admin, 'dispute' => $dispute, 'contract' => $contract]
            = $this->resolvedDispute('resolved_client');

        $dispute->update(['execution_state' => 'claiming']);

        $stripe = $this->mock(StripeService::class);
        $stripe->shouldReceive('findRefundByIdempotencyKey')
            ->once()
            ->with("dispute-refund-{$dispute->id}")
            ->andReturn($this->fakeRefund('re_reconcile_001'));

        $response = $this->actingAs($admin)
            ->postJson("/api/v1/disputes/{$dispute->id}/reconcile-claiming");

        $response->assertOk()
            ->assertJsonPath('outcome', 'complete')
            ->assertJsonPath('action', 'refund');

        $this->assertDatabaseHas('disputes', [
            'id'              => $dispute->id,
            'execution_state' => 'complete',
        ]);

        $this->assertDatabaseHas('transactions', [
            'contract_id'      => $contract->id,
            'type'             => 'refund',
            'stripe_reference' => 're_reconcile_001',
        ]);
    }

    public function test_reconcile_claiming_no_refund_on_stripe_resets_to_idle(): void
    {
        ['admin' => $admin, 'dispute' => $dispute] = $this->resolvedDispute('resolved_client');
        $dispute->update(['execution_state' => 'claiming']);

        $stripe = $this->mock(StripeService::class);
        $stripe->shouldReceive('findRefundByIdempotencyKey')
            ->once()
            ->andReturn(null); // Stripe has no record

        $response = $this->actingAs($admin)
            ->postJson("/api/v1/disputes/{$dispute->id}/reconcile-claiming");

        $response->assertOk()
            ->assertJsonPath('outcome', 'reset_to_idle');

        $this->assertDatabaseHas('disputes', [
            'id'              => $dispute->id,
            'execution_state' => 'idle',
        ]);

        // No transaction created — nothing happened on Stripe
        $this->assertDatabaseMissing('transactions', [
            'contract_id' => $dispute->contract_id,
            'type'        => 'refund',
        ]);
    }

    public function test_reconcile_claiming_transfer_found_on_stripe_marks_complete(): void
    {
        ['admin' => $admin, 'dispute' => $dispute, 'contract' => $contract]
            = $this->resolvedDispute('resolved_freelancer');

        $dispute->update(['execution_state' => 'claiming']);

        $stripe = $this->mock(StripeService::class);
        $stripe->shouldReceive('findTransferByIdempotencyKey')
            ->once()
            ->with("dispute-transfer-{$dispute->id}")
            ->andReturn($this->fakeTransfer('tr_reconcile_001'));

        $response = $this->actingAs($admin)
            ->postJson("/api/v1/disputes/{$dispute->id}/reconcile-claiming");

        $response->assertOk()
            ->assertJsonPath('outcome', 'complete')
            ->assertJsonPath('action', 'transfer');

        $this->assertDatabaseHas('disputes', [
            'id'              => $dispute->id,
            'execution_state' => 'complete',
        ]);

        $this->assertDatabaseHas('transactions', [
            'contract_id'        => $contract->id,
            'type'               => 'release',
            'stripe_transfer_id' => 'tr_reconcile_001',
        ]);
    }

    public function test_reconcile_claiming_no_transfer_on_stripe_resets_to_idle(): void
    {
        ['admin' => $admin, 'dispute' => $dispute] = $this->resolvedDispute('resolved_freelancer');
        $dispute->update(['execution_state' => 'claiming']);

        $stripe = $this->mock(StripeService::class);
        $stripe->shouldReceive('findTransferByIdempotencyKey')
            ->once()
            ->andReturn(null);

        $this->actingAs($admin)
            ->postJson("/api/v1/disputes/{$dispute->id}/reconcile-claiming")
            ->assertOk()
            ->assertJsonPath('outcome', 'reset_to_idle');

        $this->assertDatabaseHas('disputes', [
            'id'              => $dispute->id,
            'execution_state' => 'idle',
        ]);
    }

    public function test_reconcile_claiming_split_both_legs_found_marks_complete(): void
    {
        ['admin' => $admin, 'dispute' => $dispute, 'contract' => $contract]
            = $this->resolvedDispute('resolved_split', 70, 'pi_split_reconcile', 100.00);

        $dispute->update(['execution_state' => 'claiming']);

        $stripe = $this->mock(StripeService::class);
        $stripe->shouldReceive('findTransferByIdempotencyKey')
            ->once()
            ->andReturn($this->fakeTransfer('tr_split_reconcile_001'));
        $stripe->shouldReceive('findRefundByIdempotencyKey')
            ->once()
            ->andReturn($this->fakeRefund('re_split_reconcile_001'));

        $response = $this->actingAs($admin)
            ->postJson("/api/v1/disputes/{$dispute->id}/reconcile-claiming");

        $response->assertOk()
            ->assertJsonPath('outcome', 'complete')
            ->assertJsonPath('action', 'split');

        $this->assertDatabaseHas('disputes', [
            'id'              => $dispute->id,
            'execution_state' => 'complete',
            'split_state'     => 'complete',
        ]);
    }

    public function test_reconcile_claiming_split_only_transfer_found_resets_for_refund_retry(): void
    {
        ['admin' => $admin, 'dispute' => $dispute]
            = $this->resolvedDispute('resolved_split', 70, 'pi_split_partial_rec', 100.00);

        $dispute->update(['execution_state' => 'claiming']);

        $stripe = $this->mock(StripeService::class);
        $stripe->shouldReceive('findTransferByIdempotencyKey')
            ->once()
            ->andReturn($this->fakeTransfer('tr_partial_rec_001'));
        $stripe->shouldReceive('findRefundByIdempotencyKey')
            ->once()
            ->andReturn(null); // refund didn't happen

        $response = $this->actingAs($admin)
            ->postJson("/api/v1/disputes/{$dispute->id}/reconcile-claiming");

        $response->assertOk()
            ->assertJsonPath('outcome', 'partial_transfer_reconciled');

        // execution_state reset to idle so execute-resolution can retry refund
        $this->assertDatabaseHas('disputes', [
            'id'              => $dispute->id,
            'execution_state' => 'idle',
            'split_state'     => 'transfer_done',
            'split_transfer_id' => 'tr_partial_rec_001',
        ]);
    }

    public function test_reconcile_claiming_split_no_legs_found_resets_to_idle(): void
    {
        ['admin' => $admin, 'dispute' => $dispute]
            = $this->resolvedDispute('resolved_split', 70, 'pi_split_none', 100.00);

        $dispute->update(['execution_state' => 'claiming']);

        $stripe = $this->mock(StripeService::class);
        $stripe->shouldReceive('findTransferByIdempotencyKey')->once()->andReturn(null);
        $stripe->shouldReceive('findRefundByIdempotencyKey')->once()->andReturn(null);

        $this->actingAs($admin)
            ->postJson("/api/v1/disputes/{$dispute->id}/reconcile-claiming")
            ->assertOk()
            ->assertJsonPath('outcome', 'reset_to_idle');

        $this->assertDatabaseHas('disputes', [
            'id'              => $dispute->id,
            'execution_state' => 'idle',
        ]);
    }

    public function test_reconcile_claiming_is_idempotent_when_called_twice(): void
    {
        ['admin' => $admin, 'dispute' => $dispute, 'contract' => $contract]
            = $this->resolvedDispute('resolved_client');

        $dispute->update(['execution_state' => 'claiming']);

        $stripe = $this->mock(StripeService::class);
        $stripe->shouldReceive('findRefundByIdempotencyKey')
            ->once() // only called once — second call exits early (not claiming)
            ->andReturn($this->fakeRefund('re_idempotent_rec_001'));

        // First call: reconcile
        $this->actingAs($admin)
            ->postJson("/api/v1/disputes/{$dispute->id}/reconcile-claiming")
            ->assertOk()
            ->assertJsonPath('outcome', 'complete');

        // Second call: dispute is now 'complete', endpoint returns no-action
        $this->actingAs($admin)
            ->postJson("/api/v1/disputes/{$dispute->id}/reconcile-claiming")
            ->assertOk()
            ->assertJsonPath('execution_state', 'complete');

        // Only one refund transaction created
        $this->assertEquals(1, Transaction::where('stripe_reference', 're_idempotent_rec_001')->count());
    }
}
