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
        // A user who both sends and pays out may claim and pay their own
        // transfer (and earn both commission shares). Off unless the company
        // explicitly accepts that.
        'allow_self_claim' => false,
    ],

    'credit' => [
        'default_max_cents' => null, // null = no limit
    ],

    'sms' => [
        'enabled' => false,
        'driver' => null, // App\Enums\SmsProvider: 'tabaarak' | 'hormuud'; null = not chosen yet
        // Each gateway keeps its own account so switching driver needs no
        // retyping. Password is encrypted; blank api_url = provider default.
        'tabaarak' => ['username' => '', 'password' => '', 'sender_id' => '', 'api_url' => ''],
        'hormuud' => ['username' => '', 'password' => '', 'sender_id' => '', 'api_url' => ''],
    ],

    'transfers' => [
        // Customers always pay in USD: the "Customer pays" box is calculated
        // from "Receiver gets" and its currency cannot be changed.
        'lock_pay_currency' => true,
    ],

    'reports' => [
        // dompdf builds the whole file in memory (about 0.25 MB a row), so the
        // real limit also follows PHP's memory_limit; bigger reports use Print or CSV.
        'pdf_max_rows' => 2000,
    ],

    'receipts' => [
        'enabled' => true,
    ],

    // Suggestions for the country boxes on the transfer form. Free text is
    // still accepted — this only saves typing.
    'countries' => [
        'Somalia', 'Ethiopia', 'Kenya', 'Djibouti', 'Eritrea', 'Uganda', 'Tanzania', 'Egypt', 'Yemen', 'South Africa',
        'Sweden', 'Norway', 'Denmark', 'Finland', 'Netherlands', 'Belgium', 'Germany', 'France', 'United Kingdom',
        'Ireland', 'Italy', 'Spain', 'Switzerland', 'Austria', 'Poland', 'Greece', 'Malta',
        'Canada', 'United States', 'Australia', 'New Zealand',
        'United Arab Emirates', 'Saudi Arabia', 'Qatar', 'Kuwait', 'Turkey',
    ],

    'security' => [
        // Everyone must set up an authenticator app at first login.
        'two_factor_required' => true,
        // Minutes without activity before a signed-in user is logged out. 0 = never.
        'idle_timeout_minutes' => 15,
    ],

    'general' => [
        'company_name' => 'Blue Star',
        'timezone' => 'Africa/Mogadishu',
        'duplicate_warning_minutes' => 10,
    ],

];
