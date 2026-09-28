{{--
    Usage:
    <x-table>
        <x-slot:head>
            <th class="px-4 py-3">Name</th>
        </x-slot:head>
        <tr>
            <td class="px-4 py-3" data-label="Name">...</td>
        </tr>
    </x-table>

    Give every <td> a matching `data-label` — below `md` rows stack into cards.
--}}
@props(['head'])

<div class="overflow-x-auto -mx-5 sm:mx-0">
    <table class="table-cards w-full text-sm sm:border sm:border-slate-200 sm:rounded-xl sm:overflow-hidden">
        <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
            <tr>{{ $head }}</tr>
        </thead>
        <tbody class="divide-y divide-slate-100 sm:bg-white">
            {{ $slot }}
        </tbody>
    </table>
</div>
