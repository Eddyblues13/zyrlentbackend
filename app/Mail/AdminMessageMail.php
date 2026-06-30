<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AdminMessageMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  string       $subjectLine  The email subject.
     * @param  string       $bodyText     The plain-text message written by the admin.
     * @param  string|null  $recipientName Optional recipient name for a personal greeting.
     */
    public function __construct(
        public string $subjectLine,
        public string $bodyText,
        public ?string $recipientName = null,
    ) {
        //
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->subjectLine,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.admin-message',
            with: [
                'subjectLine'   => $this->subjectLine,
                'bodyText'      => $this->bodyText,
                'recipientName' => $this->recipientName,
            ],
        );
    }
}
