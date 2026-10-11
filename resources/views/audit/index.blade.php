<x-layouts.app :title="__('Audit Log')">
    <x-card :padding="false">
        <div class="p-5 border-b border-slate-100">
            <form method="GET" class="flex flex-wrap gap-3 items-end">
                <div class="w-full sm:w-40"><x-input name="from" label="{{ __('From') }}" type="date" :value="request('from')" /></div>
                <div class="w-full sm:w-40"><x-input name="to" label="{{ __('To') }}" type="date" :value="request('to')" /></div>
                <div class="w-full sm:w-48"><x-select name="user_id" label="{{ __('User') }}" :options="$users" placeholder="{{ __('Anyone') }}" :value="request('user_id')" /></div>
                <div class="w-full sm:w-44"><x-select name="subject_type" label="{{ __('Record type') }}" :options="$subjectTypes" placeholder="{{ __('Any type') }}" :value="request('subject_type')" /></div>
                <div class="w-full sm:w-36"><x-select name="event" label="{{ __('Event') }}" :options="$events" placeholder="{{ __('Any event') }}" :value="request('event')" /></div>
                <x-button type="submit" variant="secondary">{{ __('Filter') }}</x-button>
                @if (request()->hasAny(['from', 'to', 'user_id', 'subject_type', 'event']))
                    <x-button :href="route('audit.index')" variant="ghost">{{ __('Clear') }}</x-button>
                @endif
            </form>
        </div>

        @if ($activities->isEmpty())
            <x-empty-state icon="shield-check" title="{{ __('Nothing logged yet') }}" description="{{ __('Changes to users, branches, account types and company accounts appear here.') }}" />
        @else
            <div class="divide-y divide-slate-100">
                @foreach ($activities as $activity)
                    <div class="px-5 py-4" x-data="{ open: false }">
                        <div class="flex items-start justify-between gap-4">
                            <div class="min-w-0">
                                <p class="text-sm text-slate-900">
                                    <span class="font-medium">{{ $activity->causer?->name ?? __('System') }}</span>
                                    {{ $activity->event ?? $activity->description }}
                                    <span class="text-slate-500">{{ class_basename($activity->subject_type) }}</span>
                                    @if ($activity->subject_id)
                                        <span class="text-slate-400">#{{ $activity->subject_id }}</span>
                                    @endif
                                </p>
                                <p class="text-xs text-slate-500 mt-0.5">{{ $activity->created_at->format('M j, Y H:i') }}</p>
                                @if (! empty($activity->properties['reason']))
                                    <p class="text-xs text-slate-600 mt-1">{{ __('Reason') }}: {{ $activity->properties['reason'] }}</p>
                                @endif
                            </div>

                            @if (! empty($activity->properties['attributes']) || ! empty($activity->properties['old']))
                                <button type="button" x-on:click="open = !open"
                                    class="text-sm font-medium text-brand-600 hover:text-brand-700 shrink-0">
                                    <span x-show="!open">{{ __('Show changes') }}</span>
                                    <span x-show="open" x-cloak>{{ __('Hide') }}</span>
                                </button>
                            @endif
                        </div>

                        <div x-show="open" x-cloak class="mt-3 rounded-lg border border-slate-200 overflow-hidden">
                            <table class="w-full text-xs">
                                <thead class="bg-slate-50 text-slate-500">
                                    <tr>
                                        <th class="px-3 py-2 text-left font-medium">{{ __('Field') }}</th>
                                        <th class="px-3 py-2 text-left font-medium">{{ __('Before') }}</th>
                                        <th class="px-3 py-2 text-left font-medium">{{ __('After') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    @foreach (($activity->properties['attributes'] ?? []) as $field => $after)
                                        <tr>
                                            <td class="px-3 py-2 font-medium text-slate-700">{{ $field }}</td>
                                            <td class="px-3 py-2 text-slate-500">
                                                {{ \App\Support\AuditValue::format($activity, $field, \Illuminate\Support\Arr::get($activity->properties, "old.{$field}"), 'old') }}
                                            </td>
                                            <td class="px-3 py-2 text-slate-900">{{ \App\Support\AuditValue::format($activity, $field, $after, 'attributes') }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="px-5">
                <x-pagination :paginator="$activities" />
            </div>
        @endif
    </x-card>
</x-layouts.app>
