<?php

namespace App\Mail;

use App\Models\Milestone;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to the client when the freelancer submits a milestone for review.
 */
class MilestoneSubmittedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Milestone $milestone,
        public readonly User $recipient,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Milestone ready for review: {$this->milestone->title}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.milestone-submitted',
        );
    }
}
