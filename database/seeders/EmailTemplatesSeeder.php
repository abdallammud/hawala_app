<?php

namespace Database\Seeders;

use App\Enums\EmailEvent;
use App\Models\EmailTemplate;
use Illuminate\Database\Seeder;

/** One template per email event, from the default wording. Never overwrites an edited one. */
class EmailTemplatesSeeder extends Seeder
{
    public function run(): void
    {
        foreach (EmailEvent::cases() as $event) {
            EmailTemplate::query()->firstOrCreate(
                ['event' => $event],
                ['subject' => $event->defaultSubject(), 'body' => $event->defaultBody(), 'is_active' => true],
            );
        }
    }
}
