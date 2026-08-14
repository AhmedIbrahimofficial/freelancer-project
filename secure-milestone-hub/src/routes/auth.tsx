import { useState } from "react";
import { createFileRoute, useNavigate, Link } from "@tanstack/react-router";
import { AnimatePresence, motion } from "motion/react";
import { AlertCircle, ArrowRight, Briefcase, CheckCircle2, Loader2, PenTool } from "lucide-react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Logo } from "@/components/app-shell";
import { useAuth } from "@/lib/auth";
import { ApiError } from "@/lib/api";
import { cn } from "@/lib/utils";

export const Route = createFileRoute("/auth")({
  head: () => ({
    meta: [
      { title: "Sign in or create an account — Escrowa" },
      {
        name: "description",
        content:
          "Create an Escrowa account as a client or a freelancer and start protecting your work with milestone contracts and escrow.",
      },
    ],
  }),
  component: AuthPage,
});

type Role = "client" | "freelancer";

interface FieldState {
  value: string;
  error: string | null;
  ok: boolean;
}

const empty: FieldState = { value: "", error: null, ok: false };

function validate(field: "name" | "email" | "password", value: string): string | null {
  if (field === "name") {
    if (value.trim().length === 0) return "Enter the name that will appear on your contracts.";
    if (value.trim().length < 2) return "That looks too short to be a full name.";
    return null;
  }
  if (field === "email") {
    if (value.trim().length === 0) return "Enter the email you want contract notices sent to.";
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(value.trim()))
      return "That address is missing an @ or a domain — check for typos.";
    return null;
  }
  if (value.length === 0) return "Choose a password of at least 10 characters.";
  if (value.length < 10) return `Add ${10 - value.length} more characters — 10 minimum.`;
  if (!/[0-9]/.test(value) && !/[^a-zA-Z0-9]/.test(value))
    return "Include at least one number or symbol.";
  return null;
}

function Field({
  id,
  label,
  type = "text",
  placeholder,
  state,
  onChange,
  hint,
}: {
  id: "name" | "email" | "password";
  label: string;
  type?: string;
  placeholder: string;
  state: FieldState;
  onChange: (v: string) => void;
  hint?: string;
}) {
  return (
    <div className="space-y-1.5">
      <Label htmlFor={id}>{label}</Label>
      <div className="relative">
        <Input
          id={id}
          type={type}
          value={state.value}
          placeholder={placeholder}
          onChange={(e) => onChange(e.target.value)}
          aria-invalid={Boolean(state.error)}
          className={cn(
            "pr-9",
            state.error && "border-destructive focus-visible:ring-destructive/40",
            state.ok && "border-success/60",
          )}
        />
        <AnimatePresence>
          {state.ok && (
            <motion.span
              initial={{ opacity: 0, scale: 0.7 }}
              animate={{ opacity: 1, scale: 1 }}
              exit={{ opacity: 0, scale: 0.7 }}
              className="absolute top-1/2 right-3 -translate-y-1/2 text-success"
            >
              <CheckCircle2 className="h-4 w-4" aria-hidden />
            </motion.span>
          )}
        </AnimatePresence>
      </div>
      <div className="relative h-5">
        <AnimatePresence mode="wait" initial={false}>
          {state.error ? (
            <motion.p
              key="err"
              initial={{ opacity: 0, y: -4 }}
              animate={{ opacity: 1, y: 0 }}
              exit={{ opacity: 0, y: -4 }}
              transition={{ duration: 0.15 }}
              className="absolute inset-x-0 top-0 flex items-center gap-1.5 text-xs text-destructive"
            >
              <AlertCircle className="h-3.5 w-3.5 shrink-0" aria-hidden />
              {state.error}
            </motion.p>
          ) : (
            hint && (
              <motion.p
                key="hint"
                initial={{ opacity: 0 }}
                animate={{ opacity: 1 }}
                exit={{ opacity: 0 }}
                className="absolute inset-x-0 top-0 text-xs text-muted-foreground"
              >
                {hint}
              </motion.p>
            )
          )}
        </AnimatePresence>
      </div>
    </div>
  );
}

function AuthPage() {
  const navigate = useNavigate();
  const { login, register } = useAuth();
  const [mode, setMode] = useState<"signup" | "signin">("signup");
  const [selected, setSelected] = useState<Role>("client");
  const [name, setName] = useState(empty);
  const [email, setEmail] = useState(empty);
  const [password, setPassword] = useState(empty);
  const [submitting, setSubmitting] = useState(false);
  const [serverError, setServerError] = useState<string | null>(null);

  const fields = mode === "signup" ? [name, email, password] : [email, password];
  const canSubmit =
    !submitting &&
    fields.every((f) => f.value.length > 0 && !f.error) &&
    (mode === "signin" || Boolean(selected));

  function change(field: "name" | "email" | "password", value: string) {
    const error = validate(field, value);
    const next: FieldState = {
      value,
      error: value.length > 0 ? error : null,
      ok: !error && value.length > 0,
    };
    if (field === "name") setName(next);
    if (field === "email") setEmail(next);
    if (field === "password") setPassword(next);
  }

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    if (!canSubmit) return;
    setServerError(null);
    setSubmitting(true);
    try {
      if (mode === "signup") {
        await register({
          name: name.value.trim(),
          email: email.value.trim(),
          password: password.value,
          role: selected,
        });
      } else {
        await login(email.value.trim(), password.value);
      }
      toast.success(mode === "signup" ? "Account created" : "Signed in");
      navigate({ to: "/dashboard" });
    } catch (err) {
      if (err instanceof ApiError) {
        setServerError(err.summary);
      } else {
        setServerError("Something went wrong. Please try again.");
      }
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="grid min-h-screen lg:grid-cols-[1fr_1.05fr]">
      <aside className="relative hidden flex-col justify-between border-r border-border bg-secondary/50 p-10 lg:flex">
        <Logo />
        <div className="max-w-md">
          <h2 className="text-3xl leading-snug">
            "The contract said three milestones. Escrowa made sure all three got paid."
          </h2>
          <p className="mt-4 text-sm text-muted-foreground">
            Ines Baptista — independent engineer, Lisbon. 34 contracts, 0 disputes.
          </p>
        </div>
        <ul className="space-y-2 text-sm text-muted-foreground">
          <li>· Funds held by a licensed escrow partner, never by Escrowa</li>
          <li>· Evidence-based mediation with a written outcome</li>
          <li>· Reputation stats you can audit line by line</li>
        </ul>
      </aside>

      <main className="flex items-center justify-center px-4 py-12 sm:px-8">
        <div className="w-full max-w-md">
          <div className="lg:hidden">
            <Logo className="mb-8" />
          </div>

          <div className="inline-flex rounded-md border border-border bg-secondary p-1">
            {(["signup", "signin"] as const).map((m) => (
              <button
                key={m}
                onClick={() => { setMode(m); setServerError(null); }}
                className={cn(
                  "press rounded-sm px-4 py-1.5 text-sm transition-colors",
                  mode === m
                    ? "bg-card font-medium text-foreground shadow-card"
                    : "text-muted-foreground",
                )}
              >
                {m === "signup" ? "Create account" : "Sign in"}
              </button>
            ))}
          </div>

          <h1 className="mt-6 text-3xl">
            {mode === "signup" ? "Set up your account" : "Welcome back"}
          </h1>
          <p className="mt-2 text-sm text-muted-foreground">
            {mode === "signup"
              ? "Your role sets your default dashboard. You can work in both directions later."
              : "Sign in to review milestones, disputes and escrow activity."}
          </p>

          {/* Server-level error */}
          {serverError && (
            <div className="mt-4 flex items-start gap-2 rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2.5 text-sm text-destructive">
              <AlertCircle className="mt-0.5 h-4 w-4 shrink-0" aria-hidden />
              {serverError}
            </div>
          )}

          <form onSubmit={submit} className="mt-8 space-y-5">
            {mode === "signup" && (
              <fieldset className="space-y-3">
                <legend className="text-sm font-medium">I am mainly here to…</legend>
                <div className="grid gap-3 sm:grid-cols-2">
                  {(
                    [
                      { value: "client" as Role, title: "Hire", body: "I pay for work and fund escrow.", Icon: Briefcase },
                      { value: "freelancer" as Role, title: "Freelance", body: "I do the work and get paid.", Icon: PenTool },
                    ]
                  ).map((opt) => (
                    <button
                      type="button"
                      key={opt.value}
                      onClick={() => setSelected(opt.value)}
                      className={cn(
                        "press rounded-lg border p-4 text-left transition-colors",
                        selected === opt.value
                          ? "border-primary bg-primary/5 ring-1 ring-primary/30"
                          : "border-border bg-card hover:bg-secondary",
                      )}
                    >
                      <opt.Icon className="h-4 w-4 text-primary" aria-hidden />
                      <p className="mt-2.5 text-sm font-medium">{opt.title}</p>
                      <p className="mt-0.5 text-xs text-muted-foreground">{opt.body}</p>
                    </button>
                  ))}
                </div>
              </fieldset>
            )}

            {mode === "signup" && (
              <Field
                id="name"
                label="Full legal name"
                placeholder="Maya Okonkwo"
                state={name}
                onChange={(v) => change("name", v)}
                hint="This is the name that appears on signatures."
              />
            )}
            <Field
              id="email"
              label="Email"
              type="email"
              placeholder="you@studio.com"
              state={email}
              onChange={(v) => change("email", v)}
            />
            <Field
              id="password"
              label="Password"
              type="password"
              placeholder="At least 10 characters"
              state={password}
              onChange={(v) => change("password", v)}
              hint="10+ characters, including a number or symbol."
            />

            <Button type="submit" size="lg" disabled={!canSubmit} className="press w-full">
              {submitting ? (
                <Loader2 className="mr-2 h-4 w-4 animate-spin" aria-hidden />
              ) : (
                <ArrowRight className="ml-1.5 h-4 w-4" aria-hidden />
              )}
              {mode === "signup" ? "Create account" : "Sign in"}
            </Button>
          </form>

          <p className="mt-6 text-xs leading-relaxed text-muted-foreground">
            <Link to="/dashboard" className="text-primary hover:underline">
              Browse the demo dashboard
            </Link>{" "}
            without an account.
          </p>
        </div>
      </main>
    </div>
  );
}
