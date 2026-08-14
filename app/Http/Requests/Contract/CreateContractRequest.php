<?php

namespace App\Http\Requests\Contract;

use Illuminate\Foundation\Http\FormRequest;

class CreateContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Only clients can create contracts
        return $this->user()?->isClient() || $this->user()?->isAdmin();
    }

    public function rules(): array
    {
        return [
            'freelancer_id'          => ['required', 'exists:users,id'],
            'title'                  => ['required', 'string', 'max:255'],
            'scope'                  => ['required', 'string', 'max:50000'],
            'total_amount'           => ['required', 'numeric', 'min:1', 'max:9999999'],
            'currency'               => ['nullable', 'string', 'size:3'],
            'start_date'             => ['nullable', 'date', 'after_or_equal:today'],
            'end_date'               => ['nullable', 'date', 'after:start_date'],
            'terms'                  => ['nullable', 'string', 'max:50000'],
            'milestones'             => ['nullable', 'array', 'min:1', 'max:50'],
            'milestones.*.title'     => ['required', 'string', 'max:255'],
            'milestones.*.description' => ['nullable', 'string', 'max:5000'],
            'milestones.*.amount'    => ['required', 'numeric', 'min:0.01'],
            'milestones.*.due_date'  => ['nullable', 'date'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // Ensure milestone amounts don't exceed contract total
            if ($this->has('milestones') && $this->has('total_amount')) {
                $milestonesTotal = collect($this->milestones)->sum('amount');
                if (round($milestonesTotal, 2) > round((float) $this->total_amount, 2)) {
                    $validator->errors()->add('milestones', 'Milestone amounts exceed the contract total amount.');
                }
            }

            // Prevent client assigning contract to themselves
            if ($this->freelancer_id && $this->user() && (int) $this->freelancer_id === $this->user()->id) {
                $validator->errors()->add('freelancer_id', 'You cannot assign a contract to yourself.');
            }
        });
    }
}
