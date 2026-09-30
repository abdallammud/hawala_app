<?php

/**
 * Sidebar menu — one flat list, no section headings. Each item is hidden
 * unless the signed-in user holds its `permission`
 * (see partials/sidebar-nav.blade.php).
 */
return [
    ['label' => 'Dashboard', 'route' => 'dashboard', 'icon' => 'home', 'permission' => 'dashboard.view'],
    ['label' => 'New Transfer', 'route' => 'transfers.create', 'icon' => 'plus-circle', 'permission' => 'transfers.create'],
    ['label' => 'All Transfers', 'route' => 'transfers.index', 'icon' => 'arrow-right-left', 'permission' => 'transfers.view_own'],
    ['label' => 'Payout Queue', 'route' => 'queue.index', 'icon' => 'inbox-stack', 'permission' => 'queue.view'],
    ['label' => 'Cancellations', 'route' => 'cancellation-requests.index', 'icon' => 'x-circle', 'permission' => 'transfers.approve_cancel'],
    ['label' => 'Balance Requests', 'route' => 'balance-requests.index', 'icon' => 'banknotes', 'permission' => 'balances.request'],
    ['label' => 'Commission', 'route' => 'commission.index', 'icon' => 'gift', 'permission' => 'commission.view_own'],
    ['label' => 'Company Accounts', 'route' => 'company-accounts.index', 'icon' => 'building-library', 'permission' => 'company_accounts.view'],
    ['label' => 'Users', 'route' => 'users.index', 'icon' => 'users', 'permission' => 'users.view'],
    ['label' => 'Setup', 'route' => 'setup.index', 'icon' => 'cog-6-tooth', 'permission' => 'setup.branches'],
    ['label' => 'Reports', 'route' => 'reports.index', 'icon' => 'chart-bar', 'permission' => 'reports.agent_statement'],
    ['label' => 'Audit Log', 'route' => 'audit.index', 'icon' => 'shield-check', 'permission' => 'audit.view'],
];
