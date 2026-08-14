/**
 * TanStack Query hooks for all API resources.
 * These replace direct mock-data reads in route components.
 */

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  ApiError,
  auth,
  contracts,
  disputes,
  milestones,
  profiles,
  transactions,
  type ApiContract,
} from "./api";

// ── Query keys ────────────────────────────────────────────────────────────────

export const QK = {
  me: ["me"] as const,
  dashboard: (status?: string) => ["dashboard", status ?? "all"] as const,
  contract: (id: string) => ["contract", id] as const,
  dispute: (id: string) => ["dispute", id] as const,
  transactions: (params?: object) => ["transactions", params ?? {}] as const,
  profile: (id: number | string) => ["profile", String(id)] as const,
};

// ── Auth ──────────────────────────────────────────────────────────────────────

export function useMe() {
  return useQuery({
    queryKey: QK.me,
    queryFn: () => auth.me(),
    staleTime: 5 * 60 * 1000,
    retry: false,
  });
}

// ── Dashboard ─────────────────────────────────────────────────────────────────

export function useDashboard(status?: string) {
  return useQuery({
    queryKey: QK.dashboard(status),
    queryFn: () => contracts.list({ status: status !== "all" ? status : undefined }),
    staleTime: 30 * 1000,
  });
}

// ── Contract detail ───────────────────────────────────────────────────────────

export function useContract(id: string) {
  return useQuery({
    queryKey: QK.contract(id),
    queryFn: () => contracts.show(id),
    staleTime: 20 * 1000,
    enabled: Boolean(id),
  });
}

// ── Contract mutations ────────────────────────────────────────────────────────

export function useCreateContract() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: contracts.create,
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["dashboard"] });
    },
  });
}

export function useSendContract() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => contracts.send(id),
    onSuccess: (_, id) => {
      qc.invalidateQueries({ queryKey: QK.contract(id) });
      qc.invalidateQueries({ queryKey: ["dashboard"] });
    },
  });
}

export function useSignContract() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, signed_name }: { id: string; signed_name: string }) =>
      contracts.sign(id, signed_name),
    onSuccess: (_, { id }) => {
      qc.invalidateQueries({ queryKey: QK.contract(id) });
      qc.invalidateQueries({ queryKey: ["dashboard"] });
    },
  });
}

// ── Milestone mutations ───────────────────────────────────────────────────────

export function useSubmitMilestone() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, notes }: { id: string; contractId: string; notes?: string }) =>
      milestones.submit(id, { submission_notes: notes }),
    onSuccess: (_, { contractId }) => {
      qc.invalidateQueries({ queryKey: QK.contract(contractId) });
    },
  });
}

export function useApproveMilestone() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id }: { id: string; contractId: string }) => milestones.approve(id),
    onSuccess: (_, { contractId }) => {
      qc.invalidateQueries({ queryKey: QK.contract(contractId) });
      qc.invalidateQueries({ queryKey: ["dashboard"] });
    },
  });
}

export function useDisputeMilestone() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, reason }: { id: string; contractId: string; reason: string }) =>
      milestones.dispute(id, reason),
    onSuccess: (data, { contractId }) => {
      qc.invalidateQueries({ queryKey: QK.contract(contractId) });
      qc.invalidateQueries({ queryKey: ["dashboard"] });
      if (data.dispute?.id) {
        qc.invalidateQueries({ queryKey: QK.dispute(data.dispute.id) });
      }
    },
  });
}

// ── Dispute ───────────────────────────────────────────────────────────────────

export function useDispute(id: string) {
  return useQuery({
    queryKey: QK.dispute(id),
    queryFn: () => disputes.show(id),
    staleTime: 15 * 1000,
    enabled: Boolean(id),
  });
}

export function useSubmitEvidence() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({
      disputeId,
      message,
      file,
    }: {
      disputeId: string;
      message?: string;
      file?: File;
    }) => disputes.submitEvidence(disputeId, { message, file }),
    onSuccess: (_, { disputeId }) => {
      qc.invalidateQueries({ queryKey: QK.dispute(disputeId) });
    },
  });
}

export function useResolveDispute() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({
      id,
      status,
      resolution_notes,
    }: {
      id: string;
      status: string;
      resolution_notes: string;
    }) => disputes.resolve(id, { status, resolution_notes }),
    onSuccess: (data, { id }) => {
      qc.invalidateQueries({ queryKey: QK.dispute(id) });
      if (data.dispute?.contract_id) {
        qc.invalidateQueries({ queryKey: QK.contract(data.dispute.contract_id) });
        qc.invalidateQueries({ queryKey: ["dashboard"] });
      }
    },
  });
}

// ── Transactions ──────────────────────────────────────────────────────────────

export function useTransactions(params?: { contract_id?: string; type?: string }) {
  return useQuery({
    queryKey: QK.transactions(params),
    queryFn: () => transactions.list(params),
    staleTime: 30 * 1000,
  });
}

// ── Profile ───────────────────────────────────────────────────────────────────

export function useProfile(userId: number | string) {
  return useQuery({
    queryKey: QK.profile(userId),
    queryFn: () => profiles.show(userId),
    staleTime: 5 * 60 * 1000,
    enabled: Boolean(userId),
  });
}

// ── Error helper ──────────────────────────────────────────────────────────────

/** Extract a user-facing message from any thrown value. */
export function errorMessage(err: unknown): string {
  if (err instanceof ApiError) return err.summary;
  if (err instanceof Error) return err.message;
  return "Something went wrong. Please try again.";
}
