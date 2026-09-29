<?php

namespace App\Exceptions\Transfers;

use RuntimeException;

/**
 * A transfer was not in the status the requested action needs — usually
 * because someone else acted on it first (claimed, paid, cancelled).
 */
class TransferStateException extends RuntimeException
{
    public static function notClaimable(): self
    {
        return new self(__('This transfer is no longer available to claim.'));
    }

    public static function notPayable(): self
    {
        return new self(__('This transfer can no longer be marked paid.'));
    }

    public static function notCancellable(): self
    {
        return new self(__('This transfer can no longer be cancelled.'));
    }

    public static function notYours(): self
    {
        return new self(__('This transfer is claimed by someone else.'));
    }

    public static function alreadyDecided(): self
    {
        return new self(__('This request has already been decided.'));
    }
}
