<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Http\Requests\Setup\StoreFeeTiersRequest;
use App\Models\FeeTier;
use App\Services\FeeCalculator;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class FeeTiersController extends Controller
{
    public function index(): View
    {
        $this->authorize('setup.fees');

        return view('setup.fee-tiers.index', [
            'tiers' => FeeTier::query()->orderBy('from_cents')->get(),
        ]);
    }

    public function update(StoreFeeTiersRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request) {
            FeeTier::query()->delete();

            foreach ($request->input('tiers') as $sort => $row) {
                FeeTier::create([
                    'from_cents' => Money::parseCents((string) $row['from']),
                    'to_cents' => isset($row['to']) && $row['to'] !== '' ? Money::parseCents((string) $row['to']) : null,
                    'rate_bp' => (int) round(((float) $row['rate']) * 100),
                    'flat_cents' => Money::parseCents((string) ($row['flat'] ?? '0')),
                    'cap_cents' => isset($row['cap']) && $row['cap'] !== '' ? Money::parseCents((string) $row['cap']) : null,
                    'sort' => $sort,
                ]);
            }
        });

        return redirect()->route('setup.fee-tiers.index')->with('status', __('Fee tiers saved.'));
    }

    public function preview(Request $request, FeeCalculator $calculator): JsonResponse
    {
        $this->authorize('setup.fees');

        $validated = $request->validate(['amount' => ['required', 'numeric', 'min:0']]);
        $amountCents = Money::parseCents((string) $validated['amount']);

        return response()->json([
            'whole' => Money::format($calculator->calculate($amountCents, 'whole')['fee_cents']),
            'marginal' => Money::format($calculator->calculate($amountCents, 'marginal')['fee_cents']),
        ]);
    }
}
