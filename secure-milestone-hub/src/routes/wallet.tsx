import { useState } from "react";
import { createFileRoute } from "@tanstack/react-router";
import { toast } from "sonner";
import { AppShell } from "@/components/app-shell";
import { ConfirmDialog } from "@/components/confirm-dialog";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { ESCROW_PARTNER, PROCESSOR, money } from "@/lib/mock-data";
import { useStore } from "@/lib/store";

export const Route = createFileRoute("/wallet")({
  head: () => ({
    meta: [
      { title: "Wallet & ledger — Escrowa" },
      {
        name: "description",
        content:
          "Every deposit, hold, release, freeze and withdrawal on one auditable ledger, with the exact trigger for each movement.",
      },
      { property: "og:title", content: "Wallet & ledger — Escrowa" },
      {
        property: "og:description",
        content: "An auditable record of every movement of your money, and one-click withdrawals.",
      },
    ],
  }),
  component: WalletPage,
});

function WalletPage() {
  const { ledger, contracts, withdraw } = useStore();
  const [open, setOpen] = useState(false);
  const [amount, setAmount] = useState("");

  const available = ledger.reduce((sum, e) => {
    if (e.type === "release") return sum + e.amount;
    if (e.type === "withdrawal" || e.type === "fee") return sum - e.amount;
    return sum;
  }, 0);
  const held = contracts
    .flatMap((c) => c.milestones)
    .filter((m) => m.fundState === "held")
    .reduce((s, m) => s + m.amount, 0);
  const frozen = contracts
    .flatMap((c) => c.milestones)
    .filter((m) => m.status === "disputed")
    .reduce((s, m) => s + m.amount, 0);

  const parsed = Number(amount);
  const fee = Math.round(parsed * 0.01 * 100) / 100 || 0;

  return (
    <AppShell>
      <header className="grid grid-cols-[minmax(0,1fr)_auto] items-start gap-4">
        <div className="min-w-0">
          <h1 className="text-2xl sm:text-3xl">Wallet</h1>
          <p className="mt-1 text-sm text-muted-foreground">
            Balances are held by {ESCROW_PARTNER}. Payouts run through {PROCESSOR}.
          </p>
        </div>
        <Button className="press shrink-0" onClick={() => setOpen(true)} disabled={available <= 0}>
          Withdraw
        </Button>
      </header>

      <dl className="mt-6 grid gap-3 sm:grid-cols-3">
        {[
          { label: "Available", value: available, note: "Released and ready to withdraw" },
          { label: "Held in escrow", value: held, note: "Committed to active milestones" },
          { label: "Frozen", value: frozen, note: "Locked pending dispute resolution" },
        ].map((b) => (
          <div key={b.label} className="surface-card p-5">
            <dt className="text-xs text-muted-foreground">{b.label}</dt>
            <dd className="numeric mt-1 text-2xl">{money(b.value)}</dd>
            <p className="mt-1 text-xs text-muted-foreground">{b.note}</p>
          </div>
        ))}
      </dl>

      <section className="mt-8">
        <h2 className="text-xl">Ledger</h2>
        <ul className="surface-card mt-3 divide-y divide-border">
          {ledger.map((e) => (
            <li key={e.id} className="grid grid-cols-[minmax(0,1fr)_auto] gap-3 p-4">
              <div className="min-w-0">
                <p className="text-sm font-medium capitalize">{e.type.replace(/_/g, " ")}</p>
                <p className="mt-0.5 text-xs text-muted-foreground">{e.trigger}</p>
                <p className="numeric mt-1 text-[11px] text-muted-foreground">
                  {new Date(e.at).toLocaleString()} · {e.processor}
                  {e.contractTitle ? ` · ${e.contractTitle}` : ""}
                </p>
              </div>
              <p className="numeric text-sm">
                {e.type === "withdrawal" || e.type === "fee" ? "−" : "+"}
                {money(e.amount, e.currency)}
              </p>
            </li>
          ))}
        </ul>
      </section>

      <ConfirmDialog
        open={open}
        onOpenChange={setOpen}
        weight="heavy"
        title="Withdraw to your bank"
        description={`Payouts settle in 1–2 business days via ${PROCESSOR}. A 1% payout fee applies.`}
        consequences={[
          { label: "Available", detail: money(available) },
          { label: "Payout fee", detail: money(fee) },
          { label: "Destination", detail: "Bank •••• 8821 (GBP)" },
        ]}
        extra={
          <div>
            <label htmlFor="wd" className="text-xs text-muted-foreground">
              Amount to withdraw
            </label>
            <Input
              id="wd"
              inputMode="decimal"
              className="mt-1.5"
              value={amount}
              onChange={(ev) => setAmount(ev.target.value)}
              placeholder={String(available)}
            />
          </div>
        }
        typedConfirmation="WITHDRAW"
        typedConfirmationLabel="Type “WITHDRAW” to confirm the payout"
        confirmLabel="Withdraw funds"
        onConfirm={() => {
          if (!parsed || parsed <= 0 || parsed > available) {
            toast.error("Enter a valid amount", {
              description: `You can withdraw up to ${money(available)}.`,
            });
            return;
          }
          withdraw(parsed, fee, "Bank •••• 8821");
          setAmount("");
          toast.success("Withdrawal on its way", {
            description: `${money(parsed)} sent to Bank •••• 8821.`,
          });
        }}
      />
    </AppShell>
  );
}
