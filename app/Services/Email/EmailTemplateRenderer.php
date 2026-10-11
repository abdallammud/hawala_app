<?php

namespace App\Services\Email;

use App\Enums\EmailEvent;
use App\Support\Settings;

/**
 * Fills {placeholders} in a template and turns the plain text into the HTML
 * the email layout shows. Placeholders the event cannot supply render empty,
 * rather than leaving a raw {code} in a message someone will read.
 */
class EmailTemplateRenderer
{
    /** @param array<string, scalar|null> $values */
    public function render(string $text, EmailEvent $event, array $values): string
    {
        $replacements = [];

        foreach ($event->placeholders() as $key) {
            $replacements['{'.$key.'}'] = trim((string) ($values[$key] ?? ''));
        }

        // strtr does one pass over the template, so a value that happens to
        // contain "{code}" is never expanded a second time.
        return trim(strtr($text, $replacements));
    }

    /** A subject is a single line. */
    public function renderSubject(string $subject, EmailEvent $event, array $values): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $this->render($subject, $event, $values)));
    }

    /** {placeholders} written in $text that this event does not know. */
    public function unknownPlaceholders(string $text, EmailEvent $event): array
    {
        preg_match_all('/\{([a-z_]+)\}/i', $text, $found);

        return array_values(array_unique(array_diff($found[1], $event->placeholders())));
    }

    /** Plain text → safe HTML: everything escaped first, then links and line breaks. */
    public function toHtml(string $text): string
    {
        $escaped = e($text);

        $linked = preg_replace_callback(
            '~https?://[^\s<]+~i',
            fn (array $m) => '<a href="'.$m[0].'" style="color:#29468a;">'.$m[0].'</a>',
            $escaped,
        );

        return nl2br($linked, false);
    }

    /** Sample values so an admin can preview a template before saving it. */
    public function sampleValues(EmailEvent $event): array
    {
        return [
            'recipient_name' => 'Farah Omar',
            'company_name' => Settings::get('general.company_name', config('app.name')),
            'link' => url('/'),
            'code' => 'HW-0042',
            'amount' => '$250.00',
            'fee' => '$12.50',
            'sender_name' => 'Ali Hassan',
            'receiver_name' => 'Amina Abdi',
            'sender_phone' => '+46701234567',
            'receiver_phone' => '+252612345678',
            'branch' => 'Mogadishu',
            'agent_name' => 'Ali Hassan',
            'payout_agent_name' => 'Farah Omar',
            'date' => now()->format('Y-m-d H:i'),
            'status' => 'Queued',
            'reason' => 'Customer changed their mind',
            'kind' => 'Top-up (paid)',
            'decided_by' => 'Demo Admin',
            'expense_number' => 'EX-0007',
            'expense_type' => 'Office Rent',
            'payee' => 'Landlord',
            'account' => 'Mogadishu Bank',
            'created_by' => 'Demo Clerk',
        ];
    }
}
