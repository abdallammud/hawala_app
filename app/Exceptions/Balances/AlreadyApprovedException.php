<?php

namespace App\Exceptions\Balances;

use RuntimeException;

class AlreadyApprovedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(__('You have already approved this request. A different person must give the next approval.'));
    }
}
