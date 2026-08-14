export type Role = "client" | "freelancer";

export type ContractStatus =
  | "draft"
  | "awaiting_signature"
  | "in_progress"
  | "milestone_review"
  | "disputed"
  | "completed";

export type MilestoneStatus =
  | "pending"
  | "submitted"
  | "changes_requested"
  | "approved"
  | "disputed"
  | "released";

export type FundState = "unfunded" | "held" | "releasing" | "released" | "refunded";

export interface Party {
  handle: string;
  name: string;
  role: Role;
  initials: string;
}

export interface Milestone {
  id: string;
  title: string;
  description: string;
  amount: number;
  dueDate: string;
  status: MilestoneStatus;
  fundState: FundState;
  deliverables?: string[];
}

export interface ContractEvent {
  id: string;
  at: string;
  actor: string;
  type:
    | "created"
    | "sent"
    | "signed"
    | "funded"
    | "milestone_submitted"
    | "milestone_approved"
    | "milestone_released"
    | "changes_requested"
    | "dispute_opened"
    | "dispute_resolved"
    | "completed";
  label: string;
  detail?: string;
}

export interface Contract {
  id: string;
  reference: string;
  title: string;
  summary: string;
  currency: string;
  client: Party;
  freelancer: Party;
  status: ContractStatus;
  createdAt: string;
  scope: string;
  outOfScope: string;
  terms: string[];
  milestones: Milestone[];
  events: ContractEvent[];
  signedBy: string[];
  disputeId?: string;
}

export type DisputeStatus = "submitted" | "under_review" | "response_requested" | "resolved";

export interface EvidenceFile {
  name: string;
  size: string;
  kind: "image" | "doc" | "code" | "other";
}

export interface EvidenceEntry {
  id: string;
  authorHandle: string;
  authorName: string;
  side: "claimant" | "respondent" | "mediator";
  at: string;
  body: string;
  clause?: string;
  files: EvidenceFile[];
  isNew?: boolean;
}

export interface Dispute {
  id: string;
  contractId: string;
  milestoneId: string;
  reason: string;
  status: DisputeStatus;
  openedAt: string;
  openedBy: string;
  amountInDispute: number;
  entries: EvidenceEntry[];
  aiSummary?: { points: string[]; generatedAt: string };
  aiSuggestion?: { headline: string; rationale: string; split: { party: string; amount: number }[] };
}

export type LedgerType =
  | "deposit"
  | "hold"
  | "release"
  | "refund"
  | "withdrawal"
  | "fee"
  | "freeze";

export interface LedgerEntry {
  id: string;
  at: string;
  type: LedgerType;
  amount: number;
  currency: string;
  contractId?: string;
  contractTitle?: string;
  trigger: string;
  processor: string;
}

export interface Verification {
  kind: "email" | "identity" | "payment" | "business";
  label: string;
  verifiedAt: string;
  method: string;
}

export interface ProfileStats {
  contractsCompleted: number;
  completionRate: number;
  onTimeRate: number;
  disputeRate: number;
  avgReleaseDays: number;
  volume: number;
}

export interface HistoryItem {
  id: string;
  title: string;
  counterparty: string;
  anonymized: boolean;
  amount: number;
  outcome: "completed" | "completed_late" | "resolved_dispute" | "cancelled";
  closedAt: string;
}

export interface Profile {
  handle: string;
  name: string;
  role: Role;
  initials: string;
  headline: string;
  location: string;
  memberSince: string;
  bio: string;
  skills: string[];
  verifications: Verification[];
  stats: ProfileStats;
  history: HistoryItem[];
  completeness: { label: string; done: boolean }[];
}

export interface AppNotification {
  id: string;
  at: string;
  title: string;
  body: string;
  read: boolean;
  href?: string;
  tone: "info" | "warning" | "success";
}

export const ESCROW_PARTNER = "Modulr FS (licensed escrow partner)";
export const PROCESSOR = "Stripe Connect";

const you: Party = {
  handle: "maya-okonkwo",
  name: "Maya Okonkwo",
  role: "freelancer",
  initials: "MO",
};

export const counterparties = {
  northbeam: { handle: "northbeam", name: "Northbeam Studio", role: "client", initials: "NS" },
  luma: { handle: "luma-health", name: "Luma Health", role: "client", initials: "LH" },
  atlas: { handle: "atlas-freight", name: "Atlas Freight", role: "client", initials: "AF" },
} satisfies Record<string, Party>;

export const currentUser = you;

function ms(
  id: string,
  title: string,
  amount: number,
  dueDate: string,
  status: MilestoneStatus,
  fundState: FundState,
  description: string,
  deliverables: string[] = [],
): Milestone {
  return { id, title, amount, dueDate, status, fundState, description, deliverables };
}

export const seedContracts: Contract[] = [
  {
    id: "c-1042",
    reference: "ESC-1042",
    title: "Design system rebuild — Northbeam",
    summary: "Rebuild the marketing design system and ship a documented component library.",
    currency: "USD",
    client: counterparties.northbeam,
    freelancer: you,
    status: "milestone_review",
    createdAt: "2026-07-02T09:12:00Z",
    scope:
      "Audit existing marketing surfaces, define tokens (color, type, spacing), and deliver 24 documented components in Figma plus a React implementation of 12 primitives.",
    outOfScope: "Backend work, copywriting, ongoing maintenance after final milestone approval.",
    terms: [
      "Deliverables are reviewed within 5 business days of submission.",
      "Two rounds of revisions are included per milestone.",
      "Approved milestones are released from escrow within 24 hours.",
      "Either party may open a dispute within 14 days of a milestone submission.",
    ],
    milestones: [
      ms(
        "m-1",
        "Audit & token foundation",
        2400,
        "2026-07-14",
        "released",
        "released",
        "Written audit of 18 surfaces plus a token sheet covering color, type and spacing.",
        ["audit.pdf", "tokens.json"],
      ),
      ms(
        "m-2",
        "Component library in Figma",
        3600,
        "2026-08-06",
        "submitted",
        "held",
        "24 documented components with variants, states and usage notes.",
        ["library-v3.fig", "changelog.md"],
      ),
      ms(
        "m-3",
        "React implementation",
        4200,
        "2026-08-28",
        "pending",
        "held",
        "12 primitives implemented in React with Storybook coverage.",
      ),
    ],
    events: [
      {
        id: "e1",
        at: "2026-07-02T09:12:00Z",
        actor: "Northbeam Studio",
        type: "created",
        label: "Contract drafted",
        detail: "3 milestones · $10,200 total",
      },
      {
        id: "e2",
        at: "2026-07-02T15:40:00Z",
        actor: "Northbeam Studio",
        type: "sent",
        label: "Sent for signature",
      },
      {
        id: "e3",
        at: "2026-07-03T08:05:00Z",
        actor: "Maya Okonkwo",
        type: "signed",
        label: "Signed by both parties",
        detail: "Typed-name signature recorded for both parties",
      },
      {
        id: "e4",
        at: "2026-07-03T10:22:00Z",
        actor: ESCROW_PARTNER,
        type: "funded",
        label: "Escrow funded — $10,200 held",
        detail: `Held by ${ESCROW_PARTNER}`,
      },
      {
        id: "e5",
        at: "2026-07-13T17:31:00Z",
        actor: "Maya Okonkwo",
        type: "milestone_submitted",
        label: "Milestone 1 submitted",
      },
      {
        id: "e6",
        at: "2026-07-15T11:02:00Z",
        actor: "Northbeam Studio",
        type: "milestone_approved",
        label: "Milestone 1 approved",
      },
      {
        id: "e7",
        at: "2026-07-15T12:18:00Z",
        actor: ESCROW_PARTNER,
        type: "milestone_released",
        label: "$2,400 released to Maya Okonkwo",
      },
      {
        id: "e8",
        at: "2026-08-05T16:44:00Z",
        actor: "Maya Okonkwo",
        type: "milestone_submitted",
        label: "Milestone 2 submitted for review",
        detail: "2 files attached",
      },
    ],
    signedBy: ["Maya Okonkwo", "Northbeam Studio"],
  },
  {
    id: "c-1051",
    reference: "ESC-1051",
    title: "Patient onboarding flow — Luma Health",
    summary: "Research, design and prototype a compliant patient onboarding flow.",
    currency: "USD",
    client: counterparties.luma,
    freelancer: you,
    status: "disputed",
    createdAt: "2026-06-11T12:00:00Z",
    scope:
      "Discovery interviews (6), journey map, and a clickable prototype of the onboarding flow covering consent, insurance capture and scheduling.",
    outOfScope: "Clinical copy review, HIPAA legal sign-off, engineering handoff sessions beyond one.",
    terms: [
      "Prototype must cover the three flows listed in scope.",
      "Client provides interview participants within 10 days of signing.",
      "Disputes are mediated by Escrowa within 7 business days.",
    ],
    milestones: [
      ms(
        "m-1",
        "Discovery & journey map",
        1800,
        "2026-06-25",
        "released",
        "released",
        "Six interviews synthesised into a journey map with prioritised friction points.",
      ),
      ms(
        "m-2",
        "Clickable prototype",
        3200,
        "2026-07-20",
        "disputed",
        "held",
        "Clickable prototype covering consent, insurance capture and scheduling.",
        ["prototype-link.txt", "flow-coverage.pdf"],
      ),
    ],
    events: [
      {
        id: "e1",
        at: "2026-06-11T12:00:00Z",
        actor: "Luma Health",
        type: "created",
        label: "Contract drafted",
      },
      {
        id: "e2",
        at: "2026-06-12T09:30:00Z",
        actor: "Maya Okonkwo",
        type: "signed",
        label: "Signed by both parties",
      },
      {
        id: "e3",
        at: "2026-06-12T10:00:00Z",
        actor: ESCROW_PARTNER,
        type: "funded",
        label: "Escrow funded — $5,000 held",
      },
      {
        id: "e4",
        at: "2026-06-24T18:10:00Z",
        actor: "Maya Okonkwo",
        type: "milestone_released",
        label: "$1,800 released for milestone 1",
      },
      {
        id: "e5",
        at: "2026-07-19T21:02:00Z",
        actor: "Maya Okonkwo",
        type: "milestone_submitted",
        label: "Milestone 2 submitted",
      },
      {
        id: "e6",
        at: "2026-07-24T14:26:00Z",
        actor: "Luma Health",
        type: "dispute_opened",
        label: "Dispute opened on milestone 2",
        detail: "Scheduling flow reported as incomplete · $3,200 frozen",
      },
    ],
    signedBy: ["Maya Okonkwo", "Luma Health"],
    disputeId: "d-88",
  },
  {
    id: "c-1063",
    reference: "ESC-1063",
    title: "Logistics dashboard audit — Atlas Freight",
    summary: "Two-week audit of the internal logistics dashboard with a prioritised fix list.",
    currency: "USD",
    client: counterparties.atlas,
    freelancer: you,
    status: "awaiting_signature",
    createdAt: "2026-08-08T07:45:00Z",
    scope: "Heuristic audit of 9 dashboard views, prioritised issue list, and a 1-hour walkthrough.",
    outOfScope: "Implementation of fixes, user testing recruitment.",
    terms: [
      "Audit delivered as a single document within 14 days of funding.",
      "Escrow is funded before work begins.",
    ],
    milestones: [
      ms(
        "m-1",
        "Audit report & walkthrough",
        2800,
        "2026-08-26",
        "pending",
        "unfunded",
        "Prioritised issue list with severity ratings and a recorded walkthrough.",
      ),
    ],
    events: [
      {
        id: "e1",
        at: "2026-08-08T07:45:00Z",
        actor: "Atlas Freight",
        type: "created",
        label: "Contract drafted",
      },
      {
        id: "e2",
        at: "2026-08-08T08:02:00Z",
        actor: "Atlas Freight",
        type: "sent",
        label: "Sent to you for signature",
        detail: "Awaiting your typed-name signature",
      },
    ],
    signedBy: ["Atlas Freight"],
  },
  {
    id: "c-0988",
    reference: "ESC-0988",
    title: "Brand refresh — Northbeam",
    summary: "Logo refinement, palette and a one-page brand guide.",
    currency: "USD",
    client: counterparties.northbeam,
    freelancer: you,
    status: "completed",
    createdAt: "2026-04-02T10:00:00Z",
    scope: "Logo refinement, palette, type pairing and a one-page brand guide.",
    outOfScope: "Full brand book, motion assets.",
    terms: ["Final files delivered on approval of the brand guide."],
    milestones: [
      ms("m-1", "Concepts", 1200, "2026-04-16", "released", "released", "Three logo directions."),
      ms(
        "m-2",
        "Brand guide",
        1600,
        "2026-04-30",
        "released",
        "released",
        "One-page brand guide with final files.",
      ),
    ],
    events: [
      {
        id: "e1",
        at: "2026-04-02T10:00:00Z",
        actor: "Northbeam Studio",
        type: "created",
        label: "Contract drafted",
      },
      {
        id: "e2",
        at: "2026-04-03T09:00:00Z",
        actor: "Maya Okonkwo",
        type: "signed",
        label: "Signed by both parties",
      },
      {
        id: "e3",
        at: "2026-05-01T16:20:00Z",
        actor: ESCROW_PARTNER,
        type: "completed",
        label: "Contract completed — $2,800 released in full",
      },
    ],
    signedBy: ["Maya Okonkwo", "Northbeam Studio"],
  },
];

export const seedDisputes: Dispute[] = [
  {
    id: "d-88",
    contractId: "c-1051",
    milestoneId: "m-2",
    reason: "Deliverable does not cover the scheduling flow listed in scope item 3.",
    status: "response_requested",
    openedAt: "2026-07-24T14:26:00Z",
    openedBy: "Luma Health",
    amountInDispute: 3200,
    entries: [
      {
        id: "ev-1",
        authorHandle: "luma-health",
        authorName: "Luma Health",
        side: "claimant",
        at: "2026-07-24T14:26:00Z",
        body: "The prototype covers consent and insurance capture, but the scheduling screens are placeholders. Scope item 3 requires a clickable scheduling flow with confirmation.",
        clause: "Scope — item 3: scheduling flow",
        files: [
          { name: "prototype-screens.png", size: "1.8 MB", kind: "image" },
          { name: "scope-highlight.pdf", size: "240 KB", kind: "doc" },
        ],
      },
      {
        id: "ev-2",
        authorHandle: "escrowa",
        authorName: "Escrowa Mediation",
        side: "mediator",
        at: "2026-07-25T09:10:00Z",
        body: "Dispute accepted for review. The freelancer has 5 business days to respond with evidence. $3,200 remains frozen in escrow until resolution.",
        files: [],
      },
      {
        id: "ev-3",
        authorHandle: "maya-okonkwo",
        authorName: "Maya Okonkwo",
        side: "respondent",
        at: "2026-07-26T11:48:00Z",
        body: "Scheduling screens were delivered in v4 of the prototype after the client asked to defer them on 8 July (email attached). Deferral was agreed in writing, so the milestone was submitted against the amended scope.",
        clause: "Terms — written amendments",
        files: [
          { name: "email-thread-08-jul.pdf", size: "310 KB", kind: "doc" },
          { name: "prototype-v4-scheduling.png", size: "2.1 MB", kind: "image" },
        ],
      },
    ],
    aiSummary: {
      generatedAt: "2026-07-27T08:00:00Z",
      points: [
        "Both parties agree consent and insurance capture were delivered as specified.",
        "The dispute turns on whether the scheduling flow was deferred by written agreement on 8 July.",
        "The freelancer submitted an email thread that appears to show the client requesting deferral; the client has not yet addressed that thread.",
        "No party disputes the milestone amount of $3,200.",
      ],
    },
    aiSuggestion: {
      headline: "Release $2,400 now, hold $800 against delivery of the scheduling flow",
      rationale:
        "Two of three scope items are undisputed and delivered. The scheduling item is contested but supported by written deferral evidence, so a partial hold keeps both parties whole while the remaining screens are completed.",
      split: [
        { party: "Maya Okonkwo (release now)", amount: 2400 },
        { party: "Held pending scheduling flow", amount: 800 },
      ],
    },
  },
];

export const seedLedger: LedgerEntry[] = [
  {
    id: "l-1",
    at: "2026-07-03T10:22:00Z",
    type: "deposit",
    amount: 10200,
    currency: "USD",
    contractId: "c-1042",
    contractTitle: "Design system rebuild — Northbeam",
    trigger: "Escrow funded by Northbeam Studio",
    processor: PROCESSOR,
  },
  {
    id: "l-2",
    at: "2026-07-03T10:23:00Z",
    type: "hold",
    amount: 10200,
    currency: "USD",
    contractId: "c-1042",
    contractTitle: "Design system rebuild — Northbeam",
    trigger: "Funds held against milestones 1–3",
    processor: ESCROW_PARTNER,
  },
  {
    id: "l-3",
    at: "2026-07-15T12:18:00Z",
    type: "release",
    amount: 2400,
    currency: "USD",
    contractId: "c-1042",
    contractTitle: "Design system rebuild — Northbeam",
    trigger: "Milestone 1 approved — Audit & token foundation",
    processor: ESCROW_PARTNER,
  },
  {
    id: "l-4",
    at: "2026-07-15T12:19:00Z",
    type: "fee",
    amount: 48,
    currency: "USD",
    contractId: "c-1042",
    contractTitle: "Design system rebuild — Northbeam",
    trigger: "Platform fee 2% on released milestone",
    processor: PROCESSOR,
  },
  {
    id: "l-5",
    at: "2026-06-12T10:00:00Z",
    type: "deposit",
    amount: 5000,
    currency: "USD",
    contractId: "c-1051",
    contractTitle: "Patient onboarding flow — Luma Health",
    trigger: "Escrow funded by Luma Health",
    processor: PROCESSOR,
  },
  {
    id: "l-6",
    at: "2026-06-24T18:10:00Z",
    type: "release",
    amount: 1800,
    currency: "USD",
    contractId: "c-1051",
    contractTitle: "Patient onboarding flow — Luma Health",
    trigger: "Milestone 1 approved — Discovery & journey map",
    processor: ESCROW_PARTNER,
  },
  {
    id: "l-7",
    at: "2026-07-24T14:26:00Z",
    type: "freeze",
    amount: 3200,
    currency: "USD",
    contractId: "c-1051",
    contractTitle: "Patient onboarding flow — Luma Health",
    trigger: "Dispute D-88 opened on milestone 2",
    processor: ESCROW_PARTNER,
  },
  {
    id: "l-8",
    at: "2026-05-02T09:00:00Z",
    type: "withdrawal",
    amount: 2744,
    currency: "USD",
    trigger: "Withdrawal to Wise ••4471",
    processor: PROCESSOR,
  },
];

export const seedProfiles: Profile[] = [
  {
    handle: "maya-okonkwo",
    name: "Maya Okonkwo",
    role: "freelancer",
    initials: "MO",
    headline: "Product designer — design systems & onboarding flows",
    location: "Lagos, Nigeria",
    memberSince: "2024-11-02",
    bio: "I design and build design systems for product teams. 9 years in-house before going independent. I work in fixed milestones with written scope, always.",
    skills: ["Design systems", "Onboarding UX", "Figma", "React", "Research"],
    verifications: [
      {
        kind: "email",
        label: "Email verified",
        verifiedAt: "2024-11-02",
        method: "Confirmation link sent to m•••@okonkwo.design",
      },
      {
        kind: "identity",
        label: "Government ID verified",
        verifiedAt: "2025-01-18",
        method: "Passport matched to a liveness check via Persona",
      },
      {
        kind: "payment",
        label: "Payout method verified",
        verifiedAt: "2025-01-20",
        method: "Bank account ownership confirmed via micro-deposit",
      },
    ],
    stats: {
      contractsCompleted: 27,
      completionRate: 96,
      onTimeRate: 91,
      disputeRate: 4,
      avgReleaseDays: 1.4,
      volume: 184300,
    },
    history: [
      {
        id: "h-1",
        title: "Brand refresh",
        counterparty: "Northbeam Studio",
        anonymized: false,
        amount: 2800,
        outcome: "completed",
        closedAt: "2026-05-01",
      },
      {
        id: "h-2",
        title: "Checkout redesign",
        counterparty: "Client (name hidden)",
        anonymized: true,
        amount: 7400,
        outcome: "completed",
        closedAt: "2026-03-14",
      },
      {
        id: "h-3",
        title: "Mobile app audit",
        counterparty: "Fielding Labs",
        anonymized: false,
        amount: 3100,
        outcome: "completed_late",
        closedAt: "2026-01-29",
      },
      {
        id: "h-4",
        title: "Marketing site build",
        counterparty: "Client (name hidden)",
        anonymized: true,
        amount: 5200,
        outcome: "resolved_dispute",
        closedAt: "2025-11-08",
      },
    ],
    completeness: [
      { label: "Profile basics", done: true },
      { label: "Email verified", done: true },
      { label: "Government ID verified", done: true },
      { label: "Payout method verified", done: true },
      { label: "Portfolio links", done: false },
      { label: "Business entity verified", done: false },
    ],
  },
  {
    handle: "northbeam",
    name: "Northbeam Studio",
    role: "client",
    initials: "NS",
    headline: "Brand and product studio — 14 people, Berlin",
    location: "Berlin, Germany",
    memberSince: "2025-02-11",
    bio: "We hire independent designers and engineers for scoped project work. We fund escrow up front and review within 3 business days.",
    skills: ["Brand", "Marketing sites", "Design systems"],
    verifications: [
      {
        kind: "email",
        label: "Email verified",
        verifiedAt: "2025-02-11",
        method: "Confirmation link sent to o•••@northbeam.studio",
      },
      {
        kind: "business",
        label: "Business entity verified",
        verifiedAt: "2025-02-19",
        method: "German commercial register (HRB) record matched",
      },
      {
        kind: "payment",
        label: "Funding method verified",
        verifiedAt: "2025-02-19",
        method: "Company bank account confirmed via open banking",
      },
    ],
    stats: {
      contractsCompleted: 41,
      completionRate: 98,
      onTimeRate: 95,
      disputeRate: 2,
      avgReleaseDays: 0.8,
      volume: 412000,
    },
    history: [
      {
        id: "h-1",
        title: "Brand refresh",
        counterparty: "Maya Okonkwo",
        anonymized: false,
        amount: 2800,
        outcome: "completed",
        closedAt: "2026-05-01",
      },
      {
        id: "h-2",
        title: "Illustration set",
        counterparty: "Freelancer (name hidden)",
        anonymized: true,
        amount: 1900,
        outcome: "completed",
        closedAt: "2026-02-20",
      },
    ],
    completeness: [
      { label: "Profile basics", done: true },
      { label: "Email verified", done: true },
      { label: "Business entity verified", done: true },
      { label: "Funding method verified", done: true },
      { label: "Team members", done: false },
      { label: "Standard terms template", done: false },
    ],
  },
  {
    handle: "luma-health",
    name: "Luma Health",
    role: "client",
    initials: "LH",
    headline: "Digital care platform — Toronto",
    location: "Toronto, Canada",
    memberSince: "2025-09-30",
    bio: "Care navigation platform. We work with independent researchers and designers on scoped engagements.",
    skills: ["Healthcare", "Research", "Product"],
    verifications: [
      {
        kind: "email",
        label: "Email verified",
        verifiedAt: "2025-09-30",
        method: "Confirmation link sent to p•••@lumahealth.ca",
      },
      {
        kind: "payment",
        label: "Funding method verified",
        verifiedAt: "2025-10-02",
        method: "Company card verified via Stripe Connect",
      },
    ],
    stats: {
      contractsCompleted: 12,
      completionRate: 83,
      onTimeRate: 74,
      disputeRate: 17,
      avgReleaseDays: 4.6,
      volume: 96500,
    },
    history: [
      {
        id: "h-1",
        title: "Care plan UX",
        counterparty: "Freelancer (name hidden)",
        anonymized: true,
        amount: 4400,
        outcome: "resolved_dispute",
        closedAt: "2026-02-02",
      },
      {
        id: "h-2",
        title: "Design QA sprint",
        counterparty: "Freelancer (name hidden)",
        anonymized: true,
        amount: 1500,
        outcome: "completed",
        closedAt: "2025-12-12",
      },
    ],
    completeness: [
      { label: "Profile basics", done: true },
      { label: "Email verified", done: true },
      { label: "Funding method verified", done: true },
      { label: "Business entity verified", done: false },
      { label: "Standard terms template", done: false },
      { label: "Team members", done: false },
    ],
  },
  {
    handle: "atlas-freight",
    name: "Atlas Freight",
    role: "client",
    initials: "AF",
    headline: "Freight operations software — Rotterdam",
    location: "Rotterdam, Netherlands",
    memberSince: "2026-07-21",
    bio: "Operations software for mid-size freight carriers.",
    skills: ["Logistics", "Dashboards"],
    verifications: [
      {
        kind: "email",
        label: "Email verified",
        verifiedAt: "2026-07-21",
        method: "Confirmation link sent to k•••@atlasfreight.nl",
      },
    ],
    stats: {
      contractsCompleted: 1,
      completionRate: 100,
      onTimeRate: 100,
      disputeRate: 0,
      avgReleaseDays: 2.1,
      volume: 3400,
    },
    history: [
      {
        id: "h-1",
        title: "Ops copy audit",
        counterparty: "Freelancer (name hidden)",
        anonymized: true,
        amount: 3400,
        outcome: "completed",
        closedAt: "2026-08-01",
      },
    ],
    completeness: [
      { label: "Profile basics", done: true },
      { label: "Email verified", done: true },
      { label: "Funding method verified", done: false },
      { label: "Business entity verified", done: false },
      { label: "Standard terms template", done: false },
      { label: "Team members", done: false },
    ],
  },
];

export const seedNotifications: AppNotification[] = [
  {
    id: "n-1",
    at: "2026-08-11T09:02:00Z",
    title: "Response requested — Dispute D-88",
    body: "Luma Health has 3 days left to respond. $3,200 stays frozen until resolution.",
    read: false,
    href: "/disputes/d-88",
    tone: "warning",
  },
  {
    id: "n-2",
    at: "2026-08-10T14:20:00Z",
    title: "Milestone 2 awaiting review",
    body: "Northbeam Studio has until 12 Aug to review “Component library in Figma”.",
    read: false,
    href: "/contracts/c-1042",
    tone: "info",
  },
  {
    id: "n-3",
    at: "2026-08-08T08:02:00Z",
    title: "Contract awaiting your signature",
    body: "Atlas Freight sent ESC-1063 for signature.",
    read: false,
    href: "/contracts/c-1063",
    tone: "info",
  },
  {
    id: "n-4",
    at: "2026-07-15T12:18:00Z",
    title: "$2,400 released to your balance",
    body: "Milestone 1 of ESC-1042 was approved and released.",
    read: true,
    href: "/wallet",
    tone: "success",
  },
];

export function money(amount: number, currency = "USD") {
  return new Intl.NumberFormat("en-US", {
    style: "currency",
    currency,
    maximumFractionDigits: amount % 1 === 0 ? 0 : 2,
  }).format(amount);
}

export function contractTotal(c: Contract) {
  return c.milestones.reduce((sum, m) => sum + m.amount, 0);
}

export function heldAmount(c: Contract) {
  return c.milestones
    .filter((m) => m.fundState === "held" || m.fundState === "releasing")
    .reduce((sum, m) => sum + m.amount, 0);
}
