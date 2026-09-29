<?php

namespace App\Http\Controllers\Transfers;

use App\Exceptions\Rates\RateUnavailableException;
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
        $amountCents = Money::parseCents($request->string('amount'));
        $marginBp = (int) Settings::get('currency.margin_bp', 0);

        try {
            $quote = $quotes->quote($currency, $amountCents, $marginBp);
        } catch (RateUnavailableException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($quote);
    }
}
