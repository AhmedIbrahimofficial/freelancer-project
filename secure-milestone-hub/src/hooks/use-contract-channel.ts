/**
 * Subscribe to the private-contract.{id} Pusher channel and keep
 * React Query's cache in sync without a page refresh.
 *
 * Usage:
 *   useContractChannel(contractId)   — call inside any contract detail page
 *
 * Events handled:
 *   ContractSigned     → invalidate contract + dashboard
 *   MilestoneSubmitted → invalidate contract + dashboard
 *   MilestoneApproved  → invalidate contract + dashboard
 *   DisputeRaised      → invalidate contract + dashboard
 *   DisputeResolved    → invalidate contract + dispute + dashboard
 */

import { useEffect } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { getEcho } from "@/lib/echo";
import { QK } from "@/lib/queries";

interface SignedPayload {
  contract_id: string;
  signed_by_name: string;
  fully_signed: boolean;
  status: string;
}

interface MilestonePayload {
  contract_id: string;
  milestone_id: string;
  milestone_title: string;
  status: string;
}

interface DisputePayload {
  contract_id: string;
  dispute_id: string;
  milestone_id: string | null;
  status: string;
  reason?: string;
  resolution_notes?: string;
}

export function useContractChannel(contractId: string | undefined) {
  const qc = useQueryClient();

  useEffect(() => {
    if (!contractId) return;

    const echo = getEcho();
    if (!echo) return; // Pusher not configured — skip silently

    const channel = echo.private(`contract.${contractId}`);

    channel.listen(".ContractSigned", (payload: SignedPayload) => {
      qc.invalidateQueries({ queryKey: QK.contract(contractId) });
      qc.invalidateQueries({ queryKey: ["dashboard"] });
      toast.info(
        payload.fully_signed
          ? "Contract is now fully signed by both parties."
          : `${payload.signed_by_name} has signed the contract.`,
      );
    });

    channel.listen(".MilestoneSubmitted", (payload: MilestonePayload) => {
      qc.invalidateQueries({ queryKey: QK.contract(contractId) });
      qc.invalidateQueries({ queryKey: ["dashboard"] });
      toast.info(`Milestone submitted for review: ${payload.milestone_title}`);
    });

    channel.listen(".MilestoneApproved", (payload: MilestonePayload) => {
      qc.invalidateQueries({ queryKey: QK.contract(contractId) });
      qc.invalidateQueries({ queryKey: ["dashboard"] });
      toast.success(`Milestone approved: ${payload.milestone_title}`);
    });

    channel.listen(".DisputeRaised", (payload: DisputePayload) => {
      qc.invalidateQueries({ queryKey: QK.contract(contractId) });
      qc.invalidateQueries({ queryKey: ["dashboard"] });
      toast.warning("A dispute has been opened on this contract.");
    });

    channel.listen(".DisputeResolved", (payload: DisputePayload) => {
      qc.invalidateQueries({ queryKey: QK.contract(contractId) });
      qc.invalidateQueries({ queryKey: ["dashboard"] });
      if (payload.dispute_id) {
        qc.invalidateQueries({ queryKey: QK.dispute(payload.dispute_id) });
      }
      toast.success("The dispute has been resolved.");
    });

    return () => {
      echo.leave(`contract.${contractId}`);
    };
  }, [contractId, qc]);
}
