import { motion } from "motion/react";
import { Link } from "@tanstack/react-router";
import { ArrowRight, CalendarClock, Lock } from "lucide-react";
import { Avatar, AvatarFallback } from "@/components/ui/avatar";
import { Button } from "@/components/ui/button";
import { ContractStatusBadge } from "@/components/status";
import { VerificationBadge } from "@/components/verification";
import { contractTotal, heldAmount, money, type Contract } from "@/lib/mock-data";
import { useStore } from "@/lib/store";

function nextAction(c: Contract) {
  switch (c.status) {
    case "draft":
      return { label: "Finish drafting and send for signature", cta: "Open draft" };
    case "awaiting_signature":
      return { label: "Your signature is required to start work", cta: "Review & sign" };
    case "milestone_review":
      return { label: "Client is reviewing a submitted milestone", cta: "View milestone" };
    case "disputed":
      return { label: "Response requested — funds frozen until resolved", cta: "Open dispute" };
    case "completed":
      return { label: "All milestones released", cta: "View record" };
    default: {
      const nextMs = c.milestones.find((m) => m.status === "pending" || m.status === "changes_requested");
      return {
        label: nextMs ? `Next up: ${nextMs.title}` : "Work in progress",
        cta: "Open contract",
      };
    }
  }
}

export function ContractCard({ contract, index = 0 }: { contract: Contract; index?: number }) {
  const { getProfile } = useStore();
  const counterparty = contract.client;
  const profile = getProfile(counterparty.handle);
  const action = nextAction(contract);
  const held = heldAmount(contract);
  const topVerification = profile?.verifications.find((v) => v.kind !== "email") ?? profile?.verifications[0];

  return (
    <motion.article
      layout
      initial={{ opacity: 0, y: 10 }}
      animate={{ opacity: 1, y: 0 }}
      exit={{ opacity: 0, scale: 0.98 }}
      transition={{ duration: 0.25, ease: "easeOut", delay: Math.min(index * 0.03, 0.2) }}
      className="surface-card group flex flex-col p-5 transition-shadow duration-200 hover:shadow-lift"
    >
      <div className="grid grid-cols-[minmax(0,1fr)_auto] items-start gap-3">
        <div className="min-w-0">
          <p className="numeric text-xs text-muted-foreground">{contract.reference}</p>
          <h3 className="mt-1 truncate text-lg leading-snug">{contract.title}</h3>
        </div>
        <ContractStatusBadge status={contract.status} />
      </div>

      <div className="mt-4 flex min-w-0 items-center gap-3">
        <Avatar className="h-9 w-9 shrink-0 border border-border">
          <AvatarFallback className="bg-secondary text-xs font-medium">
            {counterparty.initials}
          </AvatarFallback>
        </Avatar>
        <div className="min-w-0 flex-1">
          <div className="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1">
            <Link
              to="/profile/$handle"
              params={{ handle: counterparty.handle }}
              className="truncate text-sm font-medium hover:underline"
            >
              {counterparty.name}
            </Link>
            {topVerification && <VerificationBadge verification={topVerification} compact />}
          </div>
          {profile && (
            <p className="mt-0.5 text-xs text-muted-foreground">
              {profile.stats.completionRate}% completion · {profile.stats.disputeRate}% dispute rate
              · releases in {profile.stats.avgReleaseDays}d
            </p>
          )}
        </div>
      </div>

      <div className="mt-4 grid grid-cols-2 gap-3 border-t border-border pt-4 text-sm">
        <div>
          <p className="text-xs text-muted-foreground">Contract value</p>
          <p className="numeric mt-0.5 text-base">
            {money(contractTotal(contract), contract.currency)}
          </p>
        </div>
        <div>
          <p className="text-xs text-muted-foreground">In escrow</p>
          <p className="numeric mt-0.5 flex items-center gap-1.5 text-base">
            {held > 0 ? (
              <>
                <Lock className="h-3.5 w-3.5 text-info" aria-hidden />
                {money(held, contract.currency)}
              </>
            ) : (
              <span className="text-muted-foreground">—</span>
            )}
          </p>
        </div>
      </div>

      <div className="mt-4 flex items-start gap-2 rounded-md bg-secondary/70 px-3 py-2.5 text-xs text-muted-foreground">
        <CalendarClock className="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden />
        <span className="leading-relaxed">{action.label}</span>
      </div>

      <Button asChild variant="outline" className="press mt-4 w-full">
        <Link to="/contracts/$contractId" params={{ contractId: contract.id }}>
          {action.cta}
          <ArrowRight className="ml-1.5 h-4 w-4" aria-hidden />
        </Link>
      </Button>
    </motion.article>
  );
}
