<?php

namespace App\Http\Controllers;

use App\Enums\UserType;
use App\Services\DashboardService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(private DashboardService $dashboard) {}

    public function index(Request $request): View
    {
        $user = $request->user();

        // Staff who can see everyone's money get the admin view; agents get
        // the view for their own role.
        if ($user->can('balances.view_all') || $user->can('transfers.view_all')) {
            return view('dashboards.admin', ['data' => $this->dashboard->forAdmin()]);
        }

        return match ($user->type) {
            UserType::PayoutAgent => view('dashboards.payout-agent', ['data' => $this->dashboard->forPayoutAgent($user)]),
            UserType::DualAgent => view('dashboards.dual-agent', [
                'sending' => $this->dashboard->forSendingAgent($user),
                'payout' => $this->dashboard->forPayoutAgent($user),
            ]),
            UserType::SendingAgent => view('dashboards.sending-agent', ['data' => $this->dashboard->forSendingAgent($user)]),
            UserType::Staff => view('dashboards.sending-agent', ['data' => $this->dashboard->forSendingAgent($user)]),
        };
    }
}
