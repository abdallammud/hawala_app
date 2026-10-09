<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Full permission list — BUILD.md §10.
     */
    public const PERMISSIONS = [
        'dashboard.view',

        'transfers.create', 'transfers.view_own', 'transfers.view_branch', 'transfers.view_all',
        'transfers.cancel_own', 'transfers.request_cancel', 'transfers.cancel_any',
        'transfers.approve_cancel', 'transfers.approve_large',
        'transfers.record_collection', 'transfers.export',

        'queue.view', 'queue.claim', 'queue.mark_paid', 'queue.force_assign', 'queue.unassign',

        'balances.request', 'balances.approve', 'balances.give_credit', 'balances.record_repayment',
        'balances.fund_payout', 'balances.view_all',

        'commission.view_own', 'commission.view_all', 'commission.move', 'commission.approve_move',
        'commission.request_withdraw', 'commission.approve_withdraw',

        'company_accounts.view', 'company_accounts.manage', 'company_accounts.transfer',

        'users.view', 'users.create', 'users.edit', 'users.deactivate', 'users.set_commission',
        'users.manage_payout_accounts', 'users.set_credit_limit',

        'setup.branches', 'setup.account_types', 'setup.currencies', 'setup.fees', 'setup.settings',
        'setup.sms', 'setup.roles',

        'reports.transfers', 'reports.agent_statement', 'reports.uncollected', 'reports.credit',
        'reports.commissions', 'reports.payouts_by_method', 'reports.branch_summary',
        'reports.profit', 'reports.large_transfers', 'reports.cancellations', 'reports.company_accounts',

        'audit.view',

        'adjustments.create', 'adjustments.approve',
    ];

    private const ADMIN_EXCLUDED = [
        'setup.roles', 'setup.settings', 'adjustments.approve', 'audit.view',
    ];

    private const SENDING_AGENT = [
        'dashboard.view',
        'transfers.create', 'transfers.view_own', 'transfers.cancel_own', 'transfers.request_cancel',
        'transfers.record_collection', 'transfers.export',
        'balances.request',
        'commission.view_own', 'commission.move', 'commission.request_withdraw',
        'reports.transfers', 'reports.agent_statement', 'reports.uncollected', 'reports.commissions',
    ];

    private const PAYOUT_AGENT = [
        'dashboard.view',
        'transfers.view_branch',
        'queue.view', 'queue.claim', 'queue.mark_paid',
        'balances.request',
        'commission.view_own', 'commission.request_withdraw',
        'reports.agent_statement', 'reports.payouts_by_method', 'reports.commissions',
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Role::findOrCreate('super-admin', 'web');

        Role::findOrCreate('admin', 'web')
            ->syncPermissions(array_diff(self::PERMISSIONS, self::ADMIN_EXCLUDED));

        Role::findOrCreate('sending-agent', 'web')
            ->syncPermissions(self::SENDING_AGENT);

        Role::findOrCreate('payout-agent', 'web')
            ->syncPermissions(self::PAYOUT_AGENT);

        // One person who both sends and pays out: everything either role has.
        Role::findOrCreate('sending-payout-agent', 'web')
            ->syncPermissions(array_values(array_unique([...self::SENDING_AGENT, ...self::PAYOUT_AGENT])));
    }
}
