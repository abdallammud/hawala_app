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
        $mode = QuoteMode::tryFrom($request->string('mode')->toString()) ?? QuoteMode::Receive;
        $marginBp = (int) Settings::get('currency.margin_bp', 0);

        try {
            $quote = $quotes->quote(
                $mode,
                $request->string('currency')->toString(),
                Money::parseCents($request->string('amount')),
                $marginBp,
                $request->filled('payout_currency') ? $request->string('payout_currency')->toString() : 'USD',
            );
        } catch (RateUnavailableException|AmountTooSmallException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($quote);
    }
}
