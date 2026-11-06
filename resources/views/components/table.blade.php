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

{{-- Cards on a phone get a little air from the card edge; from `md` the table runs edge to edge. --}}
<div class="overflow-x-auto px-3 pt-3 md:p-0">
    <table class="table-cards w-full text-sm sm:border sm:border-slate-200 sm:rounded-xl sm:overflow-hidden">
        <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
            <tr>{{ $head }}</tr>
        </thead>
        <tbody class="divide-y divide-slate-100 sm:bg-white">
            {{ $slot }}
        </tbody>
    </table>
</div>
