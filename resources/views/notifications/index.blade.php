<x-layouts.app :title="__('Notifications')">
    <x-card :padding="false">
        @if ($notifications->where('read_at', null)->isNotEmpty())
            <div class="px-5 py-3 border-b border-slate-100 flex justify-end">
                <form method="POST" action="{{ route('notifications.read') }}">
                    @csrf
                    <x-button type="submit" variant="ghost" size="sm">{{ __('Mark all as read') }}</x-button>
                </form>
            </div>
        @endif

        @if ($notifications->isEmpty())
            <x-empty-state icon="bell" title="{{ __('No notifications yet') }}" />
        @else
            <div class="divide-y divide-slate-100">
                @foreach ($notifications as $notification)
                    <div class="px-5 py-4 flex items-start gap-3 {{ $notification->read_at ? '' : 'bg-brand-50/40' }}">
                        <div class="rounded-full p-2 {{ $notification->read_at ? 'bg-slate-100 text-slate-400' : 'bg-brand-100 text-brand-600' }}">
                            <x-icon name="bell" class="w-4 h-4" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm text-slate-800">{{ $notification->data['message'] ?? __('Notification') }}</p>
                            <p class="text-xs text-slate-400 mt-1">{{ $notification->created_at->diffForHumans() }}</p>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="px-5">
                <x-pagination :paginator="$notifications" />
            </div>
        @endif
    </x-card>
</x-layouts.app>
