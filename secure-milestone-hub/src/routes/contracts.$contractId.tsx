import { useState } from "react";
import { createFileRoute, Link, useNavigate, useParams } from "@tanstack/react-router";
import { motion } from "motion/react";
import {
  ArrowLeft,
  BadgeCheck,
  CheckCircle2,
  Gavel,
  Landmark,
  Loader2,
  Lock,
  PenLine,
  RefreshCw,
  Snowflake,
} from "lucide-react";
import { toast } from "sonner";
import { AppShell } from "@/components/app-shell";
import { ConfirmDialog } from "@/components/confirm-dialog";
import { DetailSkeleton } from "@/components/skeletons";
import { Button } from "@/components/ui/button";
import { Textarea } from "@/components/ui/textarea";
import { EmptyState } from "@/components/empty-state";
import { money } from "@/lib/mock-data";
import {
  useContract,
  useSignContract,
  useSendContract,
  useApproveMilestone,
  useSubmitMilestone,
  useDisputeMilestone,
  errorMessage,
} from "@/lib/queries";
import { useAuth } from "@/lib/auth";
import { useContractChannel } from "@/hooks/use-contract-channel";
import { type ApiMilestone } from "@/lib/api";
import { cn } from "@/lib/utils";

export const Route = createFileRoute("/contracts/$contractId")({
  head: () => ({
    meta: [
      { title: "Contract detail — Escrowa" },
      { name: "description", content: "Milestone contract detail, timeline and dispute management." },
    ],
  }),
  component: ContractDetail,
});

function when(iso: string) {
  return new Date(iso).toLocaleString("en-GB", {
    day: "numeric",
    month: "short",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  });
}

function milestoneStatusClass(s: string) {
  if (s === "approved" || s === "released") return "bg-success/10 text-success";
  if (s === "submitted") return "bg-primary/10 text-primary";
  if (s === "disputed") return "bg-destructive/10 text-destructive";
  return "bg-secondary text-muted-foreground";
}

type PendingAction =
  | { kind: "sign" }
  | { kind: "send" }
  | { kind: "submit"; milestone: ApiMilestone }
  | { kind: "approve"; milestone: ApiMilestone }
  | { kind: "dispute"; milestone: ApiMilestone }
  | null;

function ContractDetail() {
  const { contractId } = useParams({ from: "/contracts/$contractId" });
  const navigate = useNavigate();
  const { user } = useAuth();

  const { data: contract, isLoading, isError, error, refetch } = useContract(contractId);
  const signContract = useSignContract();
  const sendContract = useSendContract();
  const approveMilestone = useApproveMilestone();
  const submitMilestone = useSubmitMilestone();
  const disputeMilestone = useDisputeMilestone();

  // Subscribe to real-time Pusher updates for this contract
  useContractChannel(contractId);

  const [pending, setPending] = useState<PendingAction>(null);
  const [note, setNote] = useState("");
  const [signedName, setSignedName] = useState(user?.name ?? "");

  if (isLoading) {
    return (
      <AppShell>
        <DetailSkeleton />
      </AppShell>
    );
  }

  if (isError || !contract) {
    return (
      <AppShell>
        <EmptyState
          title="Contract not found"
          body={isError ? errorMessage(error) : "This contract is not in your account."}
          actionLabel="Back to contracts"
          actionTo="/dashboard"
        />
      </AppShell>
    );
  }

  const iAmClient = user && contract.client_id === user.id;
  const iAmFreelancer = user && contract.freelancer_id === user.id;
  const iHaveSigned = contract.signatures?.some((s) => s.user_id === user?.id) ?? false;
  const bothSigned = (contract.signatures?.length ?? 0) >= 2;
  const isDraft = contract.status === "draft";
  const isPendingSignature = contract.status === "pending_signature";
  const isActive = contract.status === "active";
  const isDisputed = contract.status === "disputed";
  const activeDispute = contract.disputes?.[0];

  const total = parseFloat(contract.total_amount);

  return (
    <AppShell>
      <Link
        to="/dashboard"
        className="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
      >
        <ArrowLeft className="h-4 w-4" aria-hidden />
        All contracts
      </Link>

      {/* Header */}
      <header className="surface-card mt-4 p-6">
        <div className="grid grid-cols-[minmax(0,1fr)_auto] items-start gap-4">
          <div className="min-w-0">
            <p className="numeric text-xs text-muted-foreground">{contract.id.slice(0, 8).toUpperCase()}</p>
            <h1 className="mt-1 text-2xl leading-snug sm:text-3xl">{contract.title}</h1>
          </div>
          <span className={cn(
            "shrink-0 rounded-full px-2.5 py-1 text-xs font-medium capitalize",
            contract.status === "active" ? "bg-primary/10 text-primary" :
            contract.status === "disputed" ? "bg-destructive/10 text-destructive" :
            contract.status === "completed" ? "bg-success/10 text-success" :
            "bg-secondary text-muted-foreground",
          )}>
            {contract.status.replace(/_/g, " ")}
          </span>
        </div>

        <dl className="mt-6 grid gap-4 border-t border-border pt-5 sm:grid-cols-4">
          <div>
            <dt className="text-xs text-muted-foreground">Client</dt>
            <dd className="mt-0.5 text-sm">{contract.client?.name ?? "—"}</dd>
          </div>
          <div>
            <dt className="text-xs text-muted-foreground">Freelancer</dt>
            <dd className="mt-0.5 text-sm">{contract.freelancer?.name ?? "—"}</dd>
          </div>
          <div>
            <dt className="text-xs text-muted-foreground">Contract value</dt>
            <dd className="numeric mt-0.5 text-sm">{money(total, contract.currency)}</dd>
          </div>
          <div>
            <dt className="text-xs text-muted-foreground">Held by</dt>
            <dd className="mt-0.5 flex items-center gap-1.5 text-sm">
              <Landmark className="h-3.5 w-3.5 text-primary" aria-hidden />
              Stripe Escrow
            </dd>
          </div>
        </dl>

        {/* Signature badges */}
        {contract.signatures && contract.signatures.length > 0 && (
          <div className="mt-4 flex flex-wrap gap-2 border-t border-border pt-4">
            {contract.signatures.map((sig) => (
              <span key={sig.id} className="flex items-center gap-1.5 text-xs text-success">
                <CheckCircle2 className="h-3.5 w-3.5" aria-hidden />
                {sig.signed_name} signed {new Date(sig.signed_at).toLocaleDateString()}
              </span>
            ))}
          </div>
        )}

        {/* Action banners */}
        {isDraft && iAmClient && (
          <div className="mt-5 flex flex-col gap-3 rounded-md border border-warning/40 bg-warning/10 p-4 sm:flex-row sm:items-center sm:justify-between">
            <p className="text-sm text-warning-foreground">
              This is a draft — send it to the freelancer for signature.
            </p>
            <Button className="press shrink-0" onClick={() => setPending({ kind: "send" })}>
              <PenLine className="mr-1.5 h-4 w-4" aria-hidden />
              Send for signature
            </Button>
          </div>
        )}

        {isPendingSignature && !iHaveSigned && (
          <div className="mt-5 flex flex-col gap-3 rounded-md border border-warning/40 bg-warning/10 p-4 sm:flex-row sm:items-center sm:justify-between">
            <p className="text-sm text-warning-foreground">
              Your signature is required before this contract is binding.
            </p>
            <Button className="press shrink-0" onClick={() => setPending({ kind: "sign" })}>
              <PenLine className="mr-1.5 h-4 w-4" aria-hidden />
              Review & sign
            </Button>
          </div>
        )}

        {isPendingSignature && iHaveSigned && !bothSigned && (
          <div className="mt-5 rounded-md border border-border bg-secondary/50 p-4">
            <p className="text-sm text-muted-foreground">
              You have signed. Waiting for the other party to sign.
            </p>
          </div>
        )}

        {isDisputed && activeDispute && (
          <div className="mt-5 flex flex-col gap-3 rounded-md border border-destructive/30 bg-destructive/5 p-4 sm:flex-row sm:items-center sm:justify-between">
            <p className="flex items-start gap-2 text-sm text-destructive">
              <Snowflake className="mt-0.5 h-4 w-4 shrink-0" aria-hidden />
              Funds frozen pending dispute resolution.
            </p>
            <Button asChild variant="outline" className="press shrink-0">
              <Link to="/disputes/$disputeId" params={{ disputeId: activeDispute.id }}>
                Open dispute
              </Link>
            </Button>
          </div>
        )}
      </header>

      <div className="mt-6 grid gap-6 lg:grid-cols-[1.6fr_1fr]">
        {/* Milestones */}
        <section className="space-y-4">
          <h2 className="text-xl">Milestones</h2>
          {(contract.milestones ?? []).map((m) => (
            <article key={m.id} className="surface-card p-5">
              <div className="grid grid-cols-[minmax(0,1fr)_auto] items-start gap-3">
                <div className="min-w-0">
                  <h3 className="truncate text-lg">{m.title}</h3>
                  {m.description && (
                    <p className="mt-1 text-sm leading-relaxed text-muted-foreground">{m.description}</p>
                  )}
                </div>
                <p className="numeric text-lg">{money(parseFloat(m.amount), contract.currency)}</p>
              </div>

              <div className="mt-4 flex flex-wrap items-center gap-2">
                <span className={cn("rounded-full px-2.5 py-0.5 text-xs font-medium capitalize", milestoneStatusClass(m.status))}>
                  {m.status.replace(/_/g, " ")}
                </span>
                {m.due_date && (
                  <span className="text-xs text-muted-foreground">Due {m.due_date}</span>
                )}
              </div>

              {/* Freelancer actions */}
              {isActive && iAmFreelancer && ["pending", "in_progress"].includes(m.status) && (
                <div className="mt-4 border-t border-border pt-4">
                  <Button
                    className="press"
                    disabled={submitMilestone.isPending}
                    onClick={() => setPending({ kind: "submit", milestone: m })}
                  >
                    {submitMilestone.isPending && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                    Submit for review
                  </Button>
                </div>
              )}

              {/* Client actions */}
              {isActive && iAmClient && m.status === "submitted" && (
                <div className="mt-4 flex flex-wrap gap-2 border-t border-border pt-4">
                  <Button
                    className="press"
                    disabled={approveMilestone.isPending}
                    onClick={() => setPending({ kind: "approve", milestone: m })}
                  >
                    {approveMilestone.isPending && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                    <BadgeCheck className="mr-1.5 h-4 w-4" aria-hidden />
                    Approve
                  </Button>
                  <Button
                    variant="ghost"
                    className="press text-destructive"
                    disabled={disputeMilestone.isPending}
                    onClick={() => setPending({ kind: "dispute", milestone: m })}
                  >
                    <Gavel className="mr-1.5 h-4 w-4" aria-hidden />
                    Dispute
                  </Button>
                </div>
              )}

              {m.status === "disputed" && (
                <div className="mt-4 flex items-center gap-2 rounded-md bg-secondary px-3 py-2.5 text-xs text-muted-foreground">
                  <Lock className="h-3.5 w-3.5" aria-hidden />
                  Release frozen during mediation.
                </div>
              )}

              {m.status === "approved" && (
                <div className="mt-4 flex items-center gap-2 text-xs text-success">
                  <CheckCircle2 className="h-4 w-4" aria-hidden />
                  Approved {m.approved_at ? when(m.approved_at) : ""}
                </div>
              )}
            </article>
          ))}

          {/* Scope & Terms */}
          <div className="surface-card p-5">
            <h3 className="text-base">Scope</h3>
            <p className="mt-2 text-sm leading-relaxed text-muted-foreground whitespace-pre-line">{contract.scope}</p>
            {contract.terms && (
              <>
                <h3 className="mt-5 text-base">Terms</h3>
                <p className="mt-2 text-sm leading-relaxed text-muted-foreground whitespace-pre-line">{contract.terms}</p>
              </>
            )}
          </div>
        </section>

        {/* Timeline from activity log / signatures */}
        <section>
          <h2 className="text-xl">Timeline</h2>
          <div className="surface-card mt-4 p-5">
            {contract.signatures && contract.signatures.length > 0 ? (
              <ol className="space-y-4">
                {[...contract.signatures].reverse().map((sig, i) => (
                  <motion.li
                    key={sig.id}
                    initial={{ opacity: 0, x: -8 }}
                    animate={{ opacity: 1, x: 0 }}
                    transition={{ delay: i * 0.05 }}
                    className="relative flex gap-3"
                  >
                    <span className="mt-1 h-2.5 w-2.5 shrink-0 rounded-full bg-primary" aria-hidden />
                    <div>
                      <p className="text-sm font-medium">Signed by {sig.signed_name}</p>
                      <p className="numeric mt-0.5 text-[11px] text-muted-foreground">
                        {when(sig.signed_at)}
                      </p>
                    </div>
                  </motion.li>
                ))}
                <motion.li
                  initial={{ opacity: 0, x: -8 }}
                  animate={{ opacity: 1, x: 0 }}
                  className="relative flex gap-3"
                >
                  <span className="mt-1 h-2.5 w-2.5 shrink-0 rounded-full bg-border" aria-hidden />
                  <div>
                    <p className="text-sm font-medium">Contract created</p>
                    <p className="numeric mt-0.5 text-[11px] text-muted-foreground">
                      {when(contract.created_at)}
                    </p>
                  </div>
                </motion.li>
              </ol>
            ) : (
              <p className="text-sm text-muted-foreground">No events yet.</p>
            )}
          </div>
        </section>
      </div>

      {/* Send for signature */}
      <ConfirmDialog
        open={pending?.kind === "send"}
        onOpenChange={(v) => !v && setPending(null)}
        title="Send for signature?"
        description="The freelancer will be notified and can sign. Nothing is binding until both parties sign."
        confirmLabel="Send"
        onConfirm={async () => {
          try {
            await sendContract.mutateAsync(contract.id);
            toast.success("Contract sent for signature");
            setPending(null);
          } catch (err) {
            toast.error("Failed to send", { description: errorMessage(err) });
          }
        }}
      />

      {/* Sign */}
      <ConfirmDialog
        open={pending?.kind === "sign"}
        onOpenChange={(v) => !v && setPending(null)}
        weight="heavy"
        title="Sign this contract"
        description="Signing makes the scope, milestones and payment terms binding. This is recorded with a timestamp."
        consequences={[
          { label: "You are agreeing to", detail: `${(contract.milestones ?? []).length} milestones · ${money(total, contract.currency)}` },
        ]}
        typedConfirmation={user?.name}
        typedConfirmationLabel={`Type your full legal name (${user?.name}) to sign`}
        confirmLabel="Sign contract"
        onConfirm={async () => {
          try {
            await signContract.mutateAsync({ id: contract.id, signed_name: signedName });
            toast.success("Signature recorded");
            setPending(null);
          } catch (err) {
            toast.error("Signing failed", { description: errorMessage(err) });
          }
        }}
      />

      {/* Submit milestone */}
      <ConfirmDialog
        open={pending?.kind === "submit"}
        onOpenChange={(v) => !v && (setPending(null), setNote(""))}
        title="Submit milestone for review?"
        description="The client will be notified to review your work."
        extra={
          <Textarea
            rows={3}
            value={note}
            onChange={(e) => setNote(e.target.value)}
            placeholder="Optional: describe what you delivered."
          />
        }
        confirmLabel="Submit"
        onConfirm={async () => {
          if (pending?.kind !== "submit") return;
          try {
            await submitMilestone.mutateAsync({
              id: pending.milestone.id,
              contractId: contract.id,
              notes: note || undefined,
            });
            toast.success("Milestone submitted for review");
            setNote("");
            setPending(null);
          } catch (err) {
            toast.error("Submit failed", { description: errorMessage(err) });
          }
        }}
      />

      {/* Approve milestone */}
      <ConfirmDialog
        open={pending?.kind === "approve"}
        onOpenChange={(v) => !v && setPending(null)}
        weight="heavy"
        title="Approve milestone?"
        description="Approving accepts the deliverable as complete. Funds will be released to the freelancer."
        consequences={[
          {
            label: "Releasing",
            detail: pending?.kind === "approve" ? money(parseFloat(pending.milestone.amount), contract.currency) : "—",
          },
          { label: "Cannot be reversed", detail: "Once approved, the dispute window closes." },
        ]}
        typedConfirmation="APPROVE"
        typedConfirmationLabel='Type "APPROVE" to confirm'
        confirmLabel="Approve"
        onConfirm={async () => {
          if (pending?.kind !== "approve") return;
          try {
            await approveMilestone.mutateAsync({ id: pending.milestone.id, contractId: contract.id });
            toast.success("Milestone approved");
            setPending(null);
          } catch (err) {
            toast.error("Approval failed", { description: errorMessage(err) });
          }
        }}
      />

      {/* Dispute milestone */}
      <ConfirmDialog
        open={pending?.kind === "dispute"}
        onOpenChange={(v) => !v && (setPending(null), setNote(""))}
        weight="heavy"
        tone="destructive"
        title="Open a dispute"
        description="This freezes the milestone funds and starts mediation. Both parties submit evidence and a mediator issues a written resolution."
        consequences={[
          {
            label: "Amount frozen",
            detail: pending?.kind === "dispute" ? money(parseFloat(pending.milestone.amount), contract.currency) : "—",
          },
          { label: "Timeline", detail: "5 business days for the other party to respond." },
        ]}
        extra={
          <Textarea
            rows={4}
            value={note}
            onChange={(e) => setNote(e.target.value)}
            placeholder="Explain what was not delivered, referencing the scope item."
          />
        }
        typedConfirmation="DISPUTE"
        typedConfirmationLabel='Type "DISPUTE" to open mediation'
        confirmLabel="Open dispute"
        onConfirm={async () => {
          if (pending?.kind !== "dispute") return;
          if (!note.trim()) {
            toast.error("Please describe the issue before opening a dispute.");
            return;
          }
          try {
            const res = await disputeMilestone.mutateAsync({
              id: pending.milestone.id,
              contractId: contract.id,
              reason: note.trim(),
            });
            setNote("");
            setPending(null);
            toast.success("Dispute opened");
            if (res.dispute?.id) {
              navigate({ to: "/disputes/$disputeId", params: { disputeId: res.dispute.id } });
            }
          } catch (err) {
            toast.error("Failed to open dispute", { description: errorMessage(err) });
          }
        }}
      />
    </AppShell>
  );
}
