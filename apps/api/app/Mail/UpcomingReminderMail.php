<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class UpcomingReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly string $summary, public readonly string $dueOn) {}

    public function build(): self
    {
        return $this->subject('Metricove household reminder')
            ->text('emails.upcoming-reminder')
            ->with(['summary' => $this->summary, 'dueOn' => $this->dueOn]);
    }
}
