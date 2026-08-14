<?php

use App\Http\Controllers\Api\V1\AiDisputeController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ContractController;
use App\Http\Controllers\Api\V1\DisputeController;
use App\Http\Controllers\Api\V1\MilestoneController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\VerificationController;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — /api/v1/...
|--------------------------------------------------------------------------
*/

// ── Public: Auth ─────────────────────────────────────────────────────────
Route::prefix('v1')->group(function () {

    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login',    [AuthController::class, 'login']);

    // Stripe webhook — must be outside auth middleware (Stripe signs it directly)
    Route::post('/webhooks/stripe', [PaymentController::class, 'stripeWebhook']);

    // ── Authenticated routes ──────────────────────────────────────────
    Route::middleware('auth:sanctum')->group(function () {

        // Auth
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me',      [AuthController::class, 'me']);

        // Dashboard
        Route::get('/dashboard', [ContractController::class, 'dashboard']);

        // Contracts
        Route::post('/contracts',            [ContractController::class, 'store']);
        Route::get('/contracts/{contract}',  [ContractController::class, 'show']);
        Route::post('/contracts/{contract}/send', [ContractController::class, 'send']);
        Route::post('/contracts/{contract}/sign', [ContractController::class, 'sign']);
        Route::post('/contracts/{contract}/fund', [PaymentController::class, 'fund']);

        // Milestones
        Route::post('/milestones/{milestone}/submit',  [MilestoneController::class, 'submit']);
        Route::post('/milestones/{milestone}/approve', [MilestoneController::class, 'approve']);
        Route::post('/milestones/{milestone}/dispute', [MilestoneController::class, 'dispute']);
        Route::post('/milestones/{milestone}/release', [PaymentController::class, 'release']);

        // Disputes
        Route::get('/disputes/{dispute}',              [DisputeController::class, 'show']);
        Route::post('/disputes/{dispute}/evidence',    [DisputeController::class, 'submitEvidence']);
        Route::patch('/disputes/{dispute}/resolve',    [DisputeController::class, 'resolve']);
        Route::post('/disputes/{dispute}/ai-summary',  [AiDisputeController::class, 'generateSummary']);
        Route::post('/disputes/{dispute}/ai-suggest',  [AiDisputeController::class, 'generateSuggestion']);

        // Verifications (Phase 2)
        Route::post('/verifications/id',     [VerificationController::class, 'submitId']);
        Route::get('/verifications/status',  [VerificationController::class, 'status']);

        // Profiles (Phase 2)
        Route::get('/users/{user}/profile', [ProfileController::class, 'show']);
        Route::get('/users/{user}/history', [ProfileController::class, 'history']);

        // Payments & Transactions (Phase 3)
        Route::get('/transactions',        [PaymentController::class, 'transactions']);
        Route::post('/payouts/withdraw',   [PaymentController::class, 'withdraw']);

        // Stripe Connect onboarding
        Route::post('/connect/onboard',  [PaymentController::class, 'onboard']);
        Route::get('/connect/return',    [PaymentController::class, 'connectReturn']);

        // Pusher channel authentication
        Broadcast::routes(['middleware' => ['auth:sanctum']]);
    });
});
