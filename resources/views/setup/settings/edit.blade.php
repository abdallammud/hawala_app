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
                <x-commission-fields type-name="commission_send_type" value-name="commission_send_value" :type-label="__('Default send commission type')" :value-label="__('Default send commission value')" :type="$settings['commission']['send_type']" :value="$settings['commission']['send_value']" :allow-default="false" />
                <x-commission-fields type-name="commission_payout_type" value-name="commission_payout_value" :type-label="__('Default payout commission type')" :value-label="__('Default payout commission value')" :type="$settings['commission']['payout_type']" :value="$settings['commission']['payout_value']" :allow-default="false" :percent-hint="__('Percent of the fee left after the sending agent\'s share. Type 20 for 20%.')" />
            </div>
            <div class="mt-5">
                <x-checkbox name="commission_move_requires_approval" label="{{ __('Commission move to send balance requires admin approval') }}" :checked="$settings['commission']['move_requires_approval']" />
            </div>
        </x-card>

        <x-card :title="__('Currency')">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <x-input name="currency_margin_bp" label="{{ __('FX margin (basis points)') }}" type="number" min="0" max="5000" :value="$settings['currency']['margin_bp']" hint="{{ __('500 = 5% margin on non-USD transfers.') }}" />
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
                <x-checkbox name="payout_allow_self_claim" label="{{ __('Let an agent claim and pay a transfer they sent themselves') }}" :checked="$settings['payout']['allow_self_claim']" />
            </div>
        </x-card>

        <x-card :title="__('Credit')">
            <div class="max-w-xs">
                <x-input name="credit_default_max" label="{{ __('Default max credit ($)') }}" type="number" min="0" step="0.01" prefix="$" :value="$settings['credit']['default_max_cents'] !== null ? $settings['credit']['default_max_cents'] / 100 : null" hint="{{ __('Leave blank for no limit.') }}" />
            </div>
        </x-card>

        <x-card :title="__('SMS')" :subtitle="__('Nothing is sent until this is on and the individual template is active.')">
            <div
                class="space-y-5"
                x-data="{
                    driver: @js((string) old('sms_driver', $settings['sms']['driver'])),
                    testing: null,
                    result: {},
                    async test(provider) {
                        this.testing = provider;
                        this.result[provider] = null;
                        const field = (name) => document.getElementById('sms_' + provider + '_' + name).value;
                        try {
                            const res = await fetch('{{ route('setup.settings.sms-test') }}', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' },
                                body: JSON.stringify({ provider, username: field('username'), password: field('password'), api_url: field('api_url') }),
                            });
                            const data = await res.json();
                            this.result[provider] = res.ok
                                ? data
                                : { ok: false, message: data.message || @js(__('The connection test failed.')) };
                        } catch (e) {
                            this.result[provider] = { ok: false, message: @js(__('Could not reach the server.')) };
                        } finally {
                            this.testing = null;
                        }
                    },
                }"
            >
                <x-checkbox name="sms_enabled" label="{{ __('Send SMS messages') }}" :checked="$settings['sms']['enabled']" />

                <div class="max-w-xs">
                    <x-select
                        name="sms_driver"
                        label="{{ __('Provider in use') }}"
                        :options="\App\Enums\SmsProvider::options()"
                        placeholder="{{ __('Select provider') }}"
                        :value="$settings['sms']['driver']"
                        x-model="driver"
                        hint="{{ __('Each provider keeps its own account details, so you can switch back any time.') }}"
                    />
                </div>

                @foreach (\App\Enums\SmsProvider::cases() as $gateway)
                    @php($p = $gateway->value)
                    @php($account = $settings['sms'][$p])
                    <div x-show="driver === '{{ $p }}'" x-cloak class="space-y-4 rounded-lg border border-slate-200 p-4">
                        <p class="text-xs text-slate-500">
                            @if ($gateway->requiresSenderId())
                                {{ __('Enter the account Hormuud issued to your company. The sender name is shown on every message (max 11 characters, and it must be approved on your Hormuud account).') }}
                            @else
                                {{ __('Enter the account Tabaarak issued to your company. Tabaarak takes the sender name from the account itself.') }}
                            @endif
                        </p>

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-5">
                            <x-input
                                name="sms_{{ $p }}_username"
                                label="{{ __('API username') }}"
                                :value="$account['username']"
                                autocomplete="off"
                            />
                            <x-input
                                name="sms_{{ $p }}_password"
                                label="{{ __('API password') }}"
                                type="password"
                                autocomplete="new-password"
                                :placeholder="$account['password_saved'] ? '••••••••' : ''"
                                hint="{{ $account['password_saved'] ? __('Saved. Leave blank to keep it.') : __('Not set yet.') }}"
                            />
                            <x-input
                                name="sms_{{ $p }}_sender_id"
                                label="{{ $gateway->requiresSenderId() ? __('Sender name') : __('Sender name (optional)') }}"
                                :value="$account['sender_id']"
                                :maxlength="$gateway->requiresSenderId() ? \App\Services\Sms\HormuudDriver::MAX_SENDER_ID : 20"
                            />
                        </div>

                        <x-input
                            name="sms_{{ $p }}_api_url"
                            label="{{ __('API URL') }}"
                            type="url"
                            :value="$account['api_url']"
                            :placeholder="$gateway->defaultApiUrl()"
                            hint="{{ __('Only change this if :provider gave you a different address. Leave blank for the standard one.', ['provider' => $gateway->label()]) }}"
                        />

                        <div class="flex flex-wrap items-center gap-3">
                            <x-button variant="secondary" size="sm" x-on:click="test('{{ $p }}')" x-bind:disabled="testing !== null">
                                <span x-text="testing === '{{ $p }}' ? @js(__('Testing…')) : @js(__('Test connection'))"></span>
                            </x-button>
                            <p class="text-xs text-slate-500" x-show="!result['{{ $p }}']">{{ __('Logs in only. No SMS is sent.') }}</p>
                            <p
                                class="text-sm"
                                x-show="result['{{ $p }}']"
                                x-cloak
                                :class="result['{{ $p }}']?.ok ? 'text-green-700' : 'text-red-600'"
                                x-text="result['{{ $p }}']?.message"
                            ></p>
                        </div>
                    </div>
                @endforeach

                <div class="flex items-center gap-3">
                    <x-button :href="route('setup.sms-templates.index')" variant="secondary" size="sm">
                        {{ __('Edit templates') }}
                    </x-button>
                    <p class="text-xs text-slate-500">{{ __('Templates are where the message text and recipients live.') }}</p>
                </div>
            </div>
        </x-card>

        <x-card :title="__('Receipts')">
            <x-checkbox name="receipts_enabled" label="{{ __('Enable transfer receipt PDFs') }}" :checked="$settings['receipts']['enabled']" />
        </x-card>

        <x-card :title="__('Security')">
            <div class="space-y-5">
                <x-checkbox name="security_two_factor_required" label="{{ __('Require two-factor authentication (authenticator app) for everyone') }}" :checked="$settings['security']['two_factor_required']" />
                <div class="max-w-xs">
                    <x-input name="security_idle_timeout_minutes" label="{{ __('Log out after inactivity (minutes)') }}" type="number" min="0" max="{{ config('session.lifetime') }}" :value="$settings['security']['idle_timeout_minutes']" hint="{{ __('0 turns it off. Cannot be longer than :max minutes.', ['max' => config('session.lifetime')]) }}" />
                </div>
            </div>
        </x-card>

        <x-card :title="__('General')">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <x-input name="general_company_name" label="{{ __('Company name') }}" :value="$settings['general']['company_name']" />
                <x-input name="general_timezone" label="{{ __('Timezone') }}" :value="$settings['general']['timezone']" />
                <x-input name="general_duplicate_warning_minutes" label="{{ __('Duplicate transfer warning window (minutes)') }}" type="number" min="0" :value="$settings['general']['duplicate_warning_minutes']" />
            </div>
        </x-card>

        <div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-3 [&>*]:w-full sm:[&>*]:w-auto">
            <x-button :href="route('setup.index')" variant="ghost">{{ __('Back') }}</x-button>
            <x-button type="submit">{{ __('Save settings') }}</x-button>
        </div>
    </form>
</x-layouts.app>
