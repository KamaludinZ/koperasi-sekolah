import {
    BarController, BarElement, CategoryScale, Chart, LinearScale, Tooltip,
} from 'chart.js';

Chart.register(BarController, BarElement, CategoryScale, LinearScale, Tooltip);

const rupiah = (n) => (window.rupiah ? window.rupiah(n) : 'Rp' + Math.round(n).toLocaleString('id-ID'));
const short = (n) => n >= 1_000_000 ? (n / 1_000_000).toLocaleString('id-ID', { maximumFractionDigits: 1 }) + ' jt'
    : n >= 1000 ? Math.round(n / 1000) + ' rb' : String(n);

/**
 * Grafik batang penjualan (satu seri). Dipakai lewat Alpine:
 * x-data="salesChart(@js($points))", points = [{label, tooltip, value, count}]
 */
export default (points) => ({
    chart: null,

    init() {
        const muted = '#78716c';   // stone-500 – teks sumbu
        const grid = '#e7e5e4';    // stone-200 – grid tipis

        this.chart = new Chart(this.$refs.canvas, {
            type: 'bar',
            data: {
                labels: points.map((p) => p.label),
                datasets: [{
                    data: points.map((p) => p.value),
                    backgroundColor: '#059669',
                    hoverBackgroundColor: '#047857',
                    borderRadius: { topLeft: 4, topRight: 4 },
                    borderSkipped: 'bottom',
                    maxBarThickness: 28,
                    categoryPercentage: 0.9,
                    barPercentage: 0.9,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: { duration: 250 },
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#1c1917',
                        padding: 10,
                        displayColors: false,
                        callbacks: {
                            title: (items) => points[items[0].dataIndex].tooltip,
                            label: (item) => rupiah(item.parsed.y),
                            afterLabel: (item) => points[item.dataIndex].count + ' transaksi',
                        },
                    },
                },
                scales: {
                    x: {
                        grid: { display: false },
                        border: { color: grid },
                        ticks: { color: muted, font: { size: 11 }, maxRotation: 0, autoSkip: true, autoSkipPadding: 8 },
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: grid },
                        border: { display: false },
                        ticks: { color: muted, font: { size: 11 }, maxTicksLimit: 5, callback: (v) => short(v) },
                    },
                },
            },
        });
    },

    destroy() {
        this.chart?.destroy();
    },
});
