<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Http\Requests\Setup\StoreCityRequest;
use App\Models\City;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CitiesController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('setup.cities');

        $cities = City::query()
            ->when($request->filled('country'), fn ($q) => $q->where('country', $request->string('country')))
            ->when($request->filled('q'), fn ($q) => $q->where('name', 'like', '%'.$request->string('q')->trim().'%'))
            ->orderBy('country')->orderBy('name')
            ->paginate(25)->withQueryString();

        return view('setup.cities.index', [
            'cities' => $cities,
            'countries' => config('hawala.countries'),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('setup.cities');

        return view('setup.cities.create', [
            'countries' => config('hawala.countries'),
            'country' => $request->query('country'),
        ]);
    }

    public function store(StoreCityRequest $request): RedirectResponse
    {
        City::create($request->validated() + ['is_active' => true]);

        return redirect()->route('setup.cities.index', ['country' => $request->input('country')])
            ->with('status', __('City added.'));
    }

    public function edit(City $city): View
    {
        $this->authorize('setup.cities');

        return view('setup.cities.edit', ['city' => $city, 'countries' => config('hawala.countries')]);
    }

    public function update(StoreCityRequest $request, City $city): RedirectResponse
    {
        $city->update($request->validated());

        return redirect()->route('setup.cities.index', ['country' => $city->country])->with('status', __('City updated.'));
    }

    public function toggle(City $city): RedirectResponse
    {
        $this->authorize('setup.cities');

        $city->update(['is_active' => ! $city->is_active]);

        return back()->with('status', $city->is_active ? __('City activated.') : __('City deactivated.'));
    }
}
