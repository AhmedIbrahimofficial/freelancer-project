import { useMemo, useState } from "react";
import { createFileRoute, useNavigate } from "@tanstack/react-router";
import { AnimatePresence, motion } from "motion/react";
import { AlertCircle, ArrowLeft, ArrowRight, Loader2, Plus, Trash2 } from "lucide-react";
import { toast } from "sonner";
import { AppShell } from "@/components/app-shell";
import { Stepper } from "@/components/stepper";
import { ConfirmDialog } from "@/components/confirm-dialog";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { money } from "@/lib/mock-data";
import { useCreateContract, errorMessage } from "@/lib/queries";

export const Route = createFileRoute("/contracts/new")({
  head: () => ({
    meta: [
      { title: "Draft a milestone contract — Escrowa" },
      {
        name: "description",
        content: "Draft a binding milestone contract in four steps.",
      },
    ],
  }),
  component: NewContract,
});

const steps = [
  { id: "basics", label: "Basic info", hint: "Parties and title" },
  { id: "scope", label: "Scope", hint: "What is included" },
  { id: "milestones", label: "Milestones", hint: "Dates and amounts" },
  { id: "review", label: "Review & send", hint: "Document preview" },
];

interface DraftMilestone {
  key: string;
  title: string;
  amount: string;
  dueDate: string;
  description: string;
}

const newMilestone = (): DraftMilestone => ({
  key: Math.random().toString(36).slice(2, 8),
  title: "",
  amount: "",
  dueDate: "",
  description: "",
});

function NewContract() {
  const navigate = useNavigate();
  const createContract = useCreateContract();

  const [step, setStep] = useState(0);
  const [confirmOpen, setConfirmOpen] = useState(false);

  // Step 0 — basics
  const [title, setTitle] = useState("");
  const [freelancerEmail, setFreelancerEmail] = useState("");
  const [freelancerId, setFreelancerId] = useState<number | null>(null);
  const [freelancerName, setFreelancerName] = useState("");
  const [summary, setSummary] = useState("");

  // Step 1 — scope
  const [scope, setScope] = useState("");
  const [outOfScope, setOutOfScope] = useState("");

  // Step 2 — milestones
  const [milestones, setMilestones] = useState<DraftMilestone[]>([newMilestone()]);

  const total = useMemo(
    () => milestones.reduce((sum, m) => sum + (Number(m.amount) || 0), 0),
    [milestones],
  );

  const canContinue = [
    title.trim().length > 2 && summary.trim().length > 4 && freelancerEmail.trim().length > 4,
    scope.trim().length > 10,
    milestones.every((m) => m.title.trim() && Number(m.amount) > 0 && m.dueDate),
    true,
  ][step];

  // Look up freelancer by email when they move past step 0
  // (In a full build this would be a user search. Here we store the ID manually.)
  function handleFreelancerEmailBlur() {
    // Placeholder: in production, hit GET /api/v1/users/search?email=...
    // For now, parse an ID from the placeholder pattern "id:123 email"
    const match = freelancerEmail.match(/^(\d+):/);
    if (match) {
      setFreelancerId(Number(match[1]));
      setFreelancerName(freelancerEmail.slice(match[0].length).trim());
    }
  }

  async function send() {
    if (!freelancerId) {
      toast.error("Freelancer not found", {
        description: "Enter the freelancer's user ID in the format: 2:Their Name",
      });
      return;
    }

    try {
      const contract = await createContract.mutateAsync({
        freelancer_id: freelancerId,
        title,
        scope: `${scope}\n\nOut of scope: ${outOfScope}`,
        total_amount: total,
        currency: "USD",
        terms: [
          "Deliverables are reviewed within 5 business days of submission.",
          "Approved milestones are released from escrow within 24 hours.",
          "Either party may open a dispute within 14 days of a milestone submission.",
        ].join("\n"),
        milestones: milestones.map((m) => ({
          title: m.title,
          description: m.description || undefined,
          amount: Number(m.amount),
          due_date: m.dueDate || undefined,
        })),
      });
      toast.success("Contract created", {
        description: "Send it to the freelancer for signature from the contract page.",
      });
      navigate({ to: "/contracts/$contractId", params: { contractId: contract.id } });
    } catch (err) {
      toast.error("Could not create contract", { description: errorMessage(err) });
    }
  }

  return (
    <AppShell>
      <div className="mx-auto max-w-3xl">
        <h1 className="text-3xl">New contract</h1>
        <p className="mt-1.5 text-sm text-muted-foreground">
          Nothing is sent until you review the document on the last step.
        </p>

        <div className="mt-8">
          <Stepper steps={steps} current={step} />
        </div>

        {/* Show API errors */}
        {createContract.isError && (
          <div className="mt-4 flex items-start gap-2 rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2.5 text-sm text-destructive">
            <AlertCircle className="mt-0.5 h-4 w-4 shrink-0" aria-hidden />
            {errorMessage(createContract.error)}
          </div>
        )}

        <div className="surface-card mt-8 p-6">
          {/* Step 0 — Basic info */}
          {step === 0 && (
            <div className="space-y-5">
              <div className="space-y-1.5">
                <Label htmlFor="title">Contract title</Label>
                <Input
                  id="title"
                  value={title}
                  onChange={(e) => setTitle(e.target.value)}
                  placeholder="Design system rebuild"
                />
              </div>
              <div className="space-y-1.5">
                <Label htmlFor="freelancer">
                  Freelancer
                </Label>
                <Input
                  id="freelancer"
                  value={freelancerEmail}
                  onChange={(e) => setFreelancerEmail(e.target.value)}
                  onBlur={handleFreelancerEmailBlur}
                  placeholder="Enter user ID in format: 2:Jane Smith"
                />
                {freelancerName && (
                  <p className="text-xs text-success">✓ {freelancerName}</p>
                )}
                <p className="text-xs text-muted-foreground">
                  Format: <code className="font-mono">user_id:Display Name</code> — use the ID from the user record.
                </p>
              </div>
              <div className="space-y-1.5">
                <Label htmlFor="summary">One-line summary</Label>
                <Input
                  id="summary"
                  value={summary}
                  onChange={(e) => setSummary(e.target.value)}
                  placeholder="Rebuild the marketing design system and ship a component library."
                />
              </div>
            </div>
          )}

          {/* Step 1 — Scope */}
          {step === 1 && (
            <div className="space-y-5">
              <div className="space-y-1.5">
                <Label htmlFor="scope">In scope</Label>
                <Textarea
                  id="scope"
                  rows={5}
                  value={scope}
                  onChange={(e) => setScope(e.target.value)}
                  placeholder="Be specific — this is what a mediator reads first if a dispute is opened."
                />
              </div>
              <div className="space-y-1.5">
                <Label htmlFor="out">Explicitly out of scope</Label>
                <Textarea
                  id="out"
                  rows={3}
                  value={outOfScope}
                  onChange={(e) => setOutOfScope(e.target.value)}
                  placeholder="Ongoing maintenance, copywriting, handoff beyond one session."
                />
              </div>
            </div>
          )}

          {/* Step 2 — Milestones */}
          {step === 2 && (
            <div className="space-y-4">
              <AnimatePresence initial={false}>
                {milestones.map((m, i) => (
                  <motion.div
                    key={m.key}
                    initial={{ opacity: 0, x: 24, height: 0 }}
                    animate={{ opacity: 1, x: 0, height: "auto" }}
                    exit={{ opacity: 0, height: 0, marginBottom: 0 }}
                    transition={{ duration: 0.25, ease: "easeOut" }}
                    className="overflow-hidden"
                  >
                    <div className="rounded-lg border border-border p-4">
                      <div className="flex items-center justify-between gap-3">
                        <p className="numeric text-xs text-muted-foreground">
                          Milestone {i + 1} of {milestones.length}
                        </p>
                        {milestones.length > 1 && (
                          <Button
                            variant="ghost"
                            size="sm"
                            className="press text-destructive"
                            onClick={() =>
                              setMilestones((prev) => prev.filter((x) => x.key !== m.key))
                            }
                          >
                            <Trash2 className="mr-1 h-3.5 w-3.5" aria-hidden />
                            Remove
                          </Button>
                        )}
                      </div>
                      <div className="mt-3 grid gap-3 sm:grid-cols-[1fr_150px_170px]">
                        <Input
                          value={m.title}
                          placeholder="Milestone title"
                          onChange={(e) =>
                            setMilestones((prev) =>
                              prev.map((x) => (x.key === m.key ? { ...x, title: e.target.value } : x)),
                            )
                          }
                        />
                        <Input
                          value={m.amount}
                          inputMode="numeric"
                          placeholder="Amount (USD)"
                          onChange={(e) =>
                            setMilestones((prev) =>
                              prev.map((x) =>
                                x.key === m.key
                                  ? { ...x, amount: e.target.value.replace(/[^0-9.]/g, "") }
                                  : x,
                              ),
                            )
                          }
                        />
                        <Input
                          type="date"
                          value={m.dueDate}
                          onChange={(e) =>
                            setMilestones((prev) =>
                              prev.map((x) =>
                                x.key === m.key ? { ...x, dueDate: e.target.value } : x,
                              ),
                            )
                          }
                        />
                      </div>
                      <Textarea
                        rows={2}
                        className="mt-3"
                        value={m.description}
                        placeholder="What exactly is delivered for this milestone to be approved?"
                        onChange={(e) =>
                          setMilestones((prev) =>
                            prev.map((x) =>
                              x.key === m.key ? { ...x, description: e.target.value } : x,
                            ),
                          )
                        }
                      />
                    </div>
                  </motion.div>
                ))}
              </AnimatePresence>

              <div className="flex flex-wrap items-center justify-between gap-3">
                <Button
                  variant="outline"
                  className="press"
                  onClick={() => setMilestones((prev) => [...prev, newMilestone()])}
                >
                  <Plus className="mr-1.5 h-4 w-4" aria-hidden />
                  Add milestone
                </Button>
                <motion.p
                  key={total}
                  animate={{ backgroundColor: ["oklch(0.72 0.14 72 / 0.28)", "transparent"] }}
                  transition={{ duration: 0.9 }}
                  className="numeric rounded-md px-2 py-1 text-lg"
                >
                  Total {money(total)}
                </motion.p>
              </div>
            </div>
          )}

          {/* Step 3 — Review */}
          {step === 3 && (
            <article className="space-y-6 rounded-md border border-border bg-card p-6 sm:p-8">
              <header className="border-b border-border pb-4">
                <p className="numeric text-xs text-muted-foreground">Milestone agreement — draft</p>
                <h2 className="mt-1 text-2xl">{title || "Untitled contract"}</h2>
                <p className="mt-2 text-sm text-muted-foreground">{summary}</p>
              </header>
              <section className="grid gap-4 text-sm sm:grid-cols-2">
                <div>
                  <p className="text-xs text-muted-foreground uppercase">Freelancer</p>
                  <p className="mt-1">{freelancerName || "—"}</p>
                </div>
              </section>
              <section className="space-y-2 text-sm">
                <h3 className="text-base">1. Scope</h3>
                <p className="leading-relaxed text-muted-foreground">{scope}</p>
                {outOfScope && (
                  <p className="leading-relaxed text-muted-foreground">
                    <span className="font-medium text-foreground">Out of scope: </span>
                    {outOfScope}
                  </p>
                )}
              </section>
              <section className="space-y-3 text-sm">
                <h3 className="text-base">2. Milestones and payment</h3>
                <ul className="divide-y divide-border overflow-hidden rounded-md border border-border">
                  {milestones.map((m, i) => (
                    <li key={m.key} className="grid grid-cols-[1fr_auto] gap-3 px-4 py-3">
                      <div className="min-w-0">
                        <p className="font-medium">
                          {i + 1}. {m.title || "Untitled milestone"}
                        </p>
                        <p className="text-xs text-muted-foreground">
                          Due {m.dueDate || "—"} · {m.description || "No description"}
                        </p>
                      </div>
                      <p className="numeric">{money(Number(m.amount) || 0)}</p>
                    </li>
                  ))}
                </ul>
                <p className="numeric text-right text-base">Total {money(total)}</p>
              </section>
            </article>
          )}
        </div>

        <div className="mt-6 flex items-center justify-between gap-3">
          <Button
            variant="ghost"
            className="press"
            disabled={step === 0}
            onClick={() => setStep((s) => Math.max(0, s - 1))}
          >
            <ArrowLeft className="mr-1.5 h-4 w-4" aria-hidden />
            Back
          </Button>
          {step < 3 ? (
            <Button
              className="press"
              disabled={!canContinue}
              onClick={() => setStep((s) => Math.min(3, s + 1))}
            >
              Continue
              <ArrowRight className="ml-1.5 h-4 w-4" aria-hidden />
            </Button>
          ) : (
            <Button
              className="press"
              disabled={createContract.isPending}
              onClick={() => setConfirmOpen(true)}
            >
              {createContract.isPending && <Loader2 className="mr-2 h-4 w-4 animate-spin" aria-hidden />}
              Create contract
            </Button>
          )}
        </div>
      </div>

      <ConfirmDialog
        open={confirmOpen}
        onOpenChange={setConfirmOpen}
        weight="heavy"
        title="Create this contract?"
        description={`This creates the contract in the system. You can then send it to the freelancer for signature. The contract only becomes binding once both parties have signed.`}
        consequences={[
          { label: "Total value", detail: `${money(total)} across ${milestones.length} milestones` },
          { label: "What happens next", detail: "Send for signature from the contract page" },
        ]}
        confirmLabel="Create contract"
        onConfirm={send}
      />
    </AppShell>
  );
}
