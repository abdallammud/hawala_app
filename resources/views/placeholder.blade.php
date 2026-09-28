<x-layouts.app :title="$title">
    <x-card :padding="false">
        <x-empty-state :icon="$icon ?? 'inbox-stack'" :title="$title" :description="$description ?? null">
            <x-slot:actions>
                <x-badge color="gold">{{ __('Coming in Phase :phase', ['phase' => $phase]) }}</x-badge>
            </x-slot:actions>
        </x-empty-state>
    </x-card>
</x-layouts.app>
