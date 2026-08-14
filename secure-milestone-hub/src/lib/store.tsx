import { createContext, useCallback, useContext, useMemo, useState, type ReactNode } from "react";
import {
  ESCROW_PARTNER,
  PROCESSOR,
  currentUser,
  seedContracts,
  seedDisputes,
  seedLedger,
  seedNotifications,
  seedProfiles,
  type AppNotification,
  type Contract,
  type ContractEvent,
  type Dispute,
  type EvidenceEntry,
  type LedgerEntry,
  type Milestone,
  type MilestoneStatus,
  type Profile,
  type Role,
} from "./mock-data";

const uid = () => Math.random().toString(36).slice(2, 9);
const now = () => new Date().toISOString();

interface AppState {
  role: Role;
  user: typeof currentUser;
  contracts: Contract[];
  disputes: Dispute[];
  ledger: LedgerEntry[];
  profiles: Profile[];
  notifications: AppNotification[];
  paymentMethodOnFile: boolean;
}

interface AppActions {
  setRole: (r: Role) => void;
  getContract: (id: string) => Contract | undefined;
  getDispute: (id: string) => Dispute | undefined;
  getProfile: (handle: string) => Profile | undefined;
  addContract: (c: Contract) => void;
  signContract: (id: string, signerName: string) => void;
  fundContract: (id: string) => void;
  setMilestoneStatus: (
    contractId: string,
    milestoneId: string,
    status: MilestoneStatus,
    note?: string,
  ) => void;
  releaseMilestone: (contractId: string, milestoneId: string) => void;
  openDispute: (contractId: string, milestoneId: string, reason: string, body: string) => string;
  addEvidence: (disputeId: string, entry: Omit<EvidenceEntry, "id" | "at">) => void;
  resolveDispute: (disputeId: string, summary: string) => void;
  addPaymentMethod: () => void;
  withdraw: (amount: number, fee: number, destination: string) => void;
  markAllRead: () => void;
  markRead: (id: string) => void;
  pushNotification: (n: Omit<AppNotification, "id" | "at" | "read">) => void;
}

const StoreContext = createContext<(AppState & AppActions) | null>(null);

export function StoreProvider({ children }: { children: ReactNode }) {
  const [role, setRole] = useState<Role>("freelancer");
  const [contracts, setContracts] = useState<Contract[]>(seedContracts);
  const [disputes, setDisputes] = useState<Dispute[]>(seedDisputes);
  const [ledger, setLedger] = useState<LedgerEntry[]>(seedLedger);
  const [profiles] = useState<Profile[]>(seedProfiles);
  const [notifications, setNotifications] = useState<AppNotification[]>(seedNotifications);
  const [paymentMethodOnFile, setPaymentMethodOnFile] = useState(false);

  const patchContract = useCallback((id: string, fn: (c: Contract) => Contract) => {
    setContracts((prev) => prev.map((c) => (c.id === id ? fn(c) : c)));
  }, []);

  const addEvent = (c: Contract, ev: Omit<ContractEvent, "id" | "at">): Contract => ({
    ...c,
    events: [...c.events, { ...ev, id: uid(), at: now() }],
  });

  const pushNotification = useCallback((n: Omit<AppNotification, "id" | "at" | "read">) => {
    setNotifications((prev) => [{ ...n, id: uid(), at: now(), read: false }, ...prev]);
  }, []);

  const actions: AppActions = useMemo(
    () => ({
      setRole,
      getContract: (id) => contracts.find((c) => c.id === id),
      getDispute: (id) => disputes.find((d) => d.id === id),
      getProfile: (handle) => profiles.find((p) => p.handle === handle),
      addContract: (c) => setContracts((prev) => [c, ...prev]),
      signContract: (id, signerName) =>
        patchContract(id, (c) => {
          const signedBy = c.signedBy.includes(signerName) ? c.signedBy : [...c.signedBy, signerName];
          const bothSigned = signedBy.length >= 2;
          return addEvent(
            {
              ...c,
              signedBy,
              status: bothSigned ? "in_progress" : "awaiting_signature",
            },
            {
              actor: signerName,
              type: "signed",
              label: bothSigned ? "Signed by both parties" : `Signed by ${signerName}`,
              detail: "Typed-name signature recorded",
            },
          );
        }),
      fundContract: (id) => {
        patchContract(id, (c) => {
          const total = c.milestones.reduce((s, m) => s + m.amount, 0);
          setLedger((prev) => [
            {
              id: uid(),
              at: now(),
              type: "deposit",
              amount: total,
              currency: c.currency,
              contractId: c.id,
              contractTitle: c.title,
              trigger: `Escrow funded by ${c.client.name}`,
              processor: PROCESSOR,
            },
            {
              id: uid(),
              at: now(),
              type: "hold",
              amount: total,
              currency: c.currency,
              contractId: c.id,
              contractTitle: c.title,
              trigger: "Funds held against all milestones",
              processor: ESCROW_PARTNER,
            },
            ...prev,
          ]);
          return addEvent(
            {
              ...c,
              status: c.status === "awaiting_signature" ? c.status : "in_progress",
              milestones: c.milestones.map((m) =>
                m.fundState === "unfunded" ? { ...m, fundState: "held" } : m,
              ),
            },
            {
              actor: ESCROW_PARTNER,
              type: "funded",
              label: `Escrow funded — ${total.toLocaleString("en-US", { style: "currency", currency: c.currency, maximumFractionDigits: 0 })} held`,
              detail: `Held by ${ESCROW_PARTNER}`,
            },
          );
        });
      },
      setMilestoneStatus: (contractId, milestoneId, status, note) =>
        patchContract(contractId, (c) => {
          const m = c.milestones.find((x) => x.id === milestoneId);
          const updated: Contract = {
            ...c,
            milestones: c.milestones.map((x) => (x.id === milestoneId ? { ...x, status } : x)),
          };
          const labelMap: Record<string, string> = {
            approved: `Milestone approved — ${m?.title ?? ""}`,
            changes_requested: `Changes requested — ${m?.title ?? ""}`,
            submitted: `Milestone submitted — ${m?.title ?? ""}`,
          };
          return addEvent(
            {
              ...updated,
              status:
                status === "changes_requested"
                  ? "in_progress"
                  : status === "submitted"
                    ? "milestone_review"
                    : updated.status,
            },
            {
              actor: currentUser.name,
              type:
                status === "approved"
                  ? "milestone_approved"
                  : status === "changes_requested"
                    ? "changes_requested"
                    : "milestone_submitted",
              label: labelMap[status] ?? "Milestone updated",
              ...(note ? { detail: note } : {}),
            },
          );
        }),
      releaseMilestone: (contractId, milestoneId) =>
        patchContract(contractId, (c) => {
          const m = c.milestones.find((x) => x.id === milestoneId);
          if (m) {
            setLedger((prev) => [
              {
                id: uid(),
                at: now(),
                type: "release",
                amount: m.amount,
                currency: c.currency,
                contractId: c.id,
                contractTitle: c.title,
                trigger: `Milestone approved — ${m.title}`,
                processor: ESCROW_PARTNER,
              },
              ...prev,
            ]);
          }
          const milestones = c.milestones.map((x) =>
            x.id === milestoneId
              ? { ...x, status: "released" as MilestoneStatus, fundState: "released" as const }
              : x,
          );
          const allReleased = milestones.every((x) => x.fundState === "released");
          return addEvent(
            { ...c, milestones, status: allReleased ? "completed" : "in_progress" },
            {
              actor: ESCROW_PARTNER,
              type: allReleased ? "completed" : "milestone_released",
              label: `${m ? m.amount.toLocaleString("en-US", { style: "currency", currency: c.currency, maximumFractionDigits: 0 }) : ""} released to ${c.freelancer.name}`,
              detail: `Released by ${ESCROW_PARTNER}`,
            },
          );
        }),
      openDispute: (contractId, milestoneId, reason, body) => {
        const id = `d-${uid().slice(0, 4)}`;
        const contract = contracts.find((c) => c.id === contractId);
        const milestone = contract?.milestones.find((m) => m.id === milestoneId);
        setDisputes((prev) => [
          {
            id,
            contractId,
            milestoneId,
            reason,
            status: "submitted",
            openedAt: now(),
            openedBy: currentUser.name,
            amountInDispute: milestone?.amount ?? 0,
            entries: [
              {
                id: uid(),
                authorHandle: currentUser.handle,
                authorName: currentUser.name,
                side: "claimant",
                at: now(),
                body,
                clause: reason,
                files: [],
                isNew: true,
              },
            ],
          },
          ...prev,
        ]);
        patchContract(contractId, (c) =>
          addEvent(
            {
              ...c,
              status: "disputed",
              disputeId: id,
              milestones: c.milestones.map((m) =>
                m.id === milestoneId ? { ...m, status: "disputed" } : m,
              ),
            },
            {
              actor: currentUser.name,
              type: "dispute_opened",
              label: `Dispute opened on ${milestone?.title ?? "milestone"}`,
              detail: `Funds frozen pending resolution`,
            },
          ),
        );
        pushNotification({
          title: "Dispute submitted",
          body: "Escrowa mediation will review within 2 business days. Funds are frozen until resolution.",
          tone: "warning",
          href: `/disputes/${id}`,
        });
        return id;
      },
      addEvidence: (disputeId, entry) =>
        setDisputes((prev) =>
          prev.map((d) =>
            d.id === disputeId
              ? {
                  ...d,
                  status: d.status === "submitted" ? "under_review" : d.status,
                  entries: [
                    ...d.entries.map((e) => ({ ...e, isNew: false })),
                    { ...entry, id: uid(), at: now(), isNew: true },
                  ],
                }
              : d,
          ),
        ),
      resolveDispute: (disputeId, summary) => {
        setDisputes((prev) =>
          prev.map((d) =>
            d.id === disputeId
              ? {
                  ...d,
                  status: "resolved",
                  entries: [
                    ...d.entries.map((e) => ({ ...e, isNew: false })),
                    {
                      id: uid(),
                      authorHandle: "escrowa",
                      authorName: "Escrowa Mediation",
                      side: "mediator" as const,
                      at: now(),
                      body: summary,
                      files: [],
                      isNew: true,
                    },
                  ],
                }
              : d,
          ),
        );
        const dispute = disputes.find((d) => d.id === disputeId);
        if (dispute) {
          patchContract(dispute.contractId, (c) =>
            addEvent(
              {
                ...c,
                status: "in_progress",
                milestones: c.milestones.map((m) =>
                  m.id === dispute.milestoneId ? { ...m, status: "approved" } : m,
                ),
              },
              {
                actor: "Escrowa Mediation",
                type: "dispute_resolved",
                label: "Dispute resolved",
                detail: summary,
              },
            ),
          );
        }
      },
      addPaymentMethod: () => setPaymentMethodOnFile(true),
      withdraw: (amount, fee, destination) => {
        setLedger((prev) => [
          {
            id: uid(),
            at: now(),
            type: "withdrawal",
            amount,
            currency: "USD",
            trigger: `Withdrawal to ${destination}`,
            processor: PROCESSOR,
          },
          ...(fee > 0
            ? [
                {
                  id: uid(),
                  at: now(),
                  type: "fee" as const,
                  amount: fee,
                  currency: "USD",
                  trigger: "Withdrawal fee",
                  processor: PROCESSOR,
                },
              ]
            : []),
          ...prev,
        ]);
      },
      markAllRead: () => setNotifications((prev) => prev.map((n) => ({ ...n, read: true }))),
      markRead: (id) =>
        setNotifications((prev) => prev.map((n) => (n.id === id ? { ...n, read: true } : n))),
      pushNotification,
    }),
    [contracts, disputes, profiles, patchContract, pushNotification],
  );

  const value = {
    role,
    user: currentUser,
    contracts,
    disputes,
    ledger,
    profiles,
    notifications,
    paymentMethodOnFile,
    ...actions,
  };

  return <StoreContext.Provider value={value}>{children}</StoreContext.Provider>;
}

export function useStore() {
  const ctx = useContext(StoreContext);
  if (!ctx) throw new Error("useStore must be used inside StoreProvider");
  return ctx;
}

export function useBalances() {
  const { ledger, contracts } = useStore();
  const available = ledger.reduce((sum, e) => {
    if (e.type === "release") return sum + e.amount;
    if (e.type === "withdrawal" || e.type === "fee") return sum - e.amount;
    return sum;
  }, 0);
  const held = contracts
    .flatMap((c) => c.milestones)
    .filter((m) => m.fundState === "held" || m.fundState === "releasing")
    .reduce((sum, m) => sum + m.amount, 0);
  const frozen = contracts
    .filter((c) => c.status === "disputed")
    .flatMap((c) => c.milestones)
    .filter((m) => m.status === "disputed")
    .reduce((sum, m) => sum + m.amount, 0);
  return { available, held, frozen };
}

export type { Milestone };
