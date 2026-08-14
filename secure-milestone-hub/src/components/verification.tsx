import { BadgeCheck, Building2, CreditCard, Mail, ShieldCheck } from "lucide-react";
import { HoverCard, HoverCardContent, HoverCardTrigger } from "@/components/ui/hover-card";
import { Progress } from "@/components/ui/progress";
import { cn } from "@/lib/utils";
import type { Verification } from "@/lib/mock-data";

const kindMeta = {
  email: { label: "Email", Icon: Mail },
  identity: { label: "ID", Icon: ShieldCheck },
  payment: { label: "Payout", Icon: CreditCard },
  business: { label: "Business", Icon: Building2 },
} as const;

function formatDate(d: string) {
  return new Date(d).toLocaleDateString("en-GB", {
    day: "numeric",
    month: "short",
    year: "numeric",
  });
}

export function VerificationBadge({
  verification,
  compact = false,
}: {
  verification: Verification;
  compact?: boolean | undefined;
}) {
  const meta = kindMeta[verification.kind];
  return (
    <HoverCard openDelay={80}>
      <HoverCardTrigger asChild>
        <button
          type="button"
          className={cn(
            "inline-flex items-center gap-1.5 rounded-full border border-success/30 bg-success/10 px-2.5 py-1 text-xs font-medium text-success transition-colors hover:bg-success/20 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none",
          )}
        >
          <meta.Icon className="h-3.5 w-3.5" aria-hidden />
          {compact ? meta.label : verification.label}
          <BadgeCheck className="h-3.5 w-3.5" aria-hidden />
        </button>
      </HoverCardTrigger>
      <HoverCardContent className="w-72 text-sm">
        <p className="font-medium text-foreground">{verification.label}</p>
        <p className="mt-1 text-muted-foreground">{verification.method}</p>
        <p className="mt-2 text-xs text-muted-foreground">
          Verified {formatDate(verification.verifiedAt)} · re-checked on every payout change
        </p>
      </HoverCardContent>
    </HoverCard>
  );
}

export function VerificationRow({
  verifications,
  compact,
}: {
  verifications: Verification[];
  compact?: boolean | undefined;
}) {
  if (verifications.length === 0) {
    return (
      <span className="inline-flex items-center gap-1.5 rounded-full border border-warning/40 bg-warning/15 px-2.5 py-1 text-xs font-medium text-warning-foreground">
        No verifications yet
      </span>
    );
  }
  return (
    <div className="flex flex-wrap gap-2">
      {verifications.map((v) => (
        <VerificationBadge key={v.kind} verification={v} compact={compact} />
      ))}
    </div>
  );
}

export function StatCard({
  label,
  value,
  suffix,
  note,
  tone = "neutral",
}: {
  label: string;
  value: string | number;
  suffix?: string | undefined;
  note: string;
  tone?: "neutral" | "good" | "watch";
}) {
  return (
    <div className="surface-card p-4">
      <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">{label}</p>
      <p
        className={cn(
          "numeric mt-2 text-2xl",
          tone === "good" && "text-success",
          tone === "watch" && "text-warning-foreground",
        )}
      >
        {value}
        {suffix && <span className="ml-0.5 text-base text-muted-foreground">{suffix}</span>}
      </p>
      <p className="mt-1.5 text-xs leading-relaxed text-muted-foreground">{note}</p>
    </div>
  );
}

export function CompletenessMeter({
  items,
  className,
}: {
  items: { label: string; done: boolean }[];
  className?: string | undefined;
}) {
  const done = items.filter((i) => i.done).length;
  const pct = Math.round((done / items.length) * 100);
  return (
    <div className={cn("surface-card p-5", className)}>
      <div className="flex items-baseline justify-between gap-3">
        <h3 className="text-base">Profile completeness</h3>
        <span className="numeric text-sm text-muted-foreground">{pct}%</span>
      </div>
      <Progress value={pct} className="mt-3 h-2" />
      <ul className="mt-4 space-y-2 text-sm">
        {items.map((i) => (
          <li key={i.label} className="flex items-center gap-2">
            <span
              className={cn(
                "grid h-4 w-4 shrink-0 place-items-center rounded-full border text-[10px]",
                i.done
                  ? "border-success bg-success text-success-foreground"
                  : "border-border text-muted-foreground",
              )}
              aria-hidden
            >
              {i.done ? "✓" : ""}
            </span>
            <span className={i.done ? "text-muted-foreground" : "text-foreground"}>{i.label}</span>
          </li>
        ))}
      </ul>
      {pct < 100 && (
        <p className="mt-4 text-xs leading-relaxed text-muted-foreground">
          Completing verification raises how prominently your profile is shown to counterparties. It
          is never required to use Escrowa.
        </p>
      )}
    </div>
  );
}
