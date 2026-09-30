<?php

return [

    'fees' => [
        'mode' => 'whole', // 'whole' | 'marginal'
        'min_fee_cents' => 0,
        'refund_on_cancel' => true,
        'large_transfer_cents' => 300000, // $3,000
        'hold_large_transfers' => false,
    ],

    'commission' => [
        'send_type' => 'percent', // 'percent' | 'flat' | 'none'
        'send_value' => 5000, // 50% in basis points
        'payout_type' => 'percent',
        'payout_value' => 2000, // 20% in basis points
        'move_requires_approval' => false,
    ],

    'currency' => [
        'margin_bp' => 0,
        'max_rate_age_hours' => 72,
    ],

    'payout' => [
        'claim_minutes' => 30,
        'allow_negative' => false,
        'reference_required' => false,
    ],

    'credit' => [
        'default_max_cents' => null, // null = no limit
    ],

    'sms' => [
        'enabled' => false,
        'driver' => 'log', // 'log' | 'http'
        'http' => [],
    ],

    'receipts' => [
        'enabled' => false,
    ],

    'general' => [
        'company_name' => 'Blue Star',
        'timezone' => 'Africa/Mogadishu',
        'duplicate_warning_minutes' => 10,
    ],

];
