<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\DisputeResolved;
use App\Http\Controllers\Controller;
use App\Models\Dispute;
use App\Models\DisputeEvidence;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class DisputeController extends Controller
{
    /**
     * POST /api/v1/disputes/{id}/evidence
     * Either party submits a message or file as evidence.
     */
    public function submitEvidence(Request $request, Dispute $dispute): JsonResponse
    {
        $request->validate([
            'message' => 'nullable|string|max:10000',
            'file'    => 'nullable|file|max:20480|mimes:pdf,jpg,jpeg,png,gif,webp,mp4,zip,doc,docx',
        ]);

        $user = $request->user();

        abort_if(
            ! in_array($user->id, [$dispute->contract->client_id, $dispute->contract->freelancer_id]) && ! $user->isAdmin(),
            403,
            'You are not a party to this dispute.'
        );
        abort_if($dispute->isResolved(), 422, 'Cannot add evidence to a resolved dispute.');
        abort_if(! $request->filled('message') && ! $request->hasFile('file'), 422, 'Provide a message or a file.');

        $filePath  = null;
        $fileName  = null;
        $fileMime  = null;
        $fileSize  = null;

        if ($request->hasFile('file')) {
            $file      = $request->file('file');
            $filePath  = $file->store("disputes/{$dispute->id}/evidence", 'local');
            $fileName  = $file->getClientOriginalName();
            $fileMime  = $file->getMimeType();
            $fileSize  = $file->getSize();
        }

        $evidence = DisputeEvidence::create([
            'dispute_id' => $dispute->id,
            'user_id'    => $user->id,
            'message'    => $request->message,
            'file_path'  => $filePath,
            'file_name'  => $fileName,
            'file_mime'  => $fileMime,
            'file_size'  => $fileSize,
        ]);

        return response()->json([
            'message'  => 'Evidence submitted.',
            'evidence' => $evidence->load('user:id,name,email'),
        ], 201);
    }

    /**
     * PATCH /api/v1/disputes/{id}/resolve
     * Mediator or admin resolves a dispute.
     */
    public function resolve(Request $request, Dispute $dispute): JsonResponse
    {
        $request->validate([
            'status'           => 'required|in:resolved_client,resolved_freelancer,resolved_split,closed',
            'resolution_notes' => 'required|string|max:10000',
        ]);

        $user = $request->user();

        abort_if(! $user->isAdmin(), 403, 'Only admins and mediators can resolve disputes.');
        abort_if($dispute->isResolved(), 422, 'This dispute is already resolved.');

        $dispute->update([
            'status'           => $request->status,
            'resolution_notes' => $request->resolution_notes,
            'resolved_at'      => now(),
        ]);

        activity('disputes')
            ->performedOn($dispute)
            ->causedBy($user)
            ->withProperties(['status' => $request->status])
            ->log('resolved');

        DisputeResolved::dispatch($dispute->fresh());

        return response()->json([
            'message' => 'Dispute resolved.',
            'dispute' => $dispute->fresh()->load(['raisedBy:id,name,email', 'mediator:id,name,email']),
        ]);
    }

    /**
     * GET /api/v1/disputes/{id}
     * Full dispute thread with evidence.
     */
    public function show(Request $request, Dispute $dispute): JsonResponse
    {
        $user = $request->user();

        abort_if(
            ! in_array($user->id, [$dispute->contract->client_id, $dispute->contract->freelancer_id]) && ! $user->isAdmin(),
            403,
            'Access denied.'
        );

        $dispute->load([
            'contract:id,title,status',
            'milestone:id,title,amount',
            'raisedBy:id,name,email',
            'mediator:id,name,email',
            'evidence.user:id,name,email',
            'latestAiSummary',
        ]);

        return response()->json($dispute);
    }
}
