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
 * Sent to both parties once the financial action (transfer or refund) for a
 * resolved dispute has been executed. This confirms money has actually moved,
 * not just that a decision was made.
 */
class DisputeResolutionExecutedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Dispute $dispute,
        public readonly User    $recipient,
        public readonly string  $action,          // 'transfer' | 'refund' | 'none'
        public readonly string  $stripeReference,
    ) {}

    public function envelope(): Envelope
    {
        $subject = match ($this->action) {
            'transfer' => "Funds released: {$this->dispute->contract->title}",
            'refund'   => "Refund initiated: {$this->dispute->contract->title}",
            default    => "Dispute closed: {$this->dispute->contract->title}",
        };

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.dispute-resolution-executed');
    }
}
