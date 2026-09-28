@props(['icon' => 'inbox-stack', 'title', 'description' => null])

<div class="flex flex-col items-center justify-center text-center py-14 px-6">
    <div class="rounded-full bg-brand-50 text-brand-500 p-3.5 mb-4">
        <x-icon :name="$icon" class="w-6 h-6" />
    </div>
    <h3 class="text-sm font-semibold text-slate-900">{{ $title }}</h3>
    @if ($description)
        <p class="mt-1.5 text-sm text-slate-500 max-w-sm">{{ $description }}</p>
    @endif
    @isset($actions)
        <div class="mt-5 flex items-center gap-3">{{ $actions }}</div>
    @endisset
</div>
