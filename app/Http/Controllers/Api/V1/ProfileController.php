<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    /**
     * GET /api/v1/users/{id}/profile
     * Public profile with reputation stats.
     */
    public function show(User $user): JsonResponse
    {
        $user->load(['reputationStats', 'verifications' => fn ($q) => $q->where('status', 'approved')->select('id', 'user_id', 'type', 'verified_at')]);

        return response()->json([
            'id'              => $user->id,
            'name'            => $user->name,
            'role'            => $user->role,
            'member_since'    => $user->created_at,
            'verified_types'  => $user->verifications->pluck('type'),
            'reputation'      => $user->reputationStats,
        ]);
    }

    /**
     * GET /api/v1/users/{id}/history
     * Contract history for a user (privacy-respecting — only shows to self or admin).
     */
    public function history(Request $request, User $user): JsonResponse
    {
        $viewer = $request->user();

        abort_if($viewer->id !== $user->id && ! $viewer->isAdmin(), 403, 'Access denied.');

        $contracts = Contract::with(['client:id,name', 'freelancer:id,name'])
            ->forUser($user->id)
            ->whereIn('status', ['completed', 'cancelled', 'disputed'])
            ->latest()
            ->paginate(20);

        return response()->json($contracts);
    }
}
