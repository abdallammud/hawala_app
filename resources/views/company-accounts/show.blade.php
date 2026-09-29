<x-layouts.app :title="$account->name">
    <a href="{{ route('company-accounts.index') }}" class="inline-flex items-center gap-1.5 text-sm text-slate-500 hover:text-slate-700 mb-2">
        <x-icon name="chevron-right" class="w-4 h-4 rotate-180" />
        {{ __('Company Accounts') }}
    </a>

    <x-statement-table :statement="$statement" :closing-balance="$account->ledgerAccount?->balance_cents ?? 0" />
</x-layouts.app>
