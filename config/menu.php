<?php

/**
 * Sidebar structure. Each item's `permission` key will gate visibility once
 * spatie/laravel-permission roles are seeded (Phase 2). Until then the layout
 * renders every item so the full navigation shape can be reviewed.
 */
return [
    [
        'group' => null,
        'items' => [
            ['label' => 'Dashboard', 'route' => 'dashboard', 'icon' => 'home', 'permission' => 'dashboard.view'],
        ],
    ],
    [
        'group' => 'Transfers',
        'items' => [
            ['label' => 'New Transfer', 'route' => 'transfers.create', 'icon' => 'plus-circle', 'permission' => 'transfers.create'],
            ['label' => 'All Transfers', 'route' => 'transfers.index', 'icon' => 'arrow-right-left', 'permission' => 'transfers.view_own'],
            ['label' => 'Payout Queue', 'route' => 'queue.index', 'icon' => 'inbox-stack', 'permission' => 'queue.view'],
        ],
    ],
    [
        'group' => 'Money',
        'items' => [
            ['label' => 'Balance Requests', 'route' => 'balance-requests.index', 'icon' => 'banknotes', 'permission' => 'balances.request'],
            ['label' => 'Commission', 'route' => 'commission.index', 'icon' => 'gift', 'permission' => 'commission.view_own'],
            ['label' => 'Company Accounts', 'route' => 'company-accounts.index', 'icon' => 'building-library', 'permission' => 'company_accounts.view'],
        ],
    ],
    [
        'group' => 'Administration',
        'items' => [
            ['label' => 'Users', 'route' => 'users.index', 'icon' => 'users', 'permission' => 'users.view'],
            ['label' => 'Setup', 'route' => 'setup.index', 'icon' => 'cog-6-tooth', 'permission' => 'setup.branches'],
        ],
    ],
    [
        'group' => 'Insights',
        'items' => [
            ['label' => 'Reports', 'route' => 'reports.index', 'icon' => 'chart-bar', 'permission' => 'reports.agent_statement'],
            ['label' => 'Audit Log', 'route' => 'audit.index', 'icon' => 'shield-check', 'permission' => 'audit.view'],
        ],
    ],
];
