<?php

namespace App\Exceptions\Expenses;

use RuntimeException;

/** The expense is not in a state where the action makes sense (already decided, still approved, …). */
class ExpenseStateException extends RuntimeException {}
