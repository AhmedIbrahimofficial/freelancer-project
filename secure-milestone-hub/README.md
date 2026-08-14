# Secure Milestone Hub

Build the complete frontend for a global freelancer payment protection platform. The product lets freelancers and clients create binding milestone contracts, resolve disputes fairly, build verifiable reputations, and (eventually) move real money through escrow — all without relying on a marketplace like Upwork/Fiverr.

This is a trust and money product. The UI must feel calm, credible, and precise at every step — closer to Stripe, Mercury, or a legal-tech product than a consumer app. Every interaction should reduce anxiety around money and disputes, never add to it.

Tech stack

React + Tailwind CSS + shadcn/ui. Framer Motion for interaction polish. Fully responsive, mobile-first where freelancers will act quickly (approving milestones, responding to disputes) and desktop-optimized for setup-heavy tasks (creating contracts, reviewing evidence).

Global design principles (apply everywhere)

Every binding/destructive action requires explicit confirmation — signing, disputing, releasing funds, accepting a resolution. Never a single accidental click.

State transitions are always visible — status badge changes get a brief highlight/pulse, never a silent refresh.

Loading = skeleton screens, not spinners, for anything showing data.

Errors are specific and actionable, never generic.

No dead ends — every empty/error/completed state has a clear next action.

Money and legal actions get their own visual weight — larger confirmation modals, explicit typed confirmations for signing/fund release, no ambiguity.

PHASE 1 — Contracts & Dispute Mediation (no payments)

Landing page

Value prop above the fold. Scroll-triggered mini animated walkthrough of contract → milestone → dispute → resolution.

Buttons have subtle press/scale micro-interaction (scale to 0.97, 100ms).

Auth

Role selection at signup (hiring / freelancing) — sets default dashboard view.

Real-time inline validation, animated in/out without layout jump.

Dashboard

Contract cards: status badge (Draft / Awaiting Signature / In Progress / Milestone Review / Disputed / Completed), party, amount, next action.

Hover elevation on cards. Status uses color + icon (not color alone).

Empty state: illustration + single CTA, never blank.

Instant client-side filter/sort with animated re-ordering.

Create Contract flow

Multi-step: Basic Info → Scope → Milestones → Review & Send. Animated progress stepper, back nav preserves data.

Milestone rows dynamically add (slide in) / remove (collapse out).

Live-updating total with brief highlight flash on change.

Review step renders as a clean contract-style document preview.

Contract Detail / Timeline

Vertical event timeline (created, signed, milestone events, disputes), each timestamped. Staggered entry animation on load.

Signing requires typed full-name confirmation + explicit confirm button — must feel binding.

Milestone actions (Approve / Request Changes / Dispute) each open a confirmation modal explaining consequences.

Dispute Flow

Structured evidence submission: explanation + file upload (drag-and-drop, per-file progress, preview thumbnail) + reference to contract terms.

Horizontal stepper: Submitted → Under Review → Response Requested → Resolved.

Shared, append-only evidence thread; new entries animate in with a "new" highlight.

Notifications

Toasts slide in top-right, auto-dismiss 5s, pause on hover.

Notification bell with unread badge, dropdown (not full page).

PHASE 2 — Reputation & Verification

Public profile pages

Verified badges (email/ID/payment method) — each badge shows what was verified and when on hover/tap, never just a generic checkmark.

Reputation summary: completion rate, on-time rate, dispute rate — shown as simple stat cards, not a single opaque "score" (transparency builds trust here; avoid black-box numbers).

History list of past contracts (counterparty name optional/anonymized per privacy settings), each with outcome badge.

Interaction additions

Verification flow: step-by-step (upload ID → liveness check placeholder → email confirm), same stepper pattern as dispute flow for visual consistency across the product.

Profile completeness meter that fills as sections are completed — encourages full verification without being pushy (progress bar, not nagging modals).

Dashboard changes

Contract cards now show counterparty's verification badge + reputation stat inline, so trust signals are visible before opening a contract.

PHASE 3 — Escrow & Payments

This phase introduces real money — the highest-stakes UI in the product. Treat every screen here with extra confirmation weight and clarity.

Fund a Contract

Payment method setup (card/bank/wallet, depends on backend — Stripe Connect / Wise / licensed partner).

Explicit "Fund Escrow" step, separate from contract signing — two distinct binding actions, never merged into one click.

Funds-held confirmation screen: clearly states amount, held-by (the escrow partner, named explicitly, not vague "we"), and release conditions.

Milestone Release Flow

Approve → funds release animation (a deliberate, visible transfer confirmation — not just a status text change; consider a brief progress/checkmark sequence since this is the moment of "getting paid").

Dispute freezes the fund release button with a clear explanation ("Funds held pending dispute resolution") — never leave the user wondering where their money is.

Transaction History

Full ledger view: every fund movement (deposited, held, released, refunded) with timestamp and reference to the triggering event (which milestone, which dispute).

Downloadable/exportable statement (for freelancers' own accounting/taxes) — a real, tangible feature, not decorative.

Withdrawal

Clear balance display (available vs. pending/held).

Withdrawal confirmation shows fees (if any) explicitly before confirming — never surprise deductions.

Interaction principles specific to this phase

No fund-related state is ever ambiguous — always show held / releasing / released / refunded explicitly, with the exact next event that will change it.

Every payment screen shows the escrow partner/processor name — never obscure who's actually holding the money (regulatory + trust requirement).

PHASE 4 — AI Dispute Assistant

AI-assisted evidence summary

On the dispute screen, an optional "AI Summary" panel condenses the evidence thread into a neutral summary — clearly labeled as AI-generated, with a one-click link back to the full raw evidence (never replaces the source record).

Suggested resolution: shown as a distinct, clearly-labeled card ("AI Suggestion — not binding"), with explicit Accept / Propose Different Terms actions. Never auto-applied.

Automated milestone verification (optional, where deliverables are checkable — e.g., code repos, file uploads)

Shows a checklist-style comparison: scope item → AI-detected match confidence → human confirm/override. Always human-confirmable, never fully automatic for fund release.

Interaction principles specific to this phase

AI output is always visually distinct from human/system content (different card style, consistent "AI" label) — critical in a trust product where users must always know what's a fact vs. a suggestion.

This project was built with [Lovable](https://lovable.dev).

## Build with Lovable

Continue developing this project in the [Lovable editor](https://lovable.dev/projects/aff935ca-a21d-43c6-95ce-01e959d0c88b).

- **Ship faster**: describe what you want to build and Lovable handles the code.
- **Stay in sync**: every change made in Lovable is committed straight to this repository.
- **Full ownership**: this code is yours. Push to `main` on GitHub and your changes sync back into Lovable, ready for your next prompt.

## Development

Prefer working locally? You need Node.js and npm — [install with nvm](https://github.com/nvm-sh/nvm#installing-and-updating).

```sh
git clone <this-repository-url>
cd <repository-name>
npm i
npm run dev
```
