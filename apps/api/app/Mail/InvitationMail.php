<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        private readonly string $token,
        private readonly string $expiresAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your Metricove invitation');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.invitation',
            with: [
                'invitationUrl' => route('invitation.accept').'#token='.urlencode($this->token),
                'expiresAt' => $this->expiresAt,
            ],
        );
    }

    /**
     * @return array<int, mixed>
     */
    public function attachments(): array
    {
        return [];
    }
}
