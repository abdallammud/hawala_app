<x-layouts.app :title="__('Email')">
    @include('partials.email-tabs', ['active' => 'log'])

    @error('retry')
        <x-alert type="error">{{ $message }}</x-alert>
    @enderror

    @if ($sentToday >= $dailyWarning)
        <x-alert type="warning" title="{{ __('Close to the daily limit') }}">
            {{ __(':count emails went out in the last 24 hours. A normal Gmail account stops at about 500 a day.', ['count' => $sentToday]) }}
        </x-alert>
    @endif

    <x-card :padding="false">
        <div class="p-5 border-b border-slate-100">
            <form method="GET" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 items-end">
                <x-input name="search" placeholder="{{ __('Search address, name or subject') }}" :value="request('search')" />
                <x-select name="event" :options="$events" placeholder="{{ __('All events') }}" :value="request('event')" />
                <x-select name="status" :options="$statuses" placeholder="{{ __('All statuses') }}" :value="request('status')" />
                <div class="flex gap-2">
                    <x-button type="submit" variant="secondary">{{ __('Filter') }}</x-button>
                    @if (request()->hasAny(['search', 'event', 'status']))
                        <x-button :href="route('setup.email-log.index')" variant="ghost">{{ __('Clear') }}</x-button>
                    @endif
                </div>
            </form>
        </div>

        @if ($logs->isEmpty())
            <x-empty-state icon="inbox-stack" title="{{ __('No emails yet') }}" description="{{ __('Emails appear here as the system sends them.') }}" />
        @else
            <x-table>
                <x-slot:head>
                    <th class="px-4 py-3">{{ __('When') }}</th>
                    <th class="px-4 py-3">{{ __('Event') }}</th>
                    <th class="px-4 py-3">{{ __('To') }}</th>
                    <th class="px-4 py-3">{{ __('Subject') }}</th>
                    <th class="px-4 py-3">{{ __('Status') }}</th>
                    <th class="px-4 py-3 text-right">{{ __('Action') }}</th>
                </x-slot:head>

                @foreach ($logs as $log)
                    <tr>
                        <td class="px-4 py-3 text-sm text-slate-600 whitespace-nowrap" data-label="{{ __('When') }}">{{ $log->created_at->timezone(\App\Support\Settings::get('general.timezone', 'Africa/Mogadishu'))->format('M j, H:i') }}</td>
                        <td class="px-4 py-3 text-sm text-slate-700" data-label="{{ __('Event') }}">{{ $log->event->label() }}</td>
                        <td class="px-4 py-3 text-sm" data-label="{{ __('To') }}">
                            <span class="text-slate-900">{{ $log->to_name }}</span>
                            <span class="block text-xs text-slate-500">{{ $log->to_email }}</span>
                        </td>
                        <td class="px-4 py-3 text-sm text-slate-600 max-w-xs" data-label="{{ __('Subject') }}">
                            {{ \Illuminate\Support\Str::limit($log->subject, 60) }}
                            @if ($log->attach_receipt)<x-icon name="paper-clip" class="inline w-3.5 h-3.5 text-slate-400" />@endif
                            @if ($log->error)<span class="block text-xs text-red-600 whitespace-normal">{{ $log->error }}</span>@endif
                        </td>
                        <td class="px-4 py-3" data-label="{{ __('Status') }}">
                            <x-badge :color="$log->color()">{{ $statuses[$log->status] ?? $log->status }}</x-badge>
                        </td>
                        <td class="px-4 py-3 text-right" data-label="{{ __('Action') }}">
                            @if (in_array($log->status, ['failed', 'skipped'], true))
                                <form method="POST" action="{{ route('setup.email-log.retry', $log) }}">
                                    @csrf
                                    <x-button type="submit" variant="secondary" size="sm">{{ __('Retry') }}</x-button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </x-table>

            <div class="px-5">
                <x-pagination :paginator="$logs" />
            </div>
        @endif
    </x-card>
</x-layouts.app>
