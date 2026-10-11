<?php

namespace App\Mail;

use App\Models\EmailLog;
use App\Services\Email\EmailSettings;
use App\Services\Email\EmailTemplateRenderer;
use App\Services\Transfers\ReceiptService;
use App\Support\Settings;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Throwable;

/**
 * One email from the log: the subject and text are already rendered and
 * stored, this only dresses them in the company layout (logo, footer) and
 * adds the receipt when the event calls for it.
 */
class TemplatedMail extends Mailable
{
    public function __construct(public EmailLog $log) {}

    public function envelope(): Envelope
    {
        $replyTo = EmailSettings::current()->replyTo;

        return new Envelope(
            subject: $this->log->subject,
            replyTo: filter_var($replyTo, FILTER_VALIDATE_EMAIL) ? [new Address($replyTo)] : [],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.templated',
            text: 'emails.templated-text',
            with: [
                'htmlBody' => app(EmailTemplateRenderer::class)->toHtml($this->log->body),
                'textBody' => $this->log->body,
                'companyName' => Settings::get('general.company_name', config('app.name')),
            ],
        );
    }

    public function attachments(): array
    {
        $transfer = $this->log->attach_receipt ? $this->log->transfer : null;

        if (! $transfer) {
            return [];
        }

        // The email matters more than its attachment: if the PDF cannot be
        // built, the message still goes out and the log says why.
        try {
            $receipts = app(ReceiptService::class);
            $pdf = $receipts->pdf($transfer)->output();
        } catch (Throwable $e) {
            report($e);
            $this->log->update(['error' => 'Receipt could not be attached: '.$e->getMessage()]);

            return [];
        }

        return [Attachment::fromData(fn () => $pdf, $receipts->filename($transfer))->withMime('application/pdf')];
    }
}
