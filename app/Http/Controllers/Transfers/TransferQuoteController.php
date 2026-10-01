<?php

namespace App\Http\Controllers\Transfers;

use App\Enums\QuoteMode;
use App\Exceptions\Rates\RateUnavailableException;
use App\Exceptions\Transfers\AmountTooSmallException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Transfers\QuoteRequest;
use App\Services\Rates\QuoteService;
use App\Support\Money;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;

class TransferQuoteController extends Controller
{
    public function __invoke(QuoteRequest $request, QuoteService $quotes): JsonResponse
    {
        $currency = $request->string('currency')->toString();
        $mode = QuoteMode::tryFrom($request->string('mode')->toString()) ?? QuoteMode::Pay;
        $amountCents = Money::parseCents($request->string('amount'));
        $marginBp = (int) Settings::get('currency.margin_bp', 0);

        try {
            $quote = $quotes->quote($mode, $currency, $amountCents, $marginBp);
        } catch (RateUnavailableException|AmountTooSmallException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // What the agent may add on top before it becomes someone's mistake.
        $maxBp = (int) Settings::get('currency.max_round_up_bp', 500);

        return response()->json($quote + [
            'can_round_up' => $mode === QuoteMode::Receive
                && $currency !== 'USD'
                && $request->user()->can('transfers.round_collect'),
            'max_collect_original_cents' => $quote['collect_min_original_cents']
                + intdiv($quote['collect_min_original_cents'] * $maxBp, 10000),
        ]);
    }
}
