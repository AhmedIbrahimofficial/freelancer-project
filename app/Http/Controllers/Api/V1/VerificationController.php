<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Verification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VerificationController extends Controller
{
    /**
     * POST /api/v1/verifications/id
     * Submit identity verification request.
     */
    public function submitId(Request $request): JsonResponse
    {
        $request->validate([
            'provider' => 'required|in:stripe_identity,persona,onfido',
        ]);

        $user = $request->user();

        $verification = Verification::updateOrCreate(
            ['user_id' => $user->id, 'type' => 'identity'],
            [
                'status'   => 'submitted',
                'provider' => $request->provider,
            ]
        );

        // TODO: dispatch SubmitIdentityVerification job to call provider API

        return response()->json([
            'message'      => 'Identity verification submitted.',
            'verification' => $verification,
        ], 201);
    }

    /**
     * GET /api/v1/verifications/status
     * Get current verification statuses for the authenticated user.
     */
    public function status(Request $request): JsonResponse
    {
        $verifications = Verification::where('user_id', $request->user()->id)->get();

        return response()->json($verifications);
    }
}
