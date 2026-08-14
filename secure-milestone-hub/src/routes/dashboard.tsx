import { useMemo, useState } from "react";
import { createFileRoute, Link } from "@tanstack/react-router";
import { AnimatePresence, motion } from "motion/react";
import { FilePlus2, LogOut } from "lucide-react";
import { toast } from "sonner";
import { AppShell } from "@/components/app-shell";
import { DashboardSkeleton } from "@/components/skeletons";
import { EmptyState } from "@/components/empty-state";
import { StatCard } from "@/components/verification";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { money } from "@/lib/mock-data";
import { useAuth } from "@/lib/auth";
import { useDashboard, errorMessage } from "@/lib/queries";
import { type ApiContract } from "@/lib/api";
import { cn } from "@/lib/utils";

export const Route = createFileRoute("/dashboard")({
  head: () => ({
    meta: [
      { title: "Your contracts — Escrowa" },
      { name: "description", content: "Every contract, its status, and the escrow balance." },
    ],
  }),
  component: Dashboard,
});

type FilterStatus = "all" | "draft" | "pending_signature" | "active" | "completed" | "cancelled" | "disputed";

const filters: { id: FilterStatus; label: string }[] = [
  { id: "all", label: "All" },
  { id: "pending_signature", label: "Awaiting signature" },
  { id: "active", label: "Active" },
  { id: "disputed", label: "Disputed" },
  { id: "completed", label: "Completed" },
];

/** Map Laravel status → display label. */
function statusLabel(s: string) {
  const map: Record<string, string> = {
    draft: "Draft",
    pending_signature: "Awaiting signature",
    active: "Active",
    completed: "Completed",
    cancelled: "Cancelled",
    disputed: "Disputed",
  };
  return map[s] ?? s;
}

/** Colour class for status pill. */
function statusClass(s: string) {
  if (s === "active") return "bg-primary/10 text-primary";
  if (s === "disputed") return "bg-destructive/10 text-destructive";
  if (s === "completed") return "bg-success/10 text-success";
  return "bg-secondary text-muted-foreground";
}

function ContractRow({ contract }: { contract: ApiContract }) {
  const total = parseFloat(contract.total_amount);
  const counterparty = contract.client ?? contract.freelancer;

  return (
    <motion.div
      layout
      initial={{ opacity: 0, y: 8 }}
      animate={{ opacity: 1, y: 0 }}
      exit={{ opacity: 0 }}
      className="surface-card p-5 hover:shadow-md transition-shadow"
    >
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <Link
            to="/contracts/$contractId"
            params={{ contractId: contract.id }}
            className="font-medium hover:underline line-clamp-1"
          >
            {contract.title}
          </Link>
          {counterparty && (
            <p className="mt-0.5 text-xs text-muted-foreground">
              with {counterparty.name}
            </p>
          )}
        </div>
        <span className={cn("shrink-0 rounded-full px-2.5 py-0.5 text-xs font-medium", statusClass(contract.status))}>
          {statusLabel(contract.status)}
        </span>
      </div>
      <div className="mt-3 flex items-center justify-between">
        <span className="numeric text-sm">{money(total, contract.currency)}</span>
        <span className="text-xs text-muted-foreground">
          {new Date(contract.created_at).toLocaleDateString("en-GB", { day: "numeric", month: "short", year: "numeric" })}
        </span>
      </div>
      {contract.milestones && (
        <div className="mt-2 flex gap-1">
          {contract.milestones.map((m) => (
            <span
              key={m.id}
              title={m.title}
              className={cn(
                "h-1.5 flex-1 rounded-full",
                m.status === "approved" || m.status === "released" ? "bg-success" :
                m.status === "submitted" ? "bg-primary" :
                m.status === "disputed" ? "bg-destructive" : "bg-border",
              )}
            />
          ))}
        </div>
      )}
    </motion.div>
  );
}

function Dashboard() {
  const { user, logout } = useAuth();
  const [filter, setFilter] = useState<FilterStatus>("all");
  const [query, setQuery] = useState("");
  const [sort, setSort] = useState<"recent" | "value">("recent");

  const { data, isLoading, isError, error } = useDashboard(filter !== "all" ? filter : undefined);

  const visible = useMemo(() => {
    if (!data?.data) return [];
    return [...data.data]
      .filter((c) =>
        query.trim().length === 0
          ? true
          : `${c.title} ${c.id}`.toLowerCase().includes(query.toLowerCase()),
      )
      .sort((a, b) =>
        sort === "value"
          ? parseFloat(b.total_amount) - parseFloat(a.total_amount)
          : new Date(b.created_at).getTime() - new Date(a.created_at).getTime(),
      );
  }, [data, query, sort]);

  // Aggregate escrow stats from contracts
  const { held, disputed } = useMemo(() => {
    if (!data?.data) return { held: 0, disputed: 0 };
    let held = 0;
    let disputed = 0;
    for (const c of data.data) {
      const milestones = c.milestones ?? [];
      for (const m of milestones) {
        const amt = parseFloat(m.amount);
        if (m.status === "disputed") disputed += amt;
        else if (["pending", "in_progress", "submitted"].includes(m.status)) held += amt;
      }
    }
    return { held, disputed };
  }, [data]);

  async function handleLogout() {
    await logout();
    toast.success("Signed out");
  }

  return (
    <AppShell>
      <div className="flex items-end justify-between gap-4">
        <div>
          <h1 className="text-3xl">Contracts</h1>
          {user && (
            <p className="mt-1.5 text-sm text-muted-foreground">
              Signed in as {user.name} · {user.role}
            </p>
          )}
        </div>
        {user && (
          <Button variant="ghost" size="sm" onClick={handleLogout}>
            <LogOut className="mr-1.5 h-4 w-4" aria-hidden />
            Sign out
          </Button>
        )}
      </div>

      {isLoading ? (
        <div className="mt-8">
          <DashboardSkeleton />
        </div>
      ) : isError ? (
        <div className="mt-8 rounded-md border border-destructive/40 bg-destructive/10 p-4 text-sm text-destructive">
          {errorMessage(error)} —{" "}
          <button className="underline" onClick={() => window.location.reload()}>
            retry
          </button>
        </div>
      ) : (
        <>
          <div className="mt-8 grid gap-4 sm:grid-cols-3">
            <StatCard
              label="Active contracts"
              value={String(data?.data.filter((c) => c.status === "active").length ?? 0)}
              note="Contracts currently in progress."
            />
            <StatCard
              label="Held in escrow"
              value={money(held)}
              note="Estimated from active milestone amounts."
            />
            <StatCard
              label="In dispute"
              value={money(disputed)}
              note="Frozen until dispute resolution."
              tone="watch"
            />
          </div>

          <div className="mt-8 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div className="flex flex-wrap gap-1.5">
              {filters.map((f) => (
                <button
                  key={f.id}
                  onClick={() => setFilter(f.id)}
                  className={cn(
                    "press rounded-full border px-3 py-1.5 text-xs font-medium transition-colors",
                    filter === f.id
                      ? "border-primary bg-primary text-primary-foreground"
                      : "border-border bg-card text-muted-foreground hover:text-foreground",
                  )}
                >
                  {f.label}
                </button>
              ))}
            </div>
            <div className="flex gap-2">
              <Input
                value={query}
                onChange={(e) => setQuery(e.target.value)}
                placeholder="Search by title"
                className="sm:w-64"
              />
              <Button
                variant="outline"
                className="press shrink-0"
                onClick={() => setSort(sort === "recent" ? "value" : "recent")}
              >
                Sort: {sort === "recent" ? "Newest" : "Value"}
              </Button>
            </div>
          </div>

          {visible.length === 0 ? (
            <div className="mt-6">
              <EmptyState
                icon={<FilePlus2 className="h-7 w-7" />}
                title={!data?.data.length ? "No contracts yet" : "Nothing matches those filters"}
                body={
                  !data?.data.length
                    ? "Draft your first milestone contract."
                    : "Try clearing the search or changing the filter."
                }
                actionLabel="Create a contract"
                actionTo="/contracts/new"
                secondary={
                  query || filter !== "all" ? (
                    <button
                      onClick={() => { setFilter("all"); setQuery(""); }}
                      className="text-sm text-primary hover:underline"
                    >
                      Clear filters
                    </button>
                  ) : null
                }
              />
            </div>
          ) : (
            <motion.div layout className="mt-6 grid gap-4 lg:grid-cols-2">
              <AnimatePresence mode="popLayout">
                {visible.map((c) => (
                  <ContractRow key={c.id} contract={c} />
                ))}
              </AnimatePresence>
            </motion.div>
          )}
        </>
      )}
    </AppShell>
  );
}
