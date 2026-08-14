<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\ContractSignature;
use App\Models\Dispute;
use App\Models\EscrowBalance;
use App\Models\Milestone;
use App\Models\PaymentAccount;
use App\Models\Transaction;
use App\Models\User;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Stripe\PaymentIntent;
use Stripe\Payout;
use Stripe\Transfer;
use Tests\TestCase;

/**
 * End-to-end escrow flow tests using a mocked StripeService.
 *
 * These tests exercise the full controller + DB logic without hitting
 * the real Stripe API. To test against live Stripe test-mode:
 *   1. Set STRIPE_KEY / STRIPE_SECRET / STRIPE_WEBHOOK_SECRET in .env
 *   2. Run: stripe listen --forward-to localhost:8000/api/v1/webhooks/stripe
 *   3. Use the StripeEscrowLiveTest artisan command instead (see below).
 */
class StripeEscrowFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $client;
    private User $freelancer;
    private Contract $contract;
    private Milestone $milestone;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client     = User::factory()->client()->create(['name' => 'Test Client']);
        $this->freelancer = User::factory()->freelancer()->create(['name' => 'Test Freelancer']);

        // Give the freelancer a connected Stripe account (mocked)
        PaymentAccount::create([
            'user_id'          => $this->freelancer->id,
            'stripe_account_id' => 'acct_test_freelancer',
            'status'           => 'active',
            'payout_enabled'   => true,
            'charges_enabled'  => true,
        ]);

        // Create a fully signed active contract
        $this->contract = Contract::factory()->active()->create([
            'client_id'    => $this->client->id,
            'freelancer_id' => $this->freelancer->id,
            'total_amount' => 1500.00,
            'currency'     => 'USD',
        ]);

        ContractSignature::factory()->create([
            'contract_id' => $this->contract->id,
            'user_id'     => $this->client->id,
            'signed_name' => 'Test Client',
        ]);

        ContractSignature::factory()->create([
            'contract_id' => $this->contract->id,
            'user_id'     => $this->freelancer->id,
            'signed_name' => 'Test Freelancer',
        ]);

        $this->milestone = Milestone::factory()->create([
            'contract_id' => $this->contract->id,
            'amount'      => 1500.00,
            'status'      => 'pending',
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** Bind a mock StripeService that returns fake Stripe objects. */
    private function mockStripe(): \Mockery\MockInterface
    {
        $mock = $this->mock(StripeService::class);
        $mock->shouldReceive('isConfigured')->andReturn(true)->byDefault();
        return $mock;
    }

    /** Build a fake PaymentIntent object. */
    private function fakePaymentIntent(string $id = 'pi_test_123'): PaymentIntent
    {
        return PaymentIntent::constructFrom([
            'id'            => $id,
            'object'        => 'payment_intent',
            'client_secret' => "{$id}_secret_abc",
            'status'        => 'requires_capture',
            'amount'        => 150000,
            'currency'      => 'usd',
        ]);
    }

    /** Build a fake Transfer object. */
    private function fakeTransfer(string $id = 'tr_test_123'): Transfer
    {
        return Transfer::constructFrom([
            'id'       => $id,
            'object'   => 'transfer',
            'amount'   => 150000,
            'currency' => 'usd',
        ]);
    }

    /** Build a fake Payout object. */
    private function fakePayout(string $id = 'po_test_123'): Payout
    {
        return Payout::constructFrom([
            'id'       => $id,
            'object'   => 'payout',
            'amount'   => 100000,
            'currency' => 'usd',
        ]);
    }

    // ── 1. Fund Escrow ────────────────────────────────────────────────────────

    public function test_client_can_fund_escrow(): void
    {
        $stripe = $this->mockStripe();
        $stripe->shouldReceive('createPaymentIntent')
            ->once()
            ->with(150000, 'USD', $this->contract->id, "fund-{$this->contract->id}")
            ->andReturn($this->fakePaymentIntent('pi_fund_001'));

        $response = $this->actingAs($this->client)
            ->postJson("/api/v1/contracts/{$this->contract->id}/fund");

        $response->assertOk()
            ->assertJsonPath('escrow.status', 'funded')
            ->assertJsonStructure(['client_secret', 'escrow']);

        $this->assertDatabaseHas('escrow_balances', [
            'contract_id'              => $this->contract->id,
            'status'                   => 'funded',
            'stripe_payment_intent_id' => 'pi_fund_001',
        ]);

        $this->assertDatabaseHas('transactions', [
            'contract_id'      => $this->contract->id,
            'type'             => 'deposit',
            'status'           => 'pending',
            'stripe_reference' => 'pi_fund_001',
        ]);
    }

    public function test_non_client_cannot_fund_escrow(): void
    {
        $this->mockStripe();

        $this->actingAs($this->freelancer)
            ->postJson("/api/v1/contracts/{$this->contract->id}/fund")
            ->assertStatus(403);
    }

    public function test_funding_inactive_contract_is_rejected(): void
    {
        $this->mockStripe();
        $this->contract->update(['status' => 'pending_signature']);

        $this->actingAs($this->client)
            ->postJson("/api/v1/contracts/{$this->contract->id}/fund")
            ->assertStatus(422);
    }

    // ── 2. Webhook: payment_intent.succeeded ──────────────────────────────────

    public function test_webhook_marks_deposit_completed_on_payment_intent_succeeded(): void
    {
        // Pre-create the pending transaction
        Transaction::create([
            'contract_id'      => $this->contract->id,
            'initiated_by'     => $this->client->id,
            'type'             => 'deposit',
            'amount'           => 1500.00,
            'currency'         => 'USD',
            'stripe_reference' => 'pi_webhook_001',
            'status'           => 'pending',
        ]);

        // Build a fake signed webhook payload
        $payload = json_encode([
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id'     => 'pi_webhook_001',
                    'object' => 'payment_intent',
                    'amount' => 150000,
                ],
            ],
        ]);

        $secret    = 'whsec_test_secret';
        $timestamp = time();
        $sigHeader = $this->buildStripeSignature($payload, $timestamp, $secret);

        // Bind a StripeService mock that bypasses signature verification
        $mock = $this->mock(StripeService::class);
        $mock->shouldReceive('constructWebhookEvent')
            ->once()
            ->andReturn($this->buildFakeStripeEvent('payment_intent.succeeded', [
                'id'     => 'pi_webhook_001',
                'object' => 'payment_intent',
                'amount' => 150000,
            ]));

        $response = $this->postJson('/api/v1/webhooks/stripe', [], [
            'Stripe-Signature' => $sigHeader,
        ]);

        $response->assertOk()->assertJson(['received' => true]);

        $this->assertDatabaseHas('transactions', [
            'stripe_reference' => 'pi_webhook_001',
            'type'             => 'deposit',
            'status'           => 'completed',
        ]);
    }

    // ── 3. Approve milestone → triggers Stripe transfer ───────────────────────

    public function test_approving_milestone_triggers_stripe_transfer(): void
    {
        $stripe = $this->mockStripe();
        // The listener runs synchronously in test env — allow 1 or 2 calls
        // (controller path + listener path both call createTransfer if both fire)
        $stripe->shouldReceive('createTransfer')
            ->atLeast()->once()
            ->with(
                150000,
                'USD',
                'acct_test_freelancer',
                $this->milestone->id,
                "auto-release-{$this->milestone->id}"
            )
            ->andReturn($this->fakeTransfer('tr_release_001'));

        // Submit then approve
        $this->milestone->update(['status' => 'submitted', 'submitted_at' => now()]);

        $this->actingAs($this->client)
            ->postJson("/api/v1/milestones/{$this->milestone->id}/approve")
            ->assertOk();

        // Milestone should be 'released' (auto-released by listener)
        $this->assertDatabaseHas('milestones', [
            'id'     => $this->milestone->id,
            'status' => 'released',
        ]);

        // Release transaction recorded
        $this->assertDatabaseHas('transactions', [
            'contract_id'        => $this->contract->id,
            'milestone_id'       => $this->milestone->id,
            'type'               => 'release',
            'status'             => 'completed',
            'stripe_transfer_id' => 'tr_release_001',
        ]);
    }

    // ── 4. Webhook: transfer.created ──────────────────────────────────────────

    public function test_webhook_marks_release_completed_on_transfer_created(): void
    {
        Transaction::create([
            'contract_id'        => $this->contract->id,
            'milestone_id'       => $this->milestone->id,
            'initiated_by'       => $this->client->id,
            'type'               => 'release',
            'amount'             => 1500.00,
            'currency'           => 'USD',
            'stripe_transfer_id' => 'tr_webhook_001',
            'status'             => 'pending',
        ]);

        $mock = $this->mock(StripeService::class);
        $mock->shouldReceive('constructWebhookEvent')
            ->once()
            ->andReturn($this->buildFakeStripeEvent('transfer.created', [
                'id'     => 'tr_webhook_001',
                'object' => 'transfer',
                'amount' => 150000,
            ]));

        $this->postJson('/api/v1/webhooks/stripe', [], [
            'Stripe-Signature' => 'test',
        ])->assertOk();

        $this->assertDatabaseHas('transactions', [
            'stripe_transfer_id' => 'tr_webhook_001',
            'status'             => 'completed',
        ]);
    }

    // ── 5. Transaction ledger sequence ────────────────────────────────────────

    public function test_transaction_ledger_shows_held_then_released_sequence(): void
    {
        // Create deposit and release with explicit timestamps to ensure ordering
        $deposit = Transaction::create([
            'contract_id'      => $this->contract->id,
            'initiated_by'     => $this->client->id,
            'type'             => 'deposit',
            'amount'           => 1500.00,
            'currency'         => 'USD',
            'stripe_reference' => 'pi_seq_001',
            'status'           => 'completed',
        ]);

        // Force a slightly later timestamp via DB update
        \Illuminate\Support\Facades\DB::table('transactions')
            ->where('id', $deposit->id)
            ->update(['created_at' => now()->subMinutes(5)]);

        $release = Transaction::create([
            'contract_id'        => $this->contract->id,
            'milestone_id'       => $this->milestone->id,
            'initiated_by'       => $this->client->id,
            'type'               => 'release',
            'amount'             => 1500.00,
            'currency'           => 'USD',
            'stripe_transfer_id' => 'tr_seq_001',
            'status'             => 'completed',
        ]);

        $response = $this->actingAs($this->client)
            ->getJson("/api/v1/transactions?contract_id={$this->contract->id}");

        $response->assertOk();

        $data  = $response->json('data');
        $types = collect($data)->pluck('type')->toArray();

        // Both transaction types must be present
        $this->assertContains('deposit', $types);
        $this->assertContains('release', $types);

        // API orders newest-first (latest()): release was created AFTER deposit
        // so release appears at a lower (earlier) index in the results
        $releaseIndex = array_search('release', $types);
        $depositIndex = array_search('deposit', $types);

        // release should appear before (lower index than) deposit in newest-first ordering
        $this->assertLessThan(
            $depositIndex,
            $releaseIndex,
            "Release transaction ({$releaseIndex}) should appear before deposit ({$depositIndex}) in newest-first order"
        );
    }

    // ── 6. Withdrawal (freelancer payout) ─────────────────────────────────────

    public function test_freelancer_can_withdraw_released_balance(): void
    {
        $stripe = $this->mockStripe();
        $stripe->shouldReceive('createPayout')
            ->once()
            ->with(
                100000,
                'USD',
                'acct_test_freelancer',
                \Mockery::type('string') // idempotency key
            )
            ->andReturn($this->fakePayout('po_withdraw_001'));

        $response = $this->actingAs($this->freelancer)
            ->postJson('/api/v1/payouts/withdraw', [
                'amount'   => 1000.00,
                'currency' => 'USD',
            ]);

        $response->assertOk();

        $this->assertDatabaseHas('transactions', [
            'initiated_by'     => $this->freelancer->id,
            'type'             => 'payout',
            'stripe_reference' => 'po_withdraw_001',
            'status'           => 'processing',
        ]);
    }

    public function test_client_cannot_withdraw(): void
    {
        $this->mockStripe();

        $this->actingAs($this->client)
            ->postJson('/api/v1/payouts/withdraw', [
                'amount'   => 100.00,
                'currency' => 'USD',
            ])
            ->assertStatus(403);
    }

    // ── 7. Dispute: funds stay held, NOT released ─────────────────────────────

    public function test_disputed_milestone_funds_are_not_released(): void
    {
        // Fund escrow first
        EscrowBalance::create([
            'contract_id'              => $this->contract->id,
            'held_amount'              => 1500.00,
            'released_amount'          => 0,
            'refunded_amount'          => 0,
            'currency'                 => 'USD',
            'status'                   => 'funded',
            'stripe_payment_intent_id' => 'pi_dispute_001',
        ]);

        Transaction::create([
            'contract_id'      => $this->contract->id,
            'initiated_by'     => $this->client->id,
            'type'             => 'deposit',
            'amount'           => 1500.00,
            'currency'         => 'USD',
            'stripe_reference' => 'pi_dispute_001',
            'status'           => 'completed',
        ]);

        // Submit milestone
        $this->milestone->update(['status' => 'submitted', 'submitted_at' => now()]);

        // Client raises a dispute instead of approving
        $this->actingAs($this->client)
            ->postJson("/api/v1/milestones/{$this->milestone->id}/dispute", [
                'reason' => 'Deliverable does not meet the agreed spec.',
            ])
            ->assertStatus(201);

        // Milestone must be 'disputed'
        $this->assertDatabaseHas('milestones', [
            'id'     => $this->milestone->id,
            'status' => 'disputed',
        ]);

        // Escrow balance: nothing released
        $escrow = EscrowBalance::where('contract_id', $this->contract->id)->first();
        $this->assertEquals('0.00', $escrow->released_amount);

        // No release transaction created
        $this->assertDatabaseMissing('transactions', [
            'contract_id' => $this->contract->id,
            'type'        => 'release',
        ]);

        // Approve must be blocked while disputed
        $this->actingAs($this->client)
            ->postJson("/api/v1/milestones/{$this->milestone->id}/approve")
            ->assertStatus(422);
    }

    // ── 8. Webhook: charge.dispute.created (chargeback) ──────────────────────

    public function test_webhook_flags_transaction_on_chargeback(): void
    {
        Transaction::create([
            'contract_id'      => $this->contract->id,
            'initiated_by'     => $this->client->id,
            'type'             => 'deposit',
            'amount'           => 1500.00,
            'currency'         => 'USD',
            'stripe_reference' => 'pi_chargeback_001',
            'status'           => 'completed',
        ]);

        $mock = $this->mock(StripeService::class);
        $mock->shouldReceive('constructWebhookEvent')
            ->once()
            ->andReturn($this->buildFakeStripeEvent('charge.dispute.created', [
                'id'             => 'dp_test_001',
                'object'         => 'dispute',
                'payment_intent' => 'pi_chargeback_001',
                'reason'         => 'fraudulent',
            ]));

        $this->postJson('/api/v1/webhooks/stripe', [], [
            'Stripe-Signature' => 'test',
        ])->assertOk();

        $this->assertDatabaseHas('transactions', [
            'stripe_reference' => 'pi_chargeback_001',
            'status'           => 'reversed',
        ]);
    }

    // ── 9. Webhook signature verification ────────────────────────────────────

    public function test_webhook_rejects_invalid_signature(): void
    {
        // Real StripeService for this test — it will fail signature check
        $this->app->forgetInstance(StripeService::class);

        // Set a webhook secret so the real verification path runs
        config(['services.stripe.webhook_secret' => 'whsec_test_real_secret']);

        $response = $this->postJson(
            '/api/v1/webhooks/stripe',
            ['type' => 'payment_intent.succeeded'],
            ['Stripe-Signature' => 'v1=invalidsignature,t=12345']
        );

        $response->assertStatus(400)
            ->assertJson(['error' => 'Invalid signature.']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Build a Stripe-Signature header value for a given payload and secret.
     */
    private function buildStripeSignature(string $payload, int $timestamp, string $secret): string
    {
        $signedPayload = "{$timestamp}.{$payload}";
        $signature     = hash_hmac('sha256', $signedPayload, ltrim($secret, 'whsec_'));
        return "t={$timestamp},v1={$signature}";
    }

    /**
     * Build a fake \Stripe\Event object using constructFrom so all
     * nested objects are proper Stripe SDK types.
     */
    private function buildFakeStripeEvent(string $type, array $objectData): \Stripe\Event
    {
        return \Stripe\Event::constructFrom([
            'id'      => 'evt_test_' . uniqid(),
            'object'  => 'event',
            'type'    => $type,
            'data'    => [
                'object' => $objectData,
            ],
            'livemode' => false,
        ]);
    }
}
