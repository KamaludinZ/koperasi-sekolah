import './bootstrap';
import salesChart from './sales-chart';

document.addEventListener('alpine:init', () => {
    window.Alpine.data('salesChart', salesChart);
});
