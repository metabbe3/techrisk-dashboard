// Chart.js powers the Analytics page charts. Vendored locally (was a CDN
// <script>): third-party dependency, version drift, render-blocking. Loaded as a
// page-scoped Vite entry only on /admin/analytics. The page renders charts on
// demand via the `analytics-chart-updated` event (after Livewire data loads), so
// the deferred module load is always ready in time. chart.js/auto registers all
// chart types, matching the previous CDN behaviour.
import Chart from 'chart.js/auto';
window.Chart = Chart;
