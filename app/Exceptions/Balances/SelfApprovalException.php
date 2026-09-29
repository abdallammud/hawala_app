<?php

namespace App\Exceptions\Balances;

use RuntimeException;

class SelfApprovalException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(__('You cannot approve or reject your own request.'));
    }
}
