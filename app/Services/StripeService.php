<?php

namespace App\Services;

use RuntimeException;
use Stripe\PaymentIntent;
use Stripe\Stripe;
use Stripe\StripeClient;
use Stripe\Transfer;
use Stripe\Webhook;

/**
 * Thin wrapper around the Stripe SDK.
 * All Stripe calls go through here so tests can mock this single class.
 *
 * When STRIPE_SECRET is not set (e.g. in tests without Event::fake()),
 * the client is null and any call that needs it throws a RuntimeException.
 * Tests that exercise Stripe paths should mock this service or use Event::fake().
 */
class StripeService
{
    private ?StripeClient $client = null;

    public function __construct()
    {
        $key = config('services.stripe.secret');

        if (! $key) {
            return; // Unconfigured — $client stays null
        }

        Stripe::setApiKey($key);
        Stripe::setAppInfo('FreelancerProtect', '1.0.0');
        $this->client = new StripeClient($key);
    }

    /** Returns the StripeClient or throws if Stripe is not configured. */
    private function client(): StripeClient
    {
        if (! $this->client) {
            throw new RuntimeException(
                'Stripe is not configured. Set STRIPE_SECRET in your .env file.'
            );
        }
        return $this->client;
    }

    /** True when Stripe keys are present and the client is ready. */
    public function isConfigured(): bool
    {
        return $this->client !== null;
    }

    // ── Connect onboarding ────────────────────────────────────────────────────

    public function createConnectAccount(string $email, string $country = 'US'): \Stripe\Account
    {
        return $this->client()->accounts->create([
            'type'         => 'express',
            'email'        => $email,
            'country'      => $country,
            'capabilities' => ['transfers' => ['requested' => true]],
        ]);
    }

    public function createAccountLink(
        string $stripeAccountId,
        string $refreshUrl,
        string $returnUrl,
    ): \Stripe\AccountLink {
        return $this->client()->accountLinks->create([
            'account'     => $stripeAccountId,
            'refresh_url' => $refreshUrl,
            'return_url'  => $returnUrl,
            'type'        => 'account_onboarding',
        ]);
    }

    // ── PaymentIntents ────────────────────────────────────────────────────────

    public function createPaymentIntent(
        int    $amountCents,
        string $currency,
        string $contractId,
        string $idempotencyKey,
        ?string $destinationAccountId = null,
        int    $applicationFeeCents = 0,
    ): PaymentIntent {
        $params = [
            'amount'              => $amountCents,
            'currency'            => strtolower($currency),
            'capture_method'      => 'manual',
            'confirmation_method' => 'automatic',
            'metadata'            => ['contract_id' => $contractId],
            'description'         => "Escrow funding for contract {$contractId}",
        ];

        // If freelancer's connected account is known at funding time, route funds directly.
        // This enables Stripe to associate the payment with the connected account.
        if ($destinationAccountId) {
            $params['on_behalf_of']    = $destinationAccountId;
            $params['transfer_data']   = ['destination' => $destinationAccountId];

            if ($applicationFeeCents > 0) {
                $params['application_fee_amount'] = $applicationFeeCents;
            }
        }

        return $this->client()->paymentIntents->create(
            $params,
            ['idempotency_key' => $idempotencyKey],
        );
    }

    public function capturePaymentIntent(string $paymentIntentId): PaymentIntent
    {
        return $this->client()->paymentIntents->capture($paymentIntentId);
    }

    // ── Lookups ───────────────────────────────────────────────────────────────

    /**
     * Look up a Transfer by its idempotency key using Stripe's idempotency-key
     * header search. Returns the transfer if one was created with this key, or
     * null if no operation exists for this key yet.
     *
     * Used by stuck-claim reconciliation to check whether a Stripe call actually
     * succeeded before we decide to mark the dispute as complete or reset to idle.
     */
    public function findTransferByIdempotencyKey(string $idempotencyKey): ?Transfer
    {
        $results = $this->client()->transfers->all([
            'limit' => 1,
        ], [
            // Stripe returns the object created with this idempotency key if it exists
            'idempotency_key' => $idempotencyKey,
        ]);

        return $results->data[0] ?? null;
    }

    /**
     * Retrieve a Transfer by its Stripe transfer ID.
     * Returns null if not found (e.g. 404).
     */
    public function retrieveTransfer(string $transferId): ?Transfer
    {
        try {
            return $this->client()->transfers->retrieve($transferId);
        } catch (\Stripe\Exception\InvalidRequestException $e) {
            return null;
        }
    }

    /**
     * Look up a Refund by its idempotency key.
     * Returns the refund object if one was created with this key, or null.
     */
    public function findRefundByIdempotencyKey(string $idempotencyKey): ?\Stripe\Refund
    {
        $results = $this->client()->refunds->all([
            'limit' => 1,
        ], [
            'idempotency_key' => $idempotencyKey,
        ]);

        return $results->data[0] ?? null;
    }

    /**
     * Retrieve a Refund by its Stripe refund ID.
     * Returns null if not found.
     */
    public function retrieveRefund(string $refundId): ?\Stripe\Refund
    {
        try {
            return $this->client()->refunds->retrieve($refundId);
        } catch (\Stripe\Exception\InvalidRequestException $e) {
            return null;
        }
    }

    // ── Transfers ─────────────────────────────────────────────────────────────

    public function createTransfer(
        int    $amountCents,
        string $currency,
        string $destinationAccountId,
        string $milestoneId,
        string $idempotencyKey,
    ): Transfer {
        return $this->client()->transfers->create(
            [
                'amount'      => $amountCents,
                'currency'    => strtolower($currency),
                'destination' => $destinationAccountId,
                'metadata'    => ['milestone_id' => $milestoneId],
            ],
            ['idempotency_key' => $idempotencyKey],
        );
    }

    // ── Refunds ───────────────────────────────────────────────────────────────

    /**
     * Refund a PaymentIntent back to the client.
     * Amount in cents. Pass null to refund the full captured amount.
     */
    public function refundPaymentIntent(
        string $paymentIntentId,
        ?int   $amountCents,
        string $idempotencyKey,
        string $reason = 'requested_by_customer',
    ): \Stripe\Refund {
        $params = [
            'payment_intent' => $paymentIntentId,
            'reason'         => $reason, // 'duplicate' | 'fraudulent' | 'requested_by_customer'
        ];

        if ($amountCents !== null) {
            $params['amount'] = $amountCents;
        }

        return $this->client()->refunds->create(
            $params,
            ['idempotency_key' => $idempotencyKey],
        );
    }

    // ── Payouts ───────────────────────────────────────────────────────────────

    public function createPayout(
        int    $amountCents,
        string $currency,
        string $connectedAccountId,
        string $idempotencyKey,
    ): \Stripe\Payout {
        return $this->client()->payouts->create(
            [
                'amount'   => $amountCents,
                'currency' => strtolower($currency),
            ],
            [
                'stripe_account'  => $connectedAccountId,
                'idempotency_key' => $idempotencyKey,
            ],
        );
    }

    // ── Webhooks ──────────────────────────────────────────────────────────────

    public function constructWebhookEvent(
        string $payload,
        string $sigHeader,
        string $webhookSecret,
    ): \Stripe\Event {
        return Webhook::constructEvent($payload, $sigHeader, $webhookSecret);
    }
}
