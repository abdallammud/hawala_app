@props(['paginator'])

@if ($paginator->hasPages())
    <div class="flex items-center justify-between px-1 py-3 text-sm">
        <p class="text-slate-500">
            {{ __('Showing') }}
            <span class="font-medium text-slate-700">{{ $paginator->firstItem() }}</span>
            {{ __('to') }}
            <span class="font-medium text-slate-700">{{ $paginator->lastItem() }}</span>
            {{ __('of') }}
            <span class="font-medium text-slate-700">{{ $paginator->total() }}</span>
        </p>

        <div class="flex items-center gap-1">
            @if ($paginator->onFirstPage())
                <span class="px-3 py-1.5 rounded-md text-slate-300">
                    <x-icon name="chevron-right" class="w-4 h-4 rotate-180" />
                </span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" class="px-3 py-1.5 rounded-md text-slate-600 hover:bg-slate-100">
                    <x-icon name="chevron-right" class="w-4 h-4 rotate-180" />
                </a>
            @endif

            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" class="px-3 py-1.5 rounded-md text-slate-600 hover:bg-slate-100">
                    <x-icon name="chevron-right" class="w-4 h-4" />
                </a>
            @else
                <span class="px-3 py-1.5 rounded-md text-slate-300">
                    <x-icon name="chevron-right" class="w-4 h-4" />
                </span>
            @endif
        </div>
    </div>
@endif
