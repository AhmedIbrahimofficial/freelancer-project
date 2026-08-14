import { useEffect, useRef } from "react";
import {
  AlertTriangle,
  ArrowUpRight,
  BadgeCheck,
  CheckCircle2,
  CircleDashed,
  FileEdit,
  Gavel,
  Lock,
  PenLine,
  RefreshCw,
  Snowflake,
  Timer,
} from "lucide-react";
import { cn } from "@/lib/utils";
import type { ContractStatus, FundState, MilestoneStatus } from "@/lib/mock-data";

type Tone = "neutral" | "info" | "warning" | "danger" | "success" | "primary";

const toneClass: Record<Tone, string> = {
  neutral: "bg-muted text-muted-foreground border-border",
  info: "bg-info/10 text-info border-info/25",
  warning: "bg-warning/15 text-warning-foreground border-warning/40",
  danger: "bg-destructive/10 text-destructive border-destructive/25",
  success: "bg-success/10 text-success border-success/25",
  primary: "bg-primary/10 text-primary border-primary/25",
};

export const contractStatusMeta: Record<
  ContractStatus,
  { label: string; tone: Tone; Icon: typeof CheckCircle2 }
> = {
  draft: { label: "Draft", tone: "neutral", Icon: FileEdit },
  awaiting_signature: { label: "Awaiting signature", tone: "warning", Icon: PenLine },
  in_progress: { label: "In progress", tone: "info", Icon: Timer },
  milestone_review: { label: "Milestone review", tone: "primary", Icon: CircleDashed },
  disputed: { label: "Disputed", tone: "danger", Icon: Gavel },
  completed: { label: "Completed", tone: "success", Icon: BadgeCheck },
};

export const milestoneStatusMeta: Record<
  MilestoneStatus,
  { label: string; tone: Tone; Icon: typeof CheckCircle2 }
> = {
  pending: { label: "Not started", tone: "neutral", Icon: CircleDashed },
  submitted: { label: "Awaiting review", tone: "primary", Icon: Timer },
  changes_requested: { label: "Changes requested", tone: "warning", Icon: RefreshCw },
  approved: { label: "Approved", tone: "info", Icon: CheckCircle2 },
  disputed: { label: "Disputed", tone: "danger", Icon: AlertTriangle },
  released: { label: "Released", tone: "success", Icon: BadgeCheck },
};

export const fundStateMeta: Record<
  FundState,
  { label: string; tone: Tone; Icon: typeof CheckCircle2; next: string }
> = {
  unfunded: {
    label: "Not funded",
    tone: "neutral",
    Icon: CircleDashed,
    next: "Next: client funds escrow",
  },
  held: {
    label: "Held in escrow",
    tone: "info",
    Icon: Lock,
    next: "Next: release on milestone approval",
  },
  releasing: {
    label: "Releasing",
    tone: "primary",
    Icon: ArrowUpRight,
    next: "Next: arrives in your balance",
  },
  released: {
    label: "Released",
    tone: "success",
    Icon: BadgeCheck,
    next: "Next: withdraw to your bank",
  },
  refunded: { label: "Refunded", tone: "warning", Icon: Snowflake, next: "Next: none — closed" },
};

interface BadgeProps {
  label: string;
  tone: Tone;
  Icon: typeof CheckCircle2;
  className?: string | undefined;
  pulseKey?: string | undefined;
}

function BaseBadge({ label, tone, Icon, className, pulseKey }: BadgeProps) {
  const ref = useRef<HTMLSpanElement>(null);
  const first = useRef(true);
  useEffect(() => {
    if (first.current) {
      first.current = false;
      return;
    }
    const el = ref.current;
    if (!el) return;
    el.classList.remove("status-pulse");
    void el.offsetWidth;
    el.classList.add("status-pulse");
  }, [pulseKey]);

  return (
    <span
      ref={ref}
      className={cn(
        "inline-flex shrink-0 items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-medium",
        toneClass[tone],
        className,
      )}
    >
      <Icon className="h-3.5 w-3.5" aria-hidden />
      {label}
    </span>
  );
}

export function ContractStatusBadge({
  status,
  className,
}: {
  status: ContractStatus;
  className?: string | undefined;
}) {
  const meta = contractStatusMeta[status];
  return <BaseBadge {...meta} className={className} pulseKey={status} />;
}

export function MilestoneStatusBadge({
  status,
  className,
}: {
  status: MilestoneStatus;
  className?: string | undefined;
}) {
  const meta = milestoneStatusMeta[status];
  return <BaseBadge {...meta} className={className} pulseKey={status} />;
}

export function FundStateBadge({
  state,
  className,
}: {
  state: FundState;
  className?: string | undefined;
}) {
  const meta = fundStateMeta[state];
  return (
    <BaseBadge
      label={meta.label}
      tone={meta.tone}
      Icon={meta.Icon}
      className={className}
      pulseKey={state}
    />
  );
}

export function OutcomeBadge({ outcome }: { outcome: string }) {
  const map: Record<string, { label: string; tone: Tone; Icon: typeof CheckCircle2 }> = {
    completed: { label: "Completed", tone: "success", Icon: BadgeCheck },
    completed_late: { label: "Completed late", tone: "warning", Icon: Timer },
    resolved_dispute: { label: "Dispute resolved", tone: "info", Icon: Gavel },
    cancelled: { label: "Cancelled", tone: "neutral", Icon: CircleDashed },
  };
  const meta = map[outcome] ?? map["completed"]!;
  return <BaseBadge {...meta} />;
}
