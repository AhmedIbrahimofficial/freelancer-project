<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\ContractSigned;
use App\Http\Controllers\Controller;
use App\Http\Requests\Contract\CreateContractRequest;
use App\Mail\ContractSignatureRequested;
use App\Models\Contract;
use App\Models\ContractSignature;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class ContractController extends Controller
{
    /**
     * GET /api/v1/dashboard
     * List contracts for the authenticated user with optional status filter.
     */
    public function dashboard(Request $request): JsonResponse
    {
        $user = $request->user();

        $contracts = Contract::with(['client:id,name,email', 'freelancer:id,name,email', 'milestones'])
            ->forUser($user->id)
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->latest()
            ->paginate(15);

        return response()->json($contracts);
    }

    /**
     * POST /api/v1/contracts
     */
    public function store(CreateContractRequest $request): JsonResponse
    {
        $user = $request->user();

        // Client creates the contract and assigns a freelancer
        $contract = Contract::create([
            'client_id'      => $user->id,
            'freelancer_id'  => $request->freelancer_id,
            'title'          => $request->title,
            'scope'          => $request->scope,
            'total_amount'   => $request->total_amount,
            'currency'       => $request->currency ?? 'USD',
            'start_date'     => $request->start_date,
            'end_date'       => $request->end_date,
            'terms'          => $request->terms,
            'status'         => 'draft',
        ]);

        // Create milestones if provided
        if ($request->has('milestones')) {
            foreach ($request->milestones as $index => $ms) {
                $contract->milestones()->create([
                    'title'       => $ms['title'],
                    'description' => $ms['description'] ?? null,
                    'amount'      => $ms['amount'],
                    'due_date'    => $ms['due_date'] ?? null,
                    'order'       => $index + 1,
                ]);
            }
        }

        return response()->json($contract->load(['milestones', 'client:id,name,email', 'freelancer:id,name,email']), 201);
    }

    /**
     * GET /api/v1/contracts/{id}
     */
    public function show(Request $request, Contract $contract): JsonResponse
    {
        $this->authorizeContractAccess($request, $contract);

        $contract->load([
            'client:id,name,email,role',
            'freelancer:id,name,email,role',
            'milestones',
            'signatures.user:id,name,email',
            'disputes' => fn ($q) => $q->latest()->limit(5),
        ]);

        return response()->json($contract);
    }

    /**
     * POST /api/v1/contracts/{id}/send
     * Transition draft → pending_signature and notify counterparty.
     */
    public function send(Request $request, Contract $contract): JsonResponse
    {
        $this->authorizeContractAccess($request, $contract);

        abort_if($contract->status !== 'draft', 422, 'Only draft contracts can be sent.');
        abort_if($request->user()->id !== $contract->client_id, 403, 'Only the client can send a contract.');

        $contract->update(['status' => 'pending_signature']);

        // Notify the counterparty (freelancer) that their signature is needed
        $freelancer = User::find($contract->freelancer_id);
        if ($freelancer) {
            Mail::to($freelancer->email)->queue(
                new ContractSignatureRequested(
                    $contract->load('milestones'),
                    $freelancer,
                    $request->user(),
                )
            );
        }

        return response()->json(['message' => 'Contract sent for signature.', 'contract' => $contract]);
    }

    /**
     * POST /api/v1/contracts/{id}/sign
     */
    public function sign(Request $request, Contract $contract): JsonResponse
    {
        $request->validate(['signed_name' => 'required|string|max:255']);

        $user = $request->user();

        abort_if(
            ! in_array($user->id, [$contract->client_id, $contract->freelancer_id]),
            403,
            'You are not a party to this contract.'
        );
        abort_if($contract->status === 'draft', 422, 'Contract must be sent before it can be signed.');
        abort_if($contract->isSignedBy($user), 422, 'You have already signed this contract.');

        $signature = ContractSignature::create([
            'contract_id' => $contract->id,
            'user_id'     => $user->id,
            'signed_name' => $request->signed_name,
            'ip_address'  => $request->ip(),
            'user_agent'  => $request->userAgent(),
            'signed_at'   => now(),
        ]);

        activity('contracts')
            ->performedOn($contract)
            ->causedBy($user)
            ->withProperties(['signed_name' => $request->signed_name])
            ->log('signed');

        // If both parties have signed, activate the contract
        if ($contract->isFullySigned()) {
            $contract->update(['status' => 'active']);
        }

        ContractSigned::dispatch($contract->fresh(), $user);

        return response()->json([
            'message'   => 'Contract signed successfully.',
            'signature' => $signature,
            'contract'  => $contract->fresh(),
        ]);
    }

    // ── Private helpers ──────────────────────────────────────────────

    private function authorizeContractAccess(Request $request, Contract $contract): void
    {
        $user = $request->user();
        abort_if(
            ! in_array($user->id, [$contract->client_id, $contract->freelancer_id]) && ! $user->isAdmin(),
            403,
            'Access denied.'
        );
    }
}
