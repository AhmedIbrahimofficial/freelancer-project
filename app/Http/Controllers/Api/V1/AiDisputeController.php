<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateAiDisputeSummary;
use App\Models\AiDisputeSummary;
use App\Models\Dispute;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiDisputeController extends Controller
{
    /**
     * POST /api/v1/disputes/{id}/ai-summary
     * Queue an AI summary generation for a dispute's evidence thread.
     */
    public function generateSummary(Request $request, Dispute $dispute): JsonResponse
    {
        $user = $request->user();

        abort_if(! $user->isAdmin(), 403, 'Only admins can trigger AI summaries.');
        abort_if(! $dispute->isOpen(), 422, 'AI summaries can only be generated for open disputes.');

        $summary = AiDisputeSummary::create([
            'dispute_id'   => $dispute->id,
            'type'         => 'summary',
            'summary_text' => '',
            'status'       => 'pending',
        ]);

        GenerateAiDisputeSummary::dispatch($summary);

        return response()->json([
            'message' => 'AI summary generation queued. Result will be available shortly.',
            'summary' => $summary,
        ], 202);
    }

    /**
     * POST /api/v1/disputes/{id}/ai-suggest
     * Queue an AI resolution suggestion for a dispute.
     */
    public function generateSuggestion(Request $request, Dispute $dispute): JsonResponse
    {
        $user = $request->user();

        abort_if(! $user->isAdmin(), 403, 'Only admins can trigger AI suggestions.');
        abort_if(! $dispute->isOpen(), 422, 'AI suggestions can only be generated for open disputes.');

        $suggestion = AiDisputeSummary::create([
            'dispute_id'   => $dispute->id,
            'type'         => 'suggestion',
            'summary_text' => '',
            'status'       => 'pending',
        ]);

        GenerateAiDisputeSummary::dispatch($suggestion);

        return response()->json([
            'message'    => 'AI suggestion generation queued. Result will be available shortly.',
            'suggestion' => $suggestion,
        ], 202);
    }
}
