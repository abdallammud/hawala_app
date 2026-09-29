<x-layouts.app :title="__('Settings')">
    <a href="{{ route('setup.index') }}" class="inline-flex items-center gap-1.5 text-sm text-slate-500 hover:text-slate-700 mb-2">
        <x-icon name="chevron-right" class="w-4 h-4 rotate-180" />
        {{ __('Setup') }}
    </a>

    <form method="POST" action="{{ route('setup.settings.update') }}" class="space-y-6">
        @csrf
        @method('PUT')

        <x-card :title="__('Fees')">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <x-select name="fees_mode" label="{{ __('Fee mode') }}" :options="['whole' => __('Whole (find containing tier)'), 'marginal' => __('Marginal (progressive)')]" :value="$settings['fees']['mode']" />
                <x-input name="fees_min_fee" label="{{ __('Minimum fee ($)') }}" type="number" min="0" step="0.01" prefix="$" :value="$settings['fees']['min_fee_cents'] / 100" />
                <x-input name="fees_large_transfer" label="{{ __('Large transfer threshold ($)') }}" type="number" min="0" step="0.01" prefix="$" :value="$settings['fees']['large_transfer_cents'] / 100" />
            </div>
            <div class="mt-5 space-y-3">
                <x-checkbox name="fees_refund_on_cancel" label="{{ __('Refund the fee when a transfer is cancelled') }}" :checked="$settings['fees']['refund_on_cancel']" />
                <x-checkbox name="fees_hold_large_transfers" label="{{ __('Hold large transfers for admin approval') }}" :checked="$settings['fees']['hold_large_transfers']" />
            </div>
        </x-card>

        <x-card :title="__('Commission')">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <x-select name="commission_send_type" label="{{ __('Default send commission type') }}" :options="\App\Enums\CommissionType::options()" :value="$settings['commission']['send_type']" />
                <x-input name="commission_send_value" label="{{ __('Default send commission value') }}" type="number" min="0" :value="$settings['commission']['send_value']" hint="{{ __('Basis points if percent (500 = 5%), cents if flat.') }}" />
                <x-select name="commission_payout_type" label="{{ __('Default payout commission type') }}" :options="\App\Enums\CommissionType::options()" :value="$settings['commission']['payout_type']" />
                <x-input name="commission_payout_value" label="{{ __('Default payout commission value') }}" type="number" min="0" :value="$settings['commission']['payout_value']" hint="{{ __('Basis points if percent, cents if flat.') }}" />
            </div>
            <div class="mt-5">
                <x-checkbox name="commission_move_requires_approval" label="{{ __('Commission move to send balance requires admin approval') }}" :checked="$settings['commission']['move_requires_approval']" />
            </div>
        </x-card>

        <x-card :title="__('Currency')">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <x-input name="currency_margin_bp" label="{{ __('FX margin (basis points)') }}" type="number" min="0" max="10000" :value="$settings['currency']['margin_bp']" hint="{{ __('500 = 5% margin on non-USD transfers.') }}" />
                <x-input name="currency_max_rate_age_hours" label="{{ __('Max rate age (hours)') }}" type="number" min="1" :value="$settings['currency']['max_rate_age_hours']" hint="{{ __('Older rates block non-USD transfers unless a manual rate is set.') }}" />
            </div>
        </x-card>

        <x-card :title="__('Payout')">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <x-input name="payout_claim_minutes" label="{{ __('Claim timeout (minutes)') }}" type="number" min="1" :value="$settings['payout']['claim_minutes']" />
            </div>
            <div class="mt-5 space-y-3">
                <x-checkbox name="payout_allow_negative" label="{{ __('Allow payout balance to go negative') }}" :checked="$settings['payout']['allow_negative']" />
                <x-checkbox name="payout_reference_required" label="{{ __('Require a payment reference when marking paid') }}" :checked="$settings['payout']['reference_required']" />
            </div>
        </x-card>

        <x-card :title="__('Credit')">
            <div class="max-w-xs">
                <x-input name="credit_default_max" label="{{ __('Default max credit ($)') }}" type="number" min="0" step="0.01" prefix="$" :value="$settings['credit']['default_max_cents'] !== null ? $settings['credit']['default_max_cents'] / 100 : null" hint="{{ __('Leave blank for no limit.') }}" />
            </div>
        </x-card>

        <x-card :title="__('Receipts')">
            <x-checkbox name="receipts_enabled" label="{{ __('Enable transfer receipt PDFs') }}" :checked="$settings['receipts']['enabled']" />
        </x-card>

        <x-card :title="__('General')">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <x-input name="general_company_name" label="{{ __('Company name') }}" :value="$settings['general']['company_name']" />
                <x-input name="general_timezone" label="{{ __('Timezone') }}" :value="$settings['general']['timezone']" />
                <x-input name="general_duplicate_warning_minutes" label="{{ __('Duplicate transfer warning window (minutes)') }}" type="number" min="0" :value="$settings['general']['duplicate_warning_minutes']" />
            </div>
        </x-card>

        <div class="flex justify-end gap-3">
            <x-button :href="route('setup.index')" variant="ghost">{{ __('Back') }}</x-button>
            <x-button type="submit">{{ __('Save settings') }}</x-button>
        </div>
    </form>
</x-layouts.app>
