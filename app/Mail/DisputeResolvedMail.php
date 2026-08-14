<?php

namespace App\Mail;

use App\Models\Dispute;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to both parties when a dispute is resolved by a mediator/admin.
 */
class DisputeResolvedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Dispute $dispute,
        public readonly User $recipient,
    ) {}

    public function envelope(): Envelope
    {
        $outcome = match ($this->dispute->status) {
            'resolved_client'     => 'in favour of client',
            'resolved_freelancer' => 'in favour of freelancer',
            'resolved_split'      => 'split resolution',
            default               => 'closed',
        };

        return new Envelope(
            subject: "Dispute resolved ({$outcome}): {$this->dispute->contract->title}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.dispute-resolved',
        );
    }
}
