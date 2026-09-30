<?php

namespace Database\Seeders;

use App\Enums\SmsEvent;
use App\Models\SmsTemplate;
use Illuminate\Database\Seeder;

/**
 * One sensible English template per event. All inactive: nothing sends until
 * an admin switches both `sms.enabled` and the template on (BUILD.md §13).
 */
class SmsTemplatesSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->templates() as $event => $template) {
            SmsTemplate::query()->firstOrCreate(
                ['event' => $event],
                $template + ['is_active' => false],
            );
        }
    }

    private function templates(): array
    {
        return [
            SmsEvent::TransferSent->value => [
                'body' => '{company_name}: {amount_usd} is on its way to {receiver_name}. Reference {code}. Collect at our {branch} branch.',
                'to_sender' => true,
                'to_receiver' => true,
                'to_sending_agent' => false,
                'to_payout_agent' => false,
            ],
            SmsEvent::TransferPaid->value => [
                'body' => '{company_name}: {amount_usd} (ref {code}) has been paid to {receiver_name} on {date}. Thank you.',
                'to_sender' => true,
                'to_receiver' => true,
                'to_sending_agent' => false,
                'to_payout_agent' => false,
            ],
            SmsEvent::TransferCancelled->value => [
                'body' => '{company_name}: transfer {code} for {amount_usd} has been cancelled. Please contact your agent if this is unexpected.',
                'to_sender' => true,
                'to_receiver' => false,
                'to_sending_agent' => true,
                'to_payout_agent' => false,
            ],
            SmsEvent::TopupApproved->value => [
                'body' => '{company_name}: your balance top-up of {amount_usd} was approved on {date}.',
                'to_sender' => false,
                'to_receiver' => false,
                'to_sending_agent' => true,
                'to_payout_agent' => false,
            ],
            SmsEvent::ClaimExpired->value => [
                'body' => '{company_name}: your claim on transfer {code} ({amount_usd}) timed out and is back in the queue.',
                'to_sender' => false,
                'to_receiver' => false,
                'to_sending_agent' => false,
                'to_payout_agent' => true,
            ],
            SmsEvent::LargeTransfer->value => [
                'body' => '{company_name}: large transfer {code} for {amount_usd} was recorded by {agent_name} on {date}.',
                'to_sender' => false,
                'to_receiver' => false,
                'to_sending_agent' => true,
                'to_payout_agent' => false,
            ],
        ];
    }
}
