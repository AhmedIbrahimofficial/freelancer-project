<?php

namespace App\Mail;

use App\Models\Contract;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to the counterparty when a contract is sent for signature.
 */
class ContractSignatureRequested extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Contract $contract,
        public readonly User $recipient,
        public readonly User $requestedBy,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Signature requested: {$this->contract->title}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.contract-signature-requested',
        );
    }
}
