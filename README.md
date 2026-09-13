# FreelancerProtect — Escrow & Contract Platform

A Laravel-based platform that protects both clients and freelancers through milestone-based escrow payments, digital contract signing, and AI-assisted dispute resolution.

---

## Architecture — Payment Flow

```
CLIENT
  │
  ├─ POST /contracts/{id}/fund
  │    └─ Stripe PaymentIntent (capture_method: manual)
  │         └─ Funds held in Stripe — NOT released yet
  │
  │  [Freelancer submits milestone]
  │  [Client approves milestone]
  │
  ├─ POST /milestones/{id}/release  ← explicit action required
  │    └─ Stripe Transfer → FREELANCER'S CONNECTED ACCOUNT
  │         └─ Freelancer triggers payout to their bank
  │
  └─ If dispute:
       Client / Freelancer → submit evidence
       Admin/Mediator reviews
       │
       ├─ Decision: Release → explicit POST /milestones/{id}/release
       └─ Decision: Refund  → explicit Stripe refund action
```

**Your platform is the orchestrator, not the payment recipient.**
Every freelancer has their own Stripe Express Connected Account.
You never touch the money directly.

---

## Dispute Resolution — Safety Boundary

> **Critical:** `dispute resolved` ≠ `money automatically released`

This is an intentional safety boundary. Funds never move automatically after a dispute is resolved. Every money movement requires an explicit action.

```
DISPUTE RAISED
  │
  ├─ Milestone locked (status: disputed)
  ├─ Approve blocked (422)
  ├─ Release blocked (422)
  └─ Funds remain held in Stripe escrow
       │
       │  [Both parties submit evidence]
       │  [Admin/Mediator reviews]
       │
       ▼
  PATCH /disputes/{id}/resolve
       │
       ├─ resolved_freelancer
       │    └─ execute-resolution → Stripe Transfer 100% → Freelancer
       │
       ├─ resolved_client
       │    └─ execute-resolution → Stripe Refund 100% → Client
       │
       ├─ resolved_split  (requires freelancer_percent: 1–99)
       │    └─ execute-resolution
       │         ├─ Leg 1: Stripe Transfer → Freelancer (floor of percent)
       │         └─ Leg 2: Stripe Refund  → Client (remainder, no cent lost)
       │              │
       │              └─ If Leg 2 fails after Leg 1 succeeded:
       │                   split_state = partial_failure
       │                   Re-call execute-resolution to retry refund leg only
       │
       └─ closed
            └─ execute-resolution → no financial movement
```

This means: if an admin forgets to act after resolving, the money stays safe in escrow. Nothing is lost, nothing is leaked. The worst outcome is a delay, not a loss.

**Split resolution rules:**
- `freelancer_percent` range: 1–99 (integers or decimals, e.g. 66.67)
- 0% = full refund → use `resolved_client` instead
- 100% = full transfer → use `resolved_freelancer` instead
- Rounding: freelancer gets `floor(total × percent/100)`, client gets the remainder — no cent is lost or created
- Both legs use idempotency keys — safe to retry on network failure
- If transfer succeeds but refund fails: `split_state = partial_failure`, re-call `execute-resolution` to retry refund leg only (transfer is NOT re-executed)
- Both legs recorded as separate Transactions (`release` + `refund`) for full audit trail

**What is NOT implemented yet:**
`resolved_split` now fully implements partial resolution. The remaining gap is split percentage validation at the business policy level — currently any value 1–99 is accepted. If you want to enforce specific increments (e.g. only multiples of 5%, or minimum 10%) add that validation to the `resolve` request before production.

---

## Local Setup

```bash
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate
php artisan serve
```

For real-time features (disputes, notifications):
```bash
php artisan queue:work
```

---

## Stripe Setup

### Test Mode (development)

```env
STRIPE_KEY=pk_test_...
STRIPE_SECRET=sk_test_...
STRIPE_WEBHOOK_SECRET=whsec_...
```

Install the [Stripe CLI](https://stripe.com/docs/stripe-cli) then run:
```bash
stripe listen --forward-to localhost:8000/api/v1/webhooks/stripe
```

### Live Mode (production)

```env
STRIPE_KEY=pk_live_...
STRIPE_SECRET=sk_live_...
STRIPE_WEBHOOK_SECRET=whsec_...
STRIPE_PLATFORM_FEE_PERCENT=5    # optional: 5% platform cut. Default 0.
```

**Webhook endpoint** — register in Stripe Dashboard → Webhooks:
```
https://yourdomain.com/api/v1/webhooks/stripe
```

Required events:
- `payment_intent.succeeded`
- `transfer.created`
- `charge.dispute.created`
- `account.updated`

---

## Freelancer Onboarding Flow

Every freelancer must connect a Stripe account before funds can be released to them.

```
1. POST /api/v1/connect/onboard   → returns { onboarding_url }
2. Redirect freelancer to onboarding_url (Stripe-hosted KYC)
3. Stripe redirects back to GET /api/v1/connect/return
4. PaymentAccount.status → "active", payout_enabled → true
```

If the onboarding link expires:
```
GET /api/v1/connect/refresh   → returns a fresh onboarding_url
```

The `account.updated` webhook keeps `PaymentAccount` in sync automatically.

---

## Pre-Production Test Gate

**12 automated checks must pass before touching live keys.**
This is a gate, not a suggestion.

```
CODE
  │
  ▼
php artisan stripe:test-escrow-flow   (12 automated checks)
  │
  ├─ FAIL → fix, re-run
  │
  └─ PASS
       │
       ▼
  Manual Stripe Dashboard verification
  (payments / connect accounts / transfers / webhooks)
       │
       ▼
  2 separate test users — full scenario manually
       │
       ├─ Normal payment → milestone → release
       ├─ Dispute → evidence → admin resolves → manual release/refund
       ├─ Declined card → verify escrow NOT funded
       ├─ Duplicate request → verify same PaymentIntent returned
       └─ Webhook delivery → verify events in Stripe Dashboard
       │
       ├─ ANY FAIL → fix
       │
       └─ ALL PASS
            │
            ▼
       Production infrastructure
       (PostgreSQL, queue workers, HTTPS, mail provider)
            │
            ▼
       Legal + monitoring + rate limits
       (ToS, Privacy Policy, Sentry, Supervisor)
            │
            ▼
       Live Stripe keys
            │
            ▼
       REAL USERS
```

### Running the automated checks

```bash
# Terminal 1 — app server
php artisan serve

# Terminal 2 — queue worker
php artisan queue:work

# Terminal 3 — webhook forwarding
stripe listen --forward-to localhost:8000/api/v1/webhooks/stripe

# Terminal 4 — run all 12 checks
php artisan stripe:test-escrow-flow
```

### What the 12 checks cover

| Step | What is tested |
|------|----------------|
| 1 | Demo accounts created |
| 2 | Freelancer Stripe Express account created |
| 3 | Contract + milestone created |
| 4 | PaymentIntent created → escrow funded |
| 5 | Milestone submitted |
| 6 | Milestone approved → Stripe transfer to freelancer |
| 7 | Transaction ledger: deposit → release sequence |
| 8 | Dispute raised → funds held, approve blocked |
| 9 | Admin resolves → release blocked after resolution, execute-resolution, idempotency, admin-only guard |
| 9b | Split resolution 70/30 → splitAmounts math, execution, idempotency, transactions, escrow accounting |
| 10 | Declined card → CardException, escrow NOT funded |
| 11 | Duplicate fund request → same PaymentIntent (idempotency) |
| 12 | Invalid webhook signature → rejected (400) |

### After automated checks — manual Stripe Dashboard verification

- `dashboard.stripe.com/test/payments` — payment exists with correct amount
- `dashboard.stripe.com/test/connect/accounts` — Express account exists and active
- `dashboard.stripe.com/test/transfers` — transfer to freelancer's account exists
- `dashboard.stripe.com/test/webhooks` — all 4 event types received and delivered

### Edge cases — manual test cards

| Scenario | Test value | Expected behaviour |
|----------|-----------|-------------------|
| Card declined | `pm_card_chargeDeclined` | CardException, escrow stays unfunded |
| Insufficient funds | `pm_card_visa_chargeDeclinedInsufficientFunds` | CardException |
| 3D Secure required | `pm_card_threeDSecure2Required` | `requires_action` status |
| Fraudulent | `pm_card_visa_chargeDeclinedFraudulent` | CardException |

Full list: [stripe.com/docs/testing](https://stripe.com/docs/testing)

---

## Production Checklist

12 tests passing = **software readiness**. It is not the same as **production readiness**.

### Infrastructure
- [ ] PostgreSQL or MySQL (not SQLite)
- [ ] `APP_ENV=production`, `APP_DEBUG=false`
- [ ] HTTPS only — valid TLS certificate
- [ ] Queue workers via Supervisor or Laravel Horizon
- [ ] Real mail provider (Mailgun / Postmark / SES)
- [ ] Pusher (or Soketi) for real-time notifications

### Stripe
- [ ] Live keys: `pk_live_` / `sk_live_`
- [ ] Webhook registered with correct production URL and all 5 events (`payment_intent.succeeded`, `transfer.created`, `charge.dispute.created`, `charge.refunded`, `account.updated`)
- [ ] Stripe platform application approved (required for Connect)
- [ ] Verify Stripe Connect availability for your country: [stripe.com/global](https://stripe.com/global)
- [ ] `STRIPE_PLATFORM_FEE_PERCENT` set intentionally (0 = no fee)
- [ ] Decide if split percentage increments need restricting (e.g. multiples of 5%) — add validation to `resolve` if so

### Legal
- [ ] Terms of Service
- [ ] Privacy Policy
- [ ] Dispute and Refund Policy (users must know the process)
- [ ] Legal review for jurisdiction-specific requirements

### Security
- [ ] Rate limiting on all payment endpoints
- [ ] CORS policy configured
- [ ] CSP headers

### Monitoring
- [ ] Error tracking (Sentry / Bugsnag)
- [ ] Uptime monitoring
- [ ] Failed job alerts (queue failures = stuck money)
- [ ] AI dispute suggestions reviewed manually before user-facing use

---

## API Reference

### Auth
| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/v1/register` | Register (`role: client\|freelancer`) |
| POST | `/api/v1/login` | Login → Sanctum token |
| POST | `/api/v1/logout` | Logout |
| GET  | `/api/v1/me` | Current user |

### Contracts
| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/v1/contracts` | Create contract |
| GET  | `/api/v1/contracts/{id}` | Get contract |
| POST | `/api/v1/contracts/{id}/send` | Send to freelancer |
| POST | `/api/v1/contracts/{id}/sign` | Sign contract |
| POST | `/api/v1/contracts/{id}/fund` | Fund escrow (client) |

### Milestones
| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/v1/milestones/{id}/submit` | Submit work (freelancer) |
| POST | `/api/v1/milestones/{id}/approve` | Approve work (client) |
| POST | `/api/v1/milestones/{id}/release` | Release funds to freelancer (client) |
| POST | `/api/v1/milestones/{id}/dispute` | Raise dispute |

### Disputes
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET   | `/api/v1/disputes/{id}` | View dispute + evidence thread |
| POST  | `/api/v1/disputes/{id}/evidence` | Submit evidence (file or message) |
| PATCH | `/api/v1/disputes/{id}/resolve` | Set resolution decision (admin only) |
| POST  | `/api/v1/disputes/{id}/execute-resolution` | Execute Stripe financial action (admin only) |
| POST  | `/api/v1/disputes/{id}/ai-summary` | AI-generated summary |
| POST  | `/api/v1/disputes/{id}/ai-suggest` | AI resolution suggestion (advisory) |

### Payments & Stripe Connect
| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/v1/connect/onboard` | Start Stripe Connect onboarding |
| GET  | `/api/v1/connect/return` | Stripe redirects here after onboarding |
| GET  | `/api/v1/connect/refresh` | Re-generate expired onboarding link |
| GET  | `/api/v1/transactions` | Transaction ledger |
| POST | `/api/v1/payouts/withdraw` | Freelancer withdraws to bank |
| POST | `/api/v1/webhooks/stripe` | Stripe webhook receiver (no auth) |

---

## What This Platform Does NOT Do

- **Does not hold funds in a platform bank account.** Money flows through Stripe directly to freelancer connected accounts.
- **Does not auto-release funds after dispute resolution.** Every money movement after a dispute is an explicit manual action.
- **Does not replace legal agreements.** The digital contract is a record. Consult a lawyer for jurisdiction-specific requirements.
- **Does not make final dispute decisions automatically.** AI suggestions are advisory only. A human admin resolves disputes.
