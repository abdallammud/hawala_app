<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Http\Requests\Setup\StoreBranchRequest;
use App\Models\Branch;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class BranchesController extends Controller
{
    public function index(): View
    {
        $this->authorize('setup.branches');

        return view('setup.branches.index', [
            'branches' => Branch::query()->withCount('users')->orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('setup.branches');

        return view('setup.branches.create');
    }

    public function store(StoreBranchRequest $request): RedirectResponse
    {
        Branch::create($request->validated());

        return redirect()->route('setup.branches.index')->with('status', __('Branch created.'));
    }

    public function edit(Branch $branch): View
    {
        $this->authorize('setup.branches');

        return view('setup.branches.edit', ['branch' => $branch]);
    }

    public function update(StoreBranchRequest $request, Branch $branch): RedirectResponse
    {
        $branch->update($request->validated());

        return redirect()->route('setup.branches.index')->with('status', __('Branch updated.'));
    }

    public function toggle(Branch $branch): RedirectResponse
    {
        $this->authorize('setup.branches');

        if ($branch->is_active && $branch->hasActiveUsers()) {
            return back()->withErrors(['branch' => __('This branch still has active payout agents assigned and cannot be deactivated.')]);
        }

        $branch->update(['is_active' => ! $branch->is_active]);

        return back()->with('status', $branch->is_active ? __('Branch activated.') : __('Branch deactivated.'));
    }
}
