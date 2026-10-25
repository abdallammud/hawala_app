<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class NotificationsController extends Controller
{
    public function index(Request $request): View
    {
        $notifications = $request->user()->notifications()->paginate(20);

        return view('notifications.index', ['notifications' => $notifications]);
    }

    public function markRead(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return back();
    }

    /**
     * Lightweight poll for the header bell: unread count plus any unread
     * notifications created since the client's cursor (so it can play a sound).
     */
    public function poll(Request $request): JsonResponse
    {
        $user = $request->user();
        $now = Carbon::now();
        $items = [];

        if ($request->filled('after')) {
            try {
                $after = Carbon::parse($request->query('after'));
            } catch (\Throwable) {
                $after = null;
            }

            if ($after) {
                $items = $user->unreadNotifications()
                    ->where('created_at', '>=', $after)
                    ->latest()
                    ->limit(10)
                    ->get()
                    ->map(fn ($n) => [
                        'id' => $n->id,
                        'message' => $n->data['message'] ?? __('Notification'),
                    ])
                    ->all();
            }
        }

        return response()->json([
            'unread' => $user->unreadNotifications()->count(),
            'now' => $now->toIso8601String(),
            'items' => $items,
        ]);
    }
}
