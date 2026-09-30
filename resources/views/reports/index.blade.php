<x-layouts.app :title="__('Reports')">
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        @foreach ($reports as $report)
            <a href="{{ route('reports.show', $report->key()) }}"
               class="block bg-white rounded-xl border border-slate-200 shadow-sm p-5 hover:border-brand-300 hover:shadow transition">
                <div class="flex items-start gap-3">
                    <div class="rounded-lg bg-brand-50 text-brand-600 p-2.5 shrink-0">
                        <x-icon name="chart-bar" class="w-5 h-5" />
                    </div>
                    <div class="min-w-0">
                        <h3 class="text-sm font-semibold text-slate-900">{{ $report->title() }}</h3>
                        <p class="text-xs text-slate-500 mt-1">{{ $report->description() }}</p>
                    </div>
                </div>
            </a>
        @endforeach
    </div>
</x-layouts.app>
