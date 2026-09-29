<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Http\Requests\Setup\UpdateCurrencyRequest;
use App\Models\Currency;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\View\View;

class CurrenciesController extends Controller
{
    public function index(): View
    {
        $this->authorize('setup.currencies');

        $currencies = Currency::query()
            ->with(['rates' => fn ($q) => $q->latest('fetched_at')->limit(1)])
            ->orderBy('sort')
            ->orderBy('code')
            ->get();

        return view('setup.currencies.index', ['currencies' => $currencies]);
    }

    public function update(UpdateCurrencyRequest $request, Currency $currency): RedirectResponse
    {
        $currency->update([
            'is_active' => $request->boolean('is_active'),
            'manual_rate' => $request->filled('manual_rate') ? $request->input('manual_rate') : null,
        ]);

        return back()->with('status', __(':code updated.', ['code' => $currency->code]));
    }

    public function fetch(): RedirectResponse
    {
        $this->authorize('setup.currencies');

        $exitCode = Artisan::call('hawala:fetch-rates');

        return back()->with('status', $exitCode === 0
            ? __('Rates fetched.')
            : __('Rate fetch failed for all providers — check manual overrides.'));
    }
}
