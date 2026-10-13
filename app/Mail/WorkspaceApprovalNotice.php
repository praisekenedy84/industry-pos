<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WorkspaceApprovalNotice extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $headline, public string $notice) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->headline,
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'mail.workspace-approval-notice',
        );
    }
}
