# Freelancer Payment Protection Platform

An escrow and dispute-resolution platform for freelancers and clients working directly (outside marketplaces like Upwork/Fiverr). Freelancers working cross-border, direct-contract deals have no neutral way to secure payment or resolve disputes fairly — this platform fixes that with binding milestone contracts, transparent dispute mediation, and secure escrow payments.

---

## Table of Contents
- [What This Does](#what-this-does)
- [How It Works](#how-it-works)
- [Tech Stack](#tech-stack)
- [Features by Phase](#features-by-phase)
- [What's Working](#whats-working)
- [What's NOT Working / TODO](#whats-not-working--todo)
- [Local Setup](#local-setup)
- [Environment Variables](#environment-variables)
- [Testing](#testing)
- [API Overview](#api-overview)
- [Database Schema](#database-schema)

---

## What This Does

Two people — a client and a freelancer — who've never worked together and are based in different countries can:
1. Create a binding milestone-based contract, digitally signed by both parties
2. Fund the contract through escrow (money held securely, not paid directly)
3. Have the freelancer submit work per milestone, and the client approve or dispute it
4. If disputed, submit evidence and reach a fair resolution — with an optional AI-generated neutral summary
5. Release payment automatically once milestones are approved
6. Build a public reputation profile based on completed contracts

The core idea: neither party has to "just trust" the other. The platform is the neutral third party holding both the money and the record.

---

## How It Works — Step by Step

**1. Contract creation**
A client (or freelancer) creates a contract: scope of work, one or more milestones each with an amount and due date. Both parties must digitally sign (typed full name + timestamp + IP address recorded) before the contract becomes active — this creates a legally meaningful, tamper-evident record.

**2. Funding escrow**
Once signed, the client funds the contract via Stripe Connect. The money is held by Stripe (not by the platform directly) — the platform only tracks status (held/released/refunded), it never custodies funds itself. This keeps the platform out of money-transmitter licensing territory.

**3. Milestone work**
The freelancer submits completed work against a milestone. The client reviews and either:
- **Approves** → triggers an automatic Stripe transfer releasing that milestone's funds to the freelancer
- **Disputes** → the milestone is locked, and both parties enter the dispute flow

**4. Dispute resolution**
Either party can submit evidence (text explanation + files) to a shared, append-only evidence thread. An AI-generated neutral summary and non-binding suggested resolution can be requested (clearly labeled as AI, never auto-applied). A human mediator/admin makes the final call, which updates the milestone and releases or refunds funds accordingly.

**5. Reputation**
After each contract, both parties' completion rate, dispute rate, and on-time rate update automatically, visible on their public profile — so future counterparties can see a track record before agreeing to work together.

**6. Real-time updates**
Every status change (signature, milestone approval, dispute raised, funds released) broadcasts live to both parties via Pusher — no manual refreshing needed.

---

## Tech Stack

**Backend:** PHP (Laravel) + MySQL
**Frontend:** React + TypeScript + Tailwind CSS + TanStack Query
**Payments/Escrow:** Stripe Connect (Express accounts)
**Real-time:** Pusher (WebSocket broadcasting)
**Email:** Mailgun/Postmark (via Laravel Mail)
**AI dispute assistance:** Anthropic Claude API
**Auth:** Laravel Sanctum (API token auth)
**Testing:** Pest/PHPUnit

**Key Laravel packages:**
| Package | Purpose |
|---|---|
| `laravel/sanctum` | API token authentication |
| `spatie/laravel-permission` | Role management (freelancer/client/admin) |
| `spatie/laravel-activitylog` | Full audit trail on all binding actions |
| `spatie/laravel-medialibrary` | Evidence file / document uploads (S3-backed) |
| `barryvdh/laravel-dompdf` | Signed contract PDF generation |
| `pusher/pusher-php-server` | Real-time broadcasting |

---

## Features by Phase

**Phase 1 — Contracts & Dispute Mediation**
Milestone contract creation, digital signing, milestone submit/approve/dispute, evidence submission, admin-mediated resolution. No money movement yet.

**Phase 2 — Reputation & Verification**
Verified badges (email/ID), public reputation stats (completion rate, dispute rate, on-time rate), computed via a queued job after each contract completes.

**Phase 3 — Escrow & Payments**
Stripe Connect integration: fund escrow, automatic milestone-triggered release, freelancer payouts, full transaction ledger, webhook-driven state (never trusts client-side confirmation).

**Phase 4 — AI Dispute Assistant**
Claude-generated neutral evidence summaries and non-binding suggested resolutions, clearly labeled as AI-generated and never auto-applied to a dispute outcome.

---

## What's Working

- ✅ Full contract lifecycle: creation → signing → milestone submit/approve → completion
- ✅ Dispute flow: raise → evidence submission → admin resolution
- ✅ 70 passing automated tests covering contract signing, milestone flow, disputes, authorization, and activity logging
- ✅ Frontend fully wired to the real backend API (no more mock data) — typed API client, Sanctum token auth, TanStack Query for data fetching, real loading/error states
- ✅ 6 transactional email types live (signature request, contract signed, milestone submitted/approved, dispute raised/resolved)
- ✅ Real-time updates via Pusher on private per-contract channels — both parties see live status changes without refreshing
- ✅ Stripe Connect escrow flow working end-to-end **in test mode**: fund contract → approve milestone → automatic transfer → freelancer withdrawal, with idempotency keys and webhook-driven state updates
- ✅ Full audit trail via activity log on every signature, approval, and dispute action

## What's NOT Working / TODO

- ⚠️ **Stripe is in TEST MODE ONLY** — live keys (`STRIPE_KEY`, `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`) are not yet configured; no real money can move until these are set and the full flow is re-verified in production
- ⚠️ **No legal review yet** — Terms of Service defining the platform as a facilitator (not a bank/escrow license holder) needs a real lawyer's review before accepting real transactions
- ⚠️ **AI dispute summaries (Phase 4)** are wired to the Anthropic API but should be spot-checked for output quality/appropriateness before relying on them in live disputes
- ⚠️ **No production deployment yet** — currently runs locally only; needs a real server, production Redis/queue worker setup, and a real domain for Stripe webhooks to reach
- ⚠️ **No rate limiting audit done** — throttle middleware is in place on auth/payment routes by default Laravel config, but hasn't been stress-tested
- ⚠️ **No monitoring/alerting configured** — Laravel Horizon is installed for queue monitoring but no external uptime/error alerting (e.g., Sentry) is set up yet

---

## Local Setup

```bash
composer create-project laravel/laravel freelancer-protect
cd freelancer-protect
composer install
cp .env.example .env
php artisan key:generate
```

Configure `.env` with your local MySQL database, then:
```bash
php artisan migrate
php artisan db:seed
php artisan serve
```

For queues (required for emails, AI dispute summaries, and Stripe webhook processing):
```bash
php artisan queue:work
```

For real-time events, configure Pusher credentials in `.env`, then on the frontend the Echo client will connect automatically on login.

For Stripe webhook testing locally, use the Stripe CLI:
```bash
stripe listen --forward-to localhost:8000/api/v1/webhooks/stripe
```

---

## Environment Variables

Key variables required in `.env`:

```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=freelancer_protect
DB_USERNAME=root
DB_PASSWORD=

STRIPE_KEY=pk_test_...
STRIPE_SECRET=sk_test_...
STRIPE_WEBHOOK_SECRET=whsec_...

PUSHER_APP_ID=
PUSHER_APP_KEY=
PUSHER_APP_SECRET=

MAIL_MAILER=mailgun (or postmark)
MAILGUN_DOMAIN=
MAILGUN_SECRET=

ANTHROPIC_API_KEY=
```

---

## Testing

```bash
php artisan test
```

70 tests currently passing across:
- `ContractSigningTest` — signature requirements, dual-signing, edit-lock after signing
- `MilestoneFlowTest` — submit/approve authorization, event dispatch, double-approval prevention
- `DisputeFlowTest` — dispute locking, evidence append-only rules, admin-only resolution
- `AuthorizationTest` — non-party access blocked, role-based permissions
- `ActivityLogTest` — every binding action produces an audit log entry

---

## API Overview

All endpoints under `/api/v1/`, Sanctum token auth required except registration/login.

```
POST   /contracts                    Create contract (draft)
GET    /contracts/{id}               Contract detail + timeline
POST   /contracts/{id}/send          Send to counterparty
POST   /contracts/{id}/sign          Sign (records signature)

POST   /milestones/{id}/submit       Freelancer submits work
POST   /milestones/{id}/approve      Client approves → triggers fund release
POST   /milestones/{id}/dispute      Raise dispute

POST   /disputes/{id}/evidence       Submit evidence
PATCH  /disputes/{id}/resolve        Admin/mediator resolves

POST   /contracts/{id}/fund          Fund escrow (Stripe PaymentIntent)
POST   /milestones/{id}/release      Release held funds (usually automatic on approval)
POST   /webhooks/stripe              Stripe webhook handler
GET    /transactions                 Transaction ledger
POST   /payouts/withdraw             Freelancer withdrawal

GET    /users/{id}/profile           Public profile + reputation stats
GET    /dashboard                    Contract list w/ filters
```

---

## Database Schema (15 tables)

`users`, `contracts`, `milestones`, `contract_signatures`, `disputes`, `dispute_evidence`, `verifications`, `reputation_stats`, `payment_accounts`, `escrow_balances`, `transactions`, `ai_dispute_summaries`, plus Spatie's permission/role tables and the activity log table.

---

## Security Notes

- All payment state changes are driven by verified Stripe webhooks — the frontend/client is never trusted to confirm a payment succeeded
- Fund-release logic is wrapped in database transactions to prevent partial-failure states
- Idempotency keys used on all Stripe mutation calls to prevent double-charge/double-release on retry
- Full activity log audit trail on every signature, approval, and dispute action
