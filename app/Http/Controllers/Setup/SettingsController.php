<?php

namespace App\Http\Controllers\Setup;

use App\Enums\SmsProvider;
use App\Http\Controllers\Controller;
use App\Services\Sms\GatewayCredentials;
use App\Services\Sms\HormuudDriver;
use App\Support\Money;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function edit(): View
    {
        $this->authorize('setup.settings');

        $settings = Settings::all();

        // Passwords never go back to the browser; the form only needs to know
        // whether one is saved.
        foreach (SmsProvider::cases() as $gateway) {
            $stored = (array) ($settings['sms'][$gateway->value] ?? []);
            $credentials = GatewayCredentials::forProvider($gateway);

            $settings['sms'][$gateway->value] = [
                'username' => $credentials->username,
                'password_saved' => $credentials->password !== '',
                'sender_id' => $credentials->senderId,
                // Blank means "provider default"; keep it blank in the form.
                'api_url' => trim((string) ($stored['api_url'] ?? '')),
            ];
        }

        return view('setup.settings.edit', ['settings' => $settings]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->authorize('setup.settings');

        $validated = $request->validate([
            'fees_mode' => ['required', 'in:whole,marginal'],
            'fees_min_fee' => ['required', 'numeric', 'min:0'],
            'fees_refund_on_cancel' => ['boolean'],
            'fees_large_transfer' => ['required', 'numeric', 'min:0'],
            'fees_hold_large_transfers' => ['boolean'],

            'commission_send_type' => ['required', 'in:percent,flat,none'],
            'commission_send_value' => ['required', 'integer', 'min:0'],
            'commission_payout_type' => ['required', 'in:percent,flat,none'],
            'commission_payout_value' => ['required', 'integer', 'min:0'],
            'commission_move_requires_approval' => ['boolean'],

            'currency_margin_bp' => ['required', 'integer', 'min:0', 'max:5000'],
            'currency_max_rate_age_hours' => ['required', 'integer', 'min:1'],

            'payout_claim_minutes' => ['required', 'integer', 'min:1'],
            'payout_allow_negative' => ['boolean'],
            'payout_reference_required' => ['boolean'],

            'credit_default_max' => ['nullable', 'numeric', 'min:0'],

            'sms_enabled' => ['boolean'],
            'sms_driver' => ['nullable', Rule::enum(SmsProvider::class)],
            'sms_tabaarak_username' => ['nullable', 'string', 'max:100'],
            'sms_tabaarak_password' => ['nullable', 'string', 'max:255'],
            'sms_tabaarak_sender_id' => ['nullable', 'string', 'max:20'],
            'sms_tabaarak_api_url' => ['nullable', 'url:http,https', 'max:255'],
            'sms_hormuud_username' => ['nullable', 'string', 'max:100'],
            'sms_hormuud_password' => ['nullable', 'string', 'max:255'],
            'sms_hormuud_sender_id' => ['nullable', 'string', 'max:'.HormuudDriver::MAX_SENDER_ID],
            'sms_hormuud_api_url' => ['nullable', 'url:http,https', 'max:255'],

            'receipts_enabled' => ['boolean'],

            'general_company_name' => ['required', 'string', 'max:255'],
            'general_timezone' => ['required', 'string', 'max:64'],
            'general_duplicate_warning_minutes' => ['required', 'integer', 'min:0'],
        ]);

        $gateways = $this->gatewaySettings($request);
        $this->ensureActiveGatewayReady($request, $gateways);

        $updatedBy = $request->user()->id;

        Settings::set('fees.mode', $validated['fees_mode'], $updatedBy);
        Settings::set('fees.min_fee_cents', Money::parseCents((string) $validated['fees_min_fee']), $updatedBy);
        Settings::set('fees.refund_on_cancel', $request->boolean('fees_refund_on_cancel'), $updatedBy);
        Settings::set('fees.large_transfer_cents', Money::parseCents((string) $validated['fees_large_transfer']), $updatedBy);
        Settings::set('fees.hold_large_transfers', $request->boolean('fees_hold_large_transfers'), $updatedBy);

        Settings::set('commission.send_type', $validated['commission_send_type'], $updatedBy);
        Settings::set('commission.send_value', (int) $validated['commission_send_value'], $updatedBy);
        Settings::set('commission.payout_type', $validated['commission_payout_type'], $updatedBy);
        Settings::set('commission.payout_value', (int) $validated['commission_payout_value'], $updatedBy);
        Settings::set('commission.move_requires_approval', $request->boolean('commission_move_requires_approval'), $updatedBy);

        Settings::set('currency.margin_bp', (int) $validated['currency_margin_bp'], $updatedBy);
        Settings::set('currency.max_rate_age_hours', (int) $validated['currency_max_rate_age_hours'], $updatedBy);

        Settings::set('payout.claim_minutes', (int) $validated['payout_claim_minutes'], $updatedBy);
        Settings::set('payout.allow_negative', $request->boolean('payout_allow_negative'), $updatedBy);
        Settings::set('payout.reference_required', $request->boolean('payout_reference_required'), $updatedBy);

        Settings::set('credit.default_max_cents', $request->filled('credit_default_max') ? Money::parseCents((string) $validated['credit_default_max']) : null, $updatedBy);

        Settings::set('sms.enabled', $request->boolean('sms_enabled'), $updatedBy);
        Settings::set('sms.driver', $validated['sms_driver'] ?? null, $updatedBy);

        foreach ($gateways as $provider => $values) {
            Settings::set("sms.{$provider}", $values, $updatedBy);
        }

        Settings::set('receipts.enabled', $request->boolean('receipts_enabled'), $updatedBy);

        Settings::set('general.company_name', $validated['general_company_name'], $updatedBy);
        Settings::set('general.timezone', $validated['general_timezone'], $updatedBy);
        Settings::set('general.duplicate_warning_minutes', (int) $validated['general_duplicate_warning_minutes'], $updatedBy);

        return back()->with('status', __('Settings saved.'));
    }

    /**
     * Both gateways' accounts as they will be stored. A blank password box
     * keeps the saved one (passwords are never sent back to the form).
     *
     * @return array<string, array{username: string, password: string, sender_id: string, api_url: string}>
     */
    private function gatewaySettings(Request $request): array
    {
        $out = [];

        foreach (SmsProvider::cases() as $gateway) {
            $p = $gateway->value;
            $stored = (array) Settings::get("sms.{$p}", []);
            $password = (string) $request->input("sms_{$p}_password", '');

            $out[$p] = [
                'username' => trim((string) $request->input("sms_{$p}_username", '')),
                'password' => $password !== '' ? GatewayCredentials::encrypt($password) : (string) ($stored['password'] ?? ''),
                'sender_id' => trim((string) $request->input("sms_{$p}_sender_id", '')),
                'api_url' => trim((string) $request->input("sms_{$p}_api_url", '')),
            ];
        }

        return $out;
    }

    /** Refuse to switch SMS on with a half-configured gateway. */
    private function ensureActiveGatewayReady(Request $request, array $gateways): void
    {
        if (! $request->boolean('sms_enabled')) {
            return;
        }

        $provider = SmsProvider::tryFrom((string) $request->input('sms_driver'))
            ?? throw ValidationException::withMessages([
                'sms_driver' => __('Select an SMS provider before switching SMS on.'),
            ]);

        $values = $gateways[$provider->value];

        if ($values['username'] === '' || $values['password'] === '') {
            throw ValidationException::withMessages([
                "sms_{$provider->value}_username" => __('Add the username and password for :provider before switching SMS on.', ['provider' => $provider->label()]),
            ]);
        }

        if ($provider->requiresSenderId() && $values['sender_id'] === '') {
            throw ValidationException::withMessages([
                "sms_{$provider->value}_sender_id" => __(':provider needs a sender name before SMS can be switched on.', ['provider' => $provider->label()]),
            ]);
        }
    }
}
