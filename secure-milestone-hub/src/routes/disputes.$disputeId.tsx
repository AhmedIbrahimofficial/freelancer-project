import { useState } from "react";
import { createFileRoute, Link, useParams } from "@tanstack/react-router";
import { motion } from "motion/react";
import { ArrowLeft, Loader2, Paperclip, Scale, Sparkles } from "lucide-react";
import { toast } from "sonner";
import { AppShell } from "@/components/app-shell";
import { EmptyState } from "@/components/empty-state";
import { DetailSkeleton } from "@/components/skeletons";
import { Button } from "@/components/ui/button";
import { Textarea } from "@/components/ui/textarea";
import { Badge } from "@/components/ui/badge";
import { money } from "@/lib/mock-data";
import { useDispute, useSubmitEvidence, errorMessage } from "@/lib/queries";
import { useAuth } from "@/lib/auth";
import { cn } from "@/lib/utils";

export const Route = createFileRoute("/disputes/$disputeId")({
  head: () => ({
    meta: [
      { title: "Dispute mediation — Escrowa" },
      {
        name: "description",
        content: "Structured mediation: both parties submit evidence while funds stay frozen.",
      },
    ],
  }),
  component: DisputeDetail,
});

function sideLabel(userId: number | undefined, raisedBy: number, clientId?: number) {
  if (!userId) return "party";
  if (userId === raisedBy) return "claimant";
  return "respondent";
}

function DisputeDetail() {
  const { disputeId } = useParams({ from: "/disputes/$disputeId" });
  const { user } = useAuth();

  const { data: dispute, isLoading, isError, error } = useDispute(disputeId);
  const submitEvidence = useSubmitEvidence();

  const [body, setBody] = useState("");

  if (isLoading) {
    return (
      <AppShell>
        <DetailSkeleton />
      </AppShell>
    );
  }

  if (isError || !dispute) {
    return (
      <AppShell>
        <EmptyState
          title="Dispute not found"
          body={isError ? errorMessage(error) : "This mediation case is not in your account."}
          actionLabel="Back to contracts"
          actionTo="/dashboard"
        />
      </AppShell>
    );
  }

  const milestoneAmount = dispute.milestone ? parseFloat(dispute.milestone.amount) : 0;
  const currency = "USD";
  const isResolved = ["resolved_client", "resolved_freelancer", "resolved_split", "closed"].includes(dispute.status);

  async function handleSubmitEvidence() {
    if (body.trim().length < 10) return;
    try {
      await submitEvidence.mutateAsync({ disputeId: dispute!.id, message: body.trim() });
      setBody("");
      toast.success("Evidence submitted", {
        description: "The mediator and the other party have been notified.",
      });
    } catch (err) {
      toast.error("Could not submit evidence", { description: errorMessage(err) });
    }
  }

  return (
    <AppShell>
      {dispute.contract && (
        <Link
          to="/contracts/$contractId"
          params={{ contractId: dispute.contract.id }}
          className="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
        >
          <ArrowLeft className="h-4 w-4" aria-hidden />
          {dispute.contract.title}
        </Link>
      )}

      <header className="surface-card mt-4 p-6">
        <div className="grid grid-cols-[minmax(0,1fr)_auto] items-start gap-4">
          <div className="min-w-0">
            <h1 className="text-2xl leading-snug sm:text-3xl">
              Dispute — {dispute.milestone?.title ?? "Contract level"}
            </h1>
            <p className="mt-1 text-sm text-muted-foreground leading-relaxed">
              {dispute.reason}
            </p>
            <p className="mt-2 text-xs text-muted-foreground">
              Opened {new Date(dispute.created_at).toLocaleDateString("en-GB", { day: "numeric", month: "short", year: "numeric" })}
              {dispute.raisedBy && ` by ${dispute.raisedBy.name}`}
            </p>
          </div>
          <Badge variant="outline" className="shrink-0 capitalize">
            {dispute.status.replace(/_/g, " ")}
          </Badge>
        </div>
        {milestoneAmount > 0 && (
          <p className="numeric mt-4 border-t border-border pt-4 text-sm">
            Frozen in escrow: {money(milestoneAmount, currency)}
          </p>
        )}
      </header>

      <div className="mt-6 grid gap-6 lg:grid-cols-[1.6fr_1fr]">
        <section className="space-y-4">
          <h2 className="text-xl">Evidence thread</h2>

          {(dispute.evidence ?? []).length === 0 && (
            <p className="rounded-md border border-border p-4 text-sm text-muted-foreground">
              No evidence submitted yet. Be the first to add your case.
            </p>
          )}

          {(dispute.evidence ?? []).map((e) => {
            const isMediator = !e.user_id || e.user?.role === "admin";
            const side = isMediator
              ? "mediator"
              : sideLabel(e.user_id, dispute.raised_by);

            return (
              <motion.article
                key={e.id}
                initial={{ opacity: 0, y: 8 }}
                animate={{ opacity: 1, y: 0 }}
                className={cn(
                  "surface-card p-5",
                  isMediator && "border-primary/40 bg-primary/5",
                )}
              >
                <div className="flex flex-wrap items-center gap-2">
                  <p className="text-sm font-medium">{e.user?.name ?? "Unknown"}</p>
                  <Badge variant="secondary" className="capitalize">
                    {side}
                  </Badge>
                  <span className="numeric text-[11px] text-muted-foreground">
                    {new Date(e.created_at).toLocaleString()}
                  </span>
                </div>
                {e.message && (
                  <p className="mt-3 text-sm leading-relaxed whitespace-pre-line">{e.message}</p>
                )}
                {e.file_name && (
                  <div className="mt-3 flex items-center gap-1.5 rounded-md border border-border px-2.5 py-1.5 text-xs text-muted-foreground w-fit">
                    <Paperclip className="h-3.5 w-3.5" aria-hidden />
                    {e.file_name}
                    {e.file_size && ` · ${Math.round(e.file_size / 1024)} KB`}
                  </div>
                )}
              </motion.article>
            );
          })}

          {/* Add evidence form */}
          {!isResolved && (
            <div className="surface-card p-5">
              <h3 className="text-base">Add evidence</h3>
              <p className="mt-1 text-xs text-muted-foreground">
                Reference the clause or deliverable your evidence relates to. Both parties see everything you submit.
              </p>
              <Textarea
                rows={4}
                className="mt-3"
                value={body}
                onChange={(ev) => setBody(ev.target.value)}
                placeholder="What happened, and which scope item it relates to."
                disabled={submitEvidence.isPending}
              />
              {submitEvidence.isError && (
                <p className="mt-2 text-xs text-destructive">{errorMessage(submitEvidence.error)}</p>
              )}
              <Button
                className="press mt-3"
                disabled={body.trim().length < 10 || submitEvidence.isPending}
                onClick={handleSubmitEvidence}
              >
                {submitEvidence.isPending && <Loader2 className="mr-2 h-4 w-4 animate-spin" aria-hidden />}
                Submit evidence
              </Button>
            </div>
          )}

          {isResolved && dispute.resolution_notes && (
            <div className="surface-card border-success/40 bg-success/5 p-5">
              <h3 className="flex items-center gap-2 text-base text-success">
                <Scale className="h-4 w-4" aria-hidden />
                Resolution
              </h3>
              <p className="mt-2 text-sm leading-relaxed">{dispute.resolution_notes}</p>
              {dispute.resolved_at && (
                <p className="numeric mt-1 text-xs text-muted-foreground">
                  Resolved {new Date(dispute.resolved_at).toLocaleDateString()}
                </p>
              )}
            </div>
          )}
        </section>

        <aside className="space-y-4">
          <div className="surface-card p-5">
            <h2 className="flex items-center gap-2 text-lg">
              <Sparkles className="h-4 w-4 text-primary" aria-hidden />
              AI dispute assistant
            </h2>
            <p className="mt-1 text-xs text-muted-foreground">
              A drafting aid, not a decision. A human mediator issues the final resolution.
            </p>
            <p className="mt-3 text-xs text-muted-foreground rounded-md border border-border bg-secondary p-3">
              AI summaries are generated by an admin from the dispute panel once enough evidence has been submitted.
            </p>
          </div>

          {dispute.contract && (
            <div className="surface-card p-5">
              <h3 className="text-base">Contract</h3>
              <Link
                to="/contracts/$contractId"
                params={{ contractId: dispute.contract.id }}
                className="mt-2 block text-sm text-primary hover:underline"
              >
                {dispute.contract.title}
              </Link>
              <p className="mt-1 text-xs text-muted-foreground capitalize">
                Status: {dispute.contract.status.replace(/_/g, " ")}
              </p>
            </div>
          )}
        </aside>
      </div>
    </AppShell>
  );
}
