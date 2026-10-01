<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Support\Money;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function edit(): View
    {
        $this->authorize('setup.settings');

        return view('setup.settings.edit', ['settings' => Settings::all()]);
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
            'currency_max_round_up_bp' => ['required', 'integer', 'min:0', 'max:5000'],
            'currency_max_rate_age_hours' => ['required', 'integer', 'min:1'],

            'payout_claim_minutes' => ['required', 'integer', 'min:1'],
            'payout_allow_negative' => ['boolean'],
            'payout_reference_required' => ['boolean'],

            'credit_default_max' => ['nullable', 'numeric', 'min:0'],

            'sms_enabled' => ['boolean'],
            'sms_driver' => ['required', 'in:log,http'],
            'sms_http' => ['nullable', 'string', 'json'],

            'receipts_enabled' => ['boolean'],

            'general_company_name' => ['required', 'string', 'max:255'],
            'general_timezone' => ['required', 'string', 'max:64'],
            'general_duplicate_warning_minutes' => ['required', 'integer', 'min:0'],
        ]);

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
        Settings::set('currency.max_round_up_bp', (int) $validated['currency_max_round_up_bp'], $updatedBy);
        Settings::set('currency.max_rate_age_hours', (int) $validated['currency_max_rate_age_hours'], $updatedBy);

        Settings::set('payout.claim_minutes', (int) $validated['payout_claim_minutes'], $updatedBy);
        Settings::set('payout.allow_negative', $request->boolean('payout_allow_negative'), $updatedBy);
        Settings::set('payout.reference_required', $request->boolean('payout_reference_required'), $updatedBy);

        Settings::set('credit.default_max_cents', $request->filled('credit_default_max') ? Money::parseCents((string) $validated['credit_default_max']) : null, $updatedBy);

        Settings::set('sms.enabled', $request->boolean('sms_enabled'), $updatedBy);
        Settings::set('sms.driver', $validated['sms_driver'], $updatedBy);
        // Stored as a decoded array so HttpDriver can read it without
        // decoding a string every send.
        Settings::set('sms.http', json_decode($validated['sms_http'] ?: '{}', true), $updatedBy);

        Settings::set('receipts.enabled', $request->boolean('receipts_enabled'), $updatedBy);

        Settings::set('general.company_name', $validated['general_company_name'], $updatedBy);
        Settings::set('general.timezone', $validated['general_timezone'], $updatedBy);
        Settings::set('general.duplicate_warning_minutes', (int) $validated['general_duplicate_warning_minutes'], $updatedBy);

        return back()->with('status', __('Settings saved.'));
    }
}
