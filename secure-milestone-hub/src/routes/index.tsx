import { createFileRoute, Link } from "@tanstack/react-router";
import { motion, useReducedMotion } from "motion/react";
import {
  ArrowRight,
  BadgeCheck,
  FileSignature,
  Gavel,
  Landmark,
  Lock,
  ScrollText,
  Sparkles,
  Split,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { Logo } from "@/components/app-shell";
import { ESCROW_PARTNER } from "@/lib/mock-data";

export const Route = createFileRoute("/")({
  head: () => ({
    meta: [
      { title: "Escrowa — Milestone contracts and escrow for freelancers" },
      {
        name: "description",
        content:
          "Binding milestone contracts, fair dispute mediation, verifiable reputation and escrowed payouts for independent work — without a marketplace in the middle.",
      },
      { property: "og:title", content: "Escrowa — Milestone contracts and escrow for freelancers" },
      {
        property: "og:description",
        content:
          "Write the contract, hold the money in escrow, resolve disputes with evidence. Built for independent work across borders.",
      },
    ],
  }),
  component: Landing,
});

const walkthrough = [
  {
    Icon: ScrollText,
    step: "01",
    title: "Write a contract both sides can read",
    body: "Scope, out-of-scope, terms and dated milestones. No marketplace boilerplate — a document you would be comfortable showing a lawyer.",
  },
  {
    Icon: FileSignature,
    step: "02",
    title: "Both parties sign, then escrow is funded",
    body: "Signing and funding are two deliberate actions, never one click. Each is recorded with a timestamp on the shared timeline.",
  },
  {
    Icon: Lock,
    step: "03",
    title: "Milestones are reviewed, not argued about",
    body: "Approve, request changes, or dispute. Every option explains exactly what happens to the money before you confirm it.",
  },
  {
    Icon: Gavel,
    step: "04",
    title: "Disputes follow evidence, not volume",
    body: "A shared append-only evidence thread, referenced to the clauses in your contract, with a documented resolution at the end.",
  },
];

function Landing() {
  const reduce = useReducedMotion();

  return (
    <div className="min-h-screen bg-background">
      <header className="sticky top-0 z-40 border-b border-border bg-background/85 backdrop-blur">
        <div className="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-3 sm:px-6">
          <Logo />
          <div className="flex items-center gap-2">
            <Button asChild variant="ghost" size="sm" className="press">
              <Link to="/auth">Sign in</Link>
            </Button>
            <Button asChild size="sm" className="press">
              <Link to="/auth">Create an account</Link>
            </Button>
          </div>
        </div>
      </header>

      {/* Hero */}
      <section className="relative overflow-hidden border-b border-border">
        <div className="hairline-grid pointer-events-none absolute inset-0 opacity-60" aria-hidden />
        <div className="relative mx-auto max-w-6xl px-4 py-20 sm:px-6 sm:py-28">
          <motion.div
            initial={reduce ? false : { opacity: 0, y: 14 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.5, ease: "easeOut" }}
            className="max-w-3xl"
          >
            <span className="inline-flex items-center gap-2 rounded-full border border-border bg-card px-3 py-1 text-xs font-medium text-muted-foreground">
              <Landmark className="h-3.5 w-3.5 text-primary" aria-hidden />
              Funds held by {ESCROW_PARTNER}
            </span>
            <h1 className="mt-6 text-4xl leading-[1.06] sm:text-6xl">
              Get paid for the work you agreed to.
              <span className="block text-muted-foreground">Nothing more, nothing less.</span>
            </h1>
            <p className="mt-6 max-w-2xl text-lg leading-relaxed text-muted-foreground">
              Escrowa gives freelancers and clients binding milestone contracts, escrowed funds and
              evidence-based dispute mediation — without handing a marketplace 20% of your work.
            </p>
            <div className="mt-8 flex flex-wrap items-center gap-3">
              <Button asChild size="lg" className="press">
                <Link to="/auth">
                  Create your first contract
                  <ArrowRight className="ml-1.5 h-4 w-4" aria-hidden />
                </Link>
              </Button>
              <Button asChild size="lg" variant="outline" className="press">
                <Link to="/dashboard">See a live example</Link>
              </Button>
            </div>
            <dl className="mt-14 grid max-w-2xl grid-cols-2 gap-x-8 gap-y-6 sm:grid-cols-4">
              {[
                ["$184m", "held in escrow"],
                ["1.4 days", "median release time"],
                ["4%", "of contracts disputed"],
                ["96%", "resolved without arbitration"],
              ].map(([value, label]) => (
                <div key={label}>
                  <dt className="numeric text-2xl text-foreground">{value}</dt>
                  <dd className="mt-1 text-xs text-muted-foreground">{label}</dd>
                </div>
              ))}
            </dl>
          </motion.div>
        </div>
      </section>

      {/* Scroll walkthrough */}
      <section className="mx-auto max-w-6xl px-4 py-20 sm:px-6 sm:py-28">
        <div className="max-w-2xl">
          <h2 className="text-3xl sm:text-4xl">How a contract moves</h2>
          <p className="mt-3 text-muted-foreground">
            Four states, each one visible to both sides at all times.
          </p>
        </div>

        <ol className="mt-14 space-y-4">
          {walkthrough.map((item, i) => (
            <motion.li
              key={item.step}
              initial={reduce ? false : { opacity: 0, y: 24 }}
              whileInView={{ opacity: 1, y: 0 }}
              viewport={{ once: true, amount: 0.5 }}
              transition={{ duration: 0.45, ease: "easeOut", delay: i * 0.04 }}
              className="surface-card grid gap-5 p-6 sm:grid-cols-[auto_1fr] sm:p-8"
            >
              <div className="flex items-start gap-4">
                <span className="grid h-11 w-11 shrink-0 place-items-center rounded-lg border border-border bg-secondary text-primary">
                  <item.Icon className="h-5 w-5" aria-hidden />
                </span>
                <span className="numeric pt-2.5 text-sm text-muted-foreground sm:hidden">
                  {item.step}
                </span>
              </div>
              <div className="min-w-0">
                <div className="flex items-baseline gap-3">
                  <span className="numeric hidden text-sm text-muted-foreground sm:block">
                    {item.step}
                  </span>
                  <h3 className="text-xl">{item.title}</h3>
                </div>
                <p className="mt-2 max-w-2xl leading-relaxed text-muted-foreground">{item.body}</p>
              </div>
            </motion.li>
          ))}
        </ol>
      </section>

      {/* Trust pillars */}
      <section className="border-y border-border bg-secondary/50">
        <div className="mx-auto grid max-w-6xl gap-6 px-4 py-16 sm:px-6 md:grid-cols-3">
          {[
            {
              Icon: BadgeCheck,
              title: "Reputation you can audit",
              body: "Completion rate, on-time rate and dispute rate shown separately. No opaque single score you cannot interrogate.",
            },
            {
              Icon: Split,
              title: "Money you can trace",
              body: "Every deposit, hold, release and refund is a ledger line tied to the milestone or dispute that caused it.",
            },
            {
              Icon: Sparkles,
              title: "AI that summarises, never decides",
              body: "Optional AI summaries and suggested splits are clearly labelled and always link back to the raw evidence.",
            },
          ].map((p) => (
            <motion.div
              key={p.title}
              initial={reduce ? false : { opacity: 0, y: 18 }}
              whileInView={{ opacity: 1, y: 0 }}
              viewport={{ once: true, amount: 0.4 }}
              transition={{ duration: 0.4, ease: "easeOut" }}
              className="surface-card p-6"
            >
              <p.Icon className="h-5 w-5 text-primary" aria-hidden />
              <h3 className="mt-4 text-lg">{p.title}</h3>
              <p className="mt-2 text-sm leading-relaxed text-muted-foreground">{p.body}</p>
            </motion.div>
          ))}
        </div>
      </section>

      <section className="mx-auto max-w-6xl px-4 py-20 text-center sm:px-6">
        <h2 className="text-3xl sm:text-4xl">Start with one contract</h2>
        <p className="mx-auto mt-3 max-w-xl text-muted-foreground">
          Draft it in four steps, send it for signature, and never chase an invoice again.
        </p>
        <Button asChild size="lg" className="press mt-8">
          <Link to="/auth">
            Create an account
            <ArrowRight className="ml-1.5 h-4 w-4" aria-hidden />
          </Link>
        </Button>
      </section>

      <footer className="border-t border-border py-8">
        <div className="mx-auto flex max-w-6xl flex-col gap-3 px-4 text-xs text-muted-foreground sm:flex-row sm:items-center sm:justify-between sm:px-6">
          <Logo />
          <p>
            Escrowa does not hold client funds. Escrow is provided by {ESCROW_PARTNER}. Demo data
            only.
          </p>
        </div>
      </footer>
    </div>
  );
}
