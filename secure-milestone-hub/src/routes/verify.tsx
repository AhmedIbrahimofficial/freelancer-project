import { useState } from "react";
import { createFileRoute } from "@tanstack/react-router";
import { motion } from "motion/react";
import {
  BadgeCheck,
  Building2,
  CreditCard,
  ExternalLink,
  Loader2,
  Mail,
  ShieldCheck,
} from "lucide-react";
import { toast } from "sonner";
import { AppShell } from "@/components/app-shell";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { useAuth } from "@/lib/auth";
import { useQuery, useMutation } from "@tanstack/react-query";
import { getToken } from "@/lib/api";
import { cn } from "@/lib/utils";

export const Route = createFileRoute("/verify")({
  head: () => ({
    meta: [
      { title: "Verification — Escrowa" },
      {
        name: "description",
        content: "Verify your identity, email and payment method to build trust on the platform.",
      },
    ],
  }),
  component: VerifyPage,
});

// ── Types ─────────────────────────────────────────────────────────────────────

interface Verification {
  id: number;
  user_id: number;
  type: "email" | "identity" | "payment";
  status: "pending" | "submitted" | "approved" | "rejected";
  provider: string | null;
  verified_at: string | null;
}

const BASE = (import.meta.env.VITE_API_URL ?? "http://localhost:8000") + "/api/v1";

async function fetchVerifications(): Promise<Verification[]> {
  const token = getToken();
  const res = await fetch(`${BASE}/verifications/status`, {
    headers: { Authorization: `Bearer ${token}`, Accept: "application/json" },
  });
  if (!res.ok) return [];
  return res.json();
}

async function submitIdVerification(provider: string): Promise<void> {
  const token = getToken();
  const res = await fetch(`${BASE}/verifications/id`, {
    method: "POST",
    headers: {
      Authorization: `Bearer ${token}`,
      "Content-Type": "application/json",
      Accept: "application/json",
    },
    body: JSON.stringify({ provider }),
  });
  if (!res.ok) {
    const json = await res.json().catch(() => ({}));
    throw new Error(json.message ?? "Verification request failed");
  }
}

// ── Step definitions ──────────────────────────────────────────────────────────

const steps = [
  {
    type: "email" as const,
    icon: Mail,
    title: "Email verified",
    description:
      "Confirms your email address is reachable. Required to receive contract notifications.",
    how: "Sent automatically when you create your account.",
    canRequest: false,
  },
  {
    type: "identity" as const,
    icon: ShieldCheck,
    title: "Government ID",
    description:
      "Matches a passport or national ID to a liveness check. Required before withdrawing funds.",
    how: "Submit via Stripe Identity, Persona, or Onfido.",
    canRequest: true,
    providers: [
      { value: "stripe_identity", label: "Stripe Identity" },
      { value: "persona", label: "Persona" },
      { value: "onfido", label: "Onfido" },
    ],
  },
  {
    type: "payment" as const,
    icon: CreditCard,
    title: "Payout method",
    description:
      "Confirms your connected bank or card can receive payouts. Required to release funds.",
    how: "Connect via Stripe Connect from the Escrow & Payouts page.",
    canRequest: false,
    actionLabel: "Connect payout account",
    actionTo: "/wallet",
  },
];

// ── Status helpers ─────────────────────────────────────────────────────────────

function statusBadge(status: Verification["status"] | undefined) {
  if (!status || status === "pending")
    return <Badge variant="secondary">Not started</Badge>;
  if (status === "submitted")
    return <Badge className="bg-warning/20 text-warning-foreground border-warning/40">Submitted — under review</Badge>;
  if (status === "approved")
    return <Badge className="bg-success/10 text-success border-success/30">Verified</Badge>;
  if (status === "rejected")
    return <Badge variant="destructive">Rejected — resubmit</Badge>;
}

// ── Page ──────────────────────────────────────────────────────────────────────

function VerifyPage() {
  const { user } = useAuth();
  const [selectedProvider, setSelectedProvider] = useState("stripe_identity");

  const { data: verifications = [], refetch } = useQuery({
    queryKey: ["verifications"],
    queryFn: fetchVerifications,
    enabled: Boolean(getToken()),
    staleTime: 30_000,
  });

  const submit = useMutation({
    mutationFn: (provider: string) => submitIdVerification(provider),
    onSuccess: () => {
      toast.success("Identity verification submitted", {
        description: "We'll notify you once the review is complete.",
      });
      refetch();
    },
    onError: (err: Error) => {
      toast.error("Submission failed", { description: err.message });
    },
  });

  const getStatus = (type: string) =>
    verifications.find((v) => v.type === type)?.status;

  const completedCount = steps.filter((s) => getStatus(s.type) === "approved").length;
  const pct = Math.round((completedCount / steps.length) * 100);

  return (
    <AppShell>
      <div className="mx-auto max-w-2xl">
        {/* Header */}
        <div className="mb-8">
          <h1 className="text-3xl">Verification</h1>
          <p className="mt-2 text-sm text-muted-foreground">
            Verified accounts build trust and unlock all platform features.
            {user && <span className="ml-1">Signed in as {user.name}.</span>}
          </p>

          {/* Progress bar */}
          <div className="mt-5">
            <div className="flex items-center justify-between text-xs text-muted-foreground mb-2">
              <span>{completedCount} of {steps.length} verified</span>
              <span className="numeric">{pct}%</span>
            </div>
            <div className="h-2 w-full overflow-hidden rounded-full bg-secondary">
              <motion.div
                className="h-full bg-primary rounded-full"
                initial={{ width: 0 }}
                animate={{ width: `${pct}%` }}
                transition={{ duration: 0.6, ease: "easeOut" }}
              />
            </div>
          </div>
        </div>

        {/* Steps */}
        <div className="space-y-4">
          {steps.map((step) => {
            const status = getStatus(step.type);
            const isApproved = status === "approved";
            const isSubmitted = status === "submitted";

            return (
              <motion.div
                key={step.type}
                initial={{ opacity: 0, y: 8 }}
                animate={{ opacity: 1, y: 0 }}
                className={cn(
                  "surface-card p-6",
                  isApproved && "border-success/30 bg-success/5",
                )}
              >
                <div className="flex items-start justify-between gap-4">
                  <div className="flex items-start gap-4">
                    <div className={cn(
                      "flex h-10 w-10 shrink-0 items-center justify-center rounded-full border",
                      isApproved
                        ? "border-success/40 bg-success/10 text-success"
                        : "border-border bg-secondary text-muted-foreground",
                    )}>
                      {isApproved
                        ? <BadgeCheck className="h-5 w-5" aria-hidden />
                        : <step.icon className="h-5 w-5" aria-hidden />
                      }
                    </div>
                    <div className="min-w-0">
                      <p className="font-medium">{step.title}</p>
                      <p className="mt-1 text-sm text-muted-foreground">{step.description}</p>
                      <p className="mt-2 text-xs text-muted-foreground">
                        <span className="font-medium text-foreground">How: </span>
                        {step.how}
                      </p>
                    </div>
                  </div>
                  <div className="shrink-0">
                    {statusBadge(status)}
                  </div>
                </div>

                {/* Action area */}
                {!isApproved && !isSubmitted && (
                  <div className="mt-4 border-t border-border pt-4">
                    {step.type === "identity" && step.providers && (
                      <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
                        <select
                          value={selectedProvider}
                          onChange={(e) => setSelectedProvider(e.target.value)}
                          className="h-9 rounded-md border border-input bg-card px-3 text-sm sm:w-48"
                        >
                          {step.providers.map((p) => (
                            <option key={p.value} value={p.value}>{p.label}</option>
                          ))}
                        </select>
                        <Button
                          size="sm"
                          className="press"
                          disabled={submit.isPending || !getToken()}
                          onClick={() => submit.mutate(selectedProvider)}
                        >
                          {submit.isPending && (
                            <Loader2 className="mr-2 h-4 w-4 animate-spin" aria-hidden />
                          )}
                          Submit identity verification
                        </Button>
                        {!getToken() && (
                          <p className="text-xs text-muted-foreground">
                            Sign in to submit verification.
                          </p>
                        )}
                      </div>
                    )}

                    {step.type === "payment" && (
                      <Button asChild size="sm" variant="outline" className="press">
                        <a href="/wallet">
                          <CreditCard className="mr-1.5 h-4 w-4" aria-hidden />
                          Connect payout account
                          <ExternalLink className="ml-1.5 h-3.5 w-3.5" aria-hidden />
                        </a>
                      </Button>
                    )}

                    {step.type === "email" && (
                      <p className="text-xs text-muted-foreground">
                        Email verification is sent automatically on account creation.
                        If you haven't received it, check your spam folder.
                      </p>
                    )}
                  </div>
                )}

                {isSubmitted && (
                  <div className="mt-4 rounded-md border border-warning/30 bg-warning/10 px-3 py-2.5 text-sm text-warning-foreground">
                    Your submission is under review. This typically takes 1–2 business days.
                  </div>
                )}
              </motion.div>
            );
          })}
        </div>

        {/* Footer note */}
        <p className="mt-6 text-xs leading-relaxed text-muted-foreground">
          Verification data is processed by third-party providers (Stripe, Persona, Onfido).
          Escrowa does not store raw identity documents. Verified status is shown to counterparties
          on your public profile to build trust.
        </p>
      </div>
    </AppShell>
  );
}
