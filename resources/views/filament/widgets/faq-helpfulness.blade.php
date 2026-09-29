{{--
    FAQ helpfulness table for the admin dashboard (TOG-8863). Rendered inside
    Filament's widget chrome by App\Filament\Widgets\FaqHelpfulness: aggregates
    only (entry, counts, rate), ranked worst-first so the answers that need
    rewriting surface at the top.
--}}
<x-filament-widgets::widget class="fi-wi-faq-helpfulness">
    <x-filament::section heading="FAQ helpfulness" description="Was-this-helpful rate per entry, worst first. Flagged rows need a rewrite.">
        @if ($scores === [])
            <p class="text-sm text-gray-500 dark:text-gray-400">No votes yet.</p>
        @else
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-gray-500 dark:text-gray-400">
                        <th scope="col" class="py-1 pr-3 font-medium">Entry</th>
                        <th scope="col" class="py-1 pr-3 font-medium">Helpful</th>
                        <th scope="col" class="py-1 pr-3 font-medium">Rate</th>
                        <th scope="col" class="py-1 font-medium">Needs work</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($scores as $score)
                        <tr class="border-t border-gray-200 dark:border-gray-700">
                            <td class="py-1 pr-3">{{ $score['question'] }}</td>
                            <td class="py-1 pr-3">{{ $score['helpful'] }} / {{ $score['total'] }}</td>
                            <td class="py-1 pr-3">{{ number_format($score['rate'] * 100, 0) }}%</td>
                            <td class="py-1">{{ $score['flagged'] ? 'Yes' : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
