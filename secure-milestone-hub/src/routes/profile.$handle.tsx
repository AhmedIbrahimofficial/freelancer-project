import { createFileRoute, useParams } from "@tanstack/react-router";
import { BadgeCheck, MapPin } from "lucide-react";
import { AppShell } from "@/components/app-shell";
import { EmptyState } from "@/components/empty-state";
import { Badge } from "@/components/ui/badge";
import { money } from "@/lib/mock-data";
import { useStore } from "@/lib/store";

export const Route = createFileRoute("/profile/$handle")({
  head: () => ({
    meta: [
      { title: "Verified reputation profile — Escrowa" },
      {
        name: "description",
        content:
          "A portable reputation built from settled contracts: completion rate, on-time rate, dispute rate and verified identity checks.",
      },
      { property: "og:title", content: "Verified reputation profile — Escrowa" },
      {
        property: "og:description",
        content: "Reputation earned from real, settled milestone contracts — not marketplace stars.",
      },
    ],
  }),
  component: ProfilePage,
});

function ProfilePage() {
  const { handle } = useParams({ from: "/profile/$handle" });
  const { getProfile } = useStore();
  const profile = getProfile(handle);

  if (!profile) {
    return (
      <AppShell>
        <EmptyState
          title="No such profile"
          body="This handle does not belong to any Escrowa account."
          actionLabel="Back to contracts"
          actionTo="/dashboard"
        />
      </AppShell>
    );
  }

  const s = profile.stats;
  const stats = [
    { label: "Contracts completed", value: String(s.contractsCompleted) },
    { label: "Completion rate", value: `${s.completionRate}%` },
    { label: "On-time rate", value: `${s.onTimeRate}%` },
    { label: "Dispute rate", value: `${s.disputeRate}%` },
    { label: "Avg. release time", value: `${s.avgReleaseDays} days` },
    { label: "Protected volume", value: money(s.volume) },
  ];

  return (
    <AppShell>
      <header className="surface-card p-6">
        <div className="flex min-w-0 items-start gap-4">
          <div className="grid h-14 w-14 shrink-0 place-items-center rounded-full bg-primary text-lg text-primary-foreground">
            {profile.initials}
          </div>
          <div className="min-w-0">
            <h1 className="truncate text-2xl sm:text-3xl">{profile.name}</h1>
            <p className="mt-1 text-sm text-muted-foreground">{profile.headline}</p>
            <p className="mt-2 flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
              <span className="flex items-center gap-1">
                <MapPin className="h-3.5 w-3.5" aria-hidden />
                {profile.location}
              </span>
              <span>Member since {profile.memberSince}</span>
            </p>
          </div>
        </div>

        <p className="mt-5 max-w-2xl text-sm leading-relaxed text-muted-foreground">{profile.bio}</p>

        <ul className="mt-5 flex flex-wrap gap-2">
          {profile.verifications.map((v) => (
            <li key={v.kind}>
              <Badge variant="secondary" className="gap-1.5">
                <BadgeCheck className="h-3.5 w-3.5 text-success" aria-hidden />
                {v.label}
              </Badge>
            </li>
          ))}
        </ul>
      </header>

      <section className="mt-6">
        <h2 className="text-xl">Track record</h2>
        <dl className="mt-3 grid gap-3 sm:grid-cols-3">
          {stats.map((st) => (
            <div key={st.label} className="surface-card p-4">
              <dt className="text-xs text-muted-foreground">{st.label}</dt>
              <dd className="numeric mt-1 text-xl">{st.value}</dd>
            </div>
          ))}
        </dl>
      </section>

      <section className="mt-6">
        <h2 className="text-xl">Contract history</h2>
        <ul className="surface-card mt-3 divide-y divide-border">
          {profile.history.map((h) => (
            <li key={h.id} className="grid grid-cols-[minmax(0,1fr)_auto] gap-3 p-4">
              <div className="min-w-0">
                <p className="truncate text-sm font-medium">{h.title}</p>
                <p className="mt-0.5 text-xs text-muted-foreground">
                  {h.anonymized ? "Client (private)" : h.counterparty} · closed {h.closedAt}
                </p>
              </div>
              <div className="text-right">
                <p className="numeric text-sm">{money(h.amount)}</p>
                <p className="mt-0.5 text-xs text-muted-foreground capitalize">
                  {h.outcome.replace(/_/g, " ")}
                </p>
              </div>
            </li>
          ))}
        </ul>
      </section>

      <section className="mt-6">
        <h2 className="text-xl">Profile completeness</h2>
        <ul className="surface-card mt-3 space-y-2 p-5">
          {profile.completeness.map((c) => (
            <li key={c.label} className="flex items-center gap-2 text-sm">
              <BadgeCheck
                className={c.done ? "h-4 w-4 text-success" : "h-4 w-4 text-muted-foreground"}
                aria-hidden
              />
              <span className={c.done ? "" : "text-muted-foreground"}>{c.label}</span>
            </li>
          ))}
        </ul>
      </section>
    </AppShell>
  );
}
