<?php

namespace App\Console\Commands;

use App\Services\Transfers\PayoutQueueService;
use Illuminate\Console\Command;

class ExpireClaims extends Command
{
    protected $signature = 'hawala:expire-claims';

    protected $description = 'Return transfers whose payout claim has timed out to the branch queue.';

    public function handle(PayoutQueueService $queue): int
    {
        $count = $queue->expireDueClaims();

        $this->info($count === 0 ? 'No claims to expire.' : "{$count} claim(s) expired.");

        return self::SUCCESS;
    }
}
