<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Auth\TwoFactorService;
use Illuminate\Console\Command;

/**
 * The way back in when nobody is left who can reset two-factor from the
 * Users screen — e.g. the only administrator lost their phone and their
 * recovery codes. Needs shell access to the server, which is the point.
 */
class ResetTwoFactor extends Command
{
    protected $signature = 'hawala:reset-two-factor {email : Email address of the user}';

    protected $description = 'Clear a user\'s two-factor authentication so they set it up again at their next login.';

    public function handle(TwoFactorService $twoFactor): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();

        if (! $user) {
            $this->error('No user with that email address.');

            return self::FAILURE;
        }

        $twoFactor->reset($user);

        activity()->performedOn($user)->log('Two-factor authentication reset from the command line');

        $this->info("Two-factor cleared for {$user->name}. They will be asked to set it up when they next log in.");

        return self::SUCCESS;
    }
}
