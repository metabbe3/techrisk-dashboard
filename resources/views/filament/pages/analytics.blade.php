<x-filament-panels::page>
    <form wire:submit.prevent="generateChart">
        {{ $this->form }}
        <div class="mt-6 flex gap-3">
            <x-filament::button type="submit" icon="heroicon-o-chart-bar">
                Generate Chart
            </x-filament::button>
        </div>
    </form>

    @if($chartVisible && $chartData)
        <x-filament::section class="mt-6">
            <x-slot name="heading">
                {{ App\Services\Analytics\AnalyticsQueryService::metricLabel($data['metric'] ?? 'count') }}
                by
                {{ App\Services\Analytics\AnalyticsQueryService::dimensionLabel($data['dimension'] ?? 'monthly') }}
            </x-slot>

            <div wire:ignore class="analytics-chart-container">
                <canvas id="analyticsChart"></canvas>
            </div>
        </x-filament::section>

        @if(!empty($chartData['raw_data'][0]))
            <x-filament::section class="mt-4">
                <x-slot name="heading">Data Table</x-slot>
                <div class="overflow-x-auto">
                    <table class="fi-ta-table w-full text-left text-sm">
                        <thead>
                            <tr class="fi-ta-header-row bg-gray-50 dark:bg-gray-800">
                                <th class="fi-ta-header-cell px-4 py-3 font-medium text-gray-600 dark:text-gray-300">
                                    {{ App\Services\Analytics\AnalyticsQueryService::dimensionLabel($data['dimension'] ?? 'monthly') }}
                                </th>
                                <th class="fi-ta-header-cell px-4 py-3 font-medium text-gray-600 dark:text-gray-300">
                                    {{ App\Services\Analytics\AnalyticsQueryService::metricLabel($data['metric'] ?? 'count') }}
                                </th>
                                @if(count($chartData['raw_data']) > 1)
                                    <th class="fi-ta-header-cell px-4 py-3 font-medium text-gray-600 dark:text-gray-300">
                                        Comparison
                                    </th>
                                @endif
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @foreach($chartData['raw_data'][0] as $i => $row)
                                <tr class="fi-ta-row">
                                    <td class="fi-ta-cell px-4 py-3 text-gray-700 dark:text-gray-200">{{ $row['label'] }}</td>
                                    <td class="fi-ta-cell px-4 py-3 font-mono text-gray-700 dark:text-gray-200">{{ number_format($row['value'], 2) }}</td>
                                    @if(isset($chartData['raw_data'][1][$i]))
                                        <td class="fi-ta-cell px-4 py-3 font-mono text-gray-700 dark:text-gray-200">{{ number_format($chartData['raw_data'][1][$i]['value'], 2) }}</td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        @endif
    @endif
</x-filament-panels::page>

@once
<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    window.addEventListener('analytics-chart-updated', (e) => {
        renderAnalyticsChart(e.detail.chartData, e.detail.chartType);
    });
});

function renderAnalyticsChart(chartData, chartType) {
    if (!chartData || !chartData.datasets) return;

    const canvas = document.getElementById('analyticsChart');
    if (!canvas) {
        setTimeout(() => renderAnalyticsChart(chartData, chartType), 100);
        return;
    }

    chartType = chartType || 'bar';
    const isPie = chartType === 'pie';
    const isLine = chartType === 'line';
    const isHorizontal = chartType === 'horizontal_bar';

    const isDark = document.documentElement.classList.contains('dark');
    const gridColor = isDark ? 'rgba(255,255,255,0.06)' : 'rgba(0,0,0,0.06)';
    const textColor = isDark ? '#94a3b8' : '#64748b';

    const chartJsType = isPie ? 'doughnut' : (isLine ? 'line' : 'bar');

    if (window._analyticsChart) {
        window._analyticsChart.destroy();
        window._analyticsChart = null;
    }

    const options = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                display: chartData.datasets.length > 1 || isPie,
                position: isPie ? 'right' : 'top',
                labels: { color: textColor, usePointStyle: true, padding: 16, font: { size: 12 } },
            },
            tooltip: {
                backgroundColor: isDark ? '#1e293b' : '#fff',
                titleColor: isDark ? '#f1f5f9' : '#0f172a',
                bodyColor: isDark ? '#cbd5e1' : '#475569',
                borderColor: isDark ? '#334155' : '#e2e8f0',
                borderWidth: 1,
                padding: 12,
                cornerRadius: 8,
            },
        },
        layout: { padding: 10 },
    };

    if (!isPie) {
        options.indexAxis = isHorizontal ? 'y' : 'x';
        options.scales = {
            x: {
                beginAtZero: true,
                grid: { display: isHorizontal, color: gridColor },
                ticks: { color: textColor, font: { size: 11 } },
            },
            y: {
                beginAtZero: true,
                grid: { display: !isHorizontal, color: gridColor },
                ticks: { color: textColor, font: { size: 11 } },
            },
        };
    }

    window._analyticsChart = new Chart(canvas, {
        type: chartJsType,
        data: { labels: chartData.labels, datasets: chartData.datasets },
        options: options,
    });
}
</script>
@endonce
