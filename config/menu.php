<?php

/**
 * Sidebar menu — one flat list, no section headings. Each item is hidden
 * unless the signed-in user holds its `permission`
 * (see partials/sidebar-nav.blade.php).
 *
 * `match` lists the route-name patterns that keep the item highlighted,
 * however deep the page is (`transfers/1`, `users/3/edit`, `setup/branches`).
 * Patterns may overlap: `App\Support\Menu` lights the most specific one only,
 * so `transfers.create` wins on the create page and `transfers.*` keeps
 * "All Transfers" lit everywhere else.
 */
return [
    ['label' => 'Dashboard', 'route' => 'dashboard', 'match' => ['dashboard'], 'icon' => 'home', 'permission' => 'dashboard.view'],
    ['label' => 'New Transfer', 'route' => 'transfers.create', 'match' => ['transfers.create'], 'icon' => 'plus-circle', 'permission' => 'transfers.create'],
    ['label' => 'All Transfers', 'route' => 'transfers.index', 'match' => ['transfers.*'], 'icon' => 'arrow-right-left', 'permission' => 'transfers.view_own'],
    ['label' => 'Payout Queue', 'route' => 'queue.index', 'match' => ['queue.*'], 'icon' => 'inbox-stack', 'permission' => 'queue.view'],
    ['label' => 'Cancellations', 'route' => 'cancellation-requests.index', 'match' => ['cancellation-requests.*'], 'icon' => 'x-circle', 'permission' => 'transfers.approve_cancel'],
    ['label' => 'Balance Requests', 'route' => 'balance-requests.index', 'match' => ['balance-requests.*', 'adjustments.*'], 'icon' => 'banknotes', 'permission' => 'balances.request'],
    ['label' => 'Commission', 'route' => 'commission.index', 'match' => ['commission.*'], 'icon' => 'gift', 'permission' => 'commission.view_own'],
    ['label' => 'Company Accounts', 'route' => 'company-accounts.index', 'match' => ['company-accounts.*'], 'icon' => 'building-library', 'permission' => 'company_accounts.view'],
    ['label' => 'Users', 'route' => 'users.index', 'match' => ['users.*', 'roles.*'], 'icon' => 'users', 'permission' => 'users.view'],
    ['label' => 'Setup', 'route' => 'setup.index', 'match' => ['setup.*'], 'icon' => 'cog-6-tooth', 'permission' => 'setup.branches'],
    ['label' => 'Reports', 'route' => 'reports.index', 'match' => ['reports.*'], 'icon' => 'chart-bar', 'permission' => 'reports.agent_statement'],
    ['label' => 'Audit Log', 'route' => 'audit.index', 'match' => ['audit.*'], 'icon' => 'shield-check', 'permission' => 'audit.view'],
];
