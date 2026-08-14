<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\MilestoneApproved;
use App\Events\MilestoneSubmitted;
use App\Http\Controllers\Controller;
use App\Models\Dispute;
use App\Models\Milestone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MilestoneController extends Controller
{
    /**
     * POST /api/v1/milestones/{id}/submit
     * Freelancer submits work for a milestone.
     */
    public function submit(Request $request, Milestone $milestone): JsonResponse
    {
        $request->validate([
            'submission_notes' => 'nullable|string|max:5000',
        ]);

        $user = $request->user();

        abort_if($user->id !== $milestone->contract->freelancer_id, 403, 'Only the freelancer can submit milestones.');
        abort_if(! $milestone->canBeSubmitted(), 422, 'This milestone cannot be submitted in its current state.');

        $milestone->update([
            'status'           => 'submitted',
            'submitted_at'     => now(),
            'submission_notes' => $request->submission_notes,
        ]);

        activity('milestones')
            ->performedOn($milestone)
            ->causedBy($user)
            ->log('submitted');

        MilestoneSubmitted::dispatch($milestone->fresh());

        return response()->json([
            'message'   => 'Milestone submitted for review.',
            'milestone' => $milestone->fresh(),
        ]);
    }

    /**
     * POST /api/v1/milestones/{id}/approve
     * Client approves a submitted milestone.
     */
    public function approve(Request $request, Milestone $milestone): JsonResponse
    {
        $user = $request->user();

        abort_if($user->id !== $milestone->contract->client_id, 403, 'Only the client can approve milestones.');
        abort_if(! $milestone->canBeApproved(), 422, 'This milestone cannot be approved in its current state.');

        $milestone->update([
            'status'      => 'approved',
            'approved_at' => now(),
        ]);

        activity('milestones')
            ->performedOn($milestone)
            ->causedBy($user)
            ->log('approved');

        MilestoneApproved::dispatch($milestone->fresh());

        return response()->json([
            'message'   => 'Milestone approved.',
            'milestone' => $milestone->fresh(),
        ]);
    }

    /**
     * POST /api/v1/milestones/{id}/dispute
     * Either party raises a dispute on a milestone.
     */
    public function dispute(Request $request, Milestone $milestone): JsonResponse
    {
        $request->validate([
            'reason' => 'required|string|max:5000',
        ]);

        $user     = $request->user();
        $contract = $milestone->contract;

        abort_if(
            ! in_array($user->id, [$contract->client_id, $contract->freelancer_id]),
            403,
            'You are not a party to this contract.'
        );
        abort_if(
            in_array($milestone->status, ['pending', 'approved', 'released']),
            422,
            'A dispute cannot be raised on this milestone in its current state.'
        );
        abort_if($milestone->activeDispute()->exists(), 422, 'An open dispute already exists for this milestone.');

        $dispute = Dispute::create([
            'contract_id'  => $contract->id,
            'milestone_id' => $milestone->id,
            'raised_by'    => $user->id,
            'reason'       => $request->reason,
            'status'       => 'open',
        ]);

        $milestone->update(['status' => 'disputed']);
        $contract->update(['status' => 'disputed']);

        activity('disputes')
            ->performedOn($dispute)
            ->causedBy($user)
            ->withProperties(['milestone_id' => $milestone->id, 'reason' => $request->reason])
            ->log('raised');

        return response()->json([
            'message' => 'Dispute raised successfully.',
            'dispute' => $dispute->load('raisedBy:id,name,email'),
        ], 201);
    }
}
