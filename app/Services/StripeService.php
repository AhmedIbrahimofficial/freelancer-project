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
    ): PaymentIntent {
        return $this->client()->paymentIntents->create(
            [
                'amount'              => $amountCents,
                'currency'            => strtolower($currency),
                'capture_method'      => 'manual',
                'confirmation_method' => 'automatic',
                'metadata'            => ['contract_id' => $contractId],
                'description'         => "Escrow funding for contract {$contractId}",
            ],
            ['idempotency_key' => $idempotencyKey],
        );
    }

    public function capturePaymentIntent(string $paymentIntentId): PaymentIntent
    {
        return $this->client()->paymentIntents->capture($paymentIntentId);
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
