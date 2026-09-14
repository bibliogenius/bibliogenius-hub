// Activity charts of the admin dashboard (templates/admin/dashboard_stats.html.twig).
//
// The template only emits the two series as JSON in <script type="application/json">
// blocks; everything about how they are drawn lives here, so the page carries no
// inline script and Chart.js is served from public/static/js like this file.
(function () {
    function series(id) {
        var node = document.getElementById(id);
        return node ? JSON.parse(node.textContent) : [];
    }

    var gridColor = 'rgba(0,0,0,0.06)';
    var defaults = {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: {
            x: { grid: { display: false }, ticks: { font: { size: 11 } } },
            y: { grid: { color: gridColor }, ticks: { font: { size: 11 }, precision: 0 }, beginAtZero: true },
        },
    };

    // Loans per day, last 30 days: fill the days without a row with 0.
    var loanRaw = series('chart-loans-data');
    var loanMap = Object.fromEntries(loanRaw.map(function (r) { return [r.day, parseInt(r.count, 10)]; }));
    var loanLabels = [], loanData = [];
    for (var i = 29; i >= 0; i--) {
        var d = new Date(); d.setDate(d.getDate() - i);
        var key = d.toISOString().slice(0, 10);
        loanLabels.push(i === 0 ? 'Today' : key.slice(5)); // MM-DD
        loanData.push(loanMap[key] !== undefined ? loanMap[key] : 0);
    }

    new Chart(document.getElementById('chart-loans'), {
        type: 'bar',
        data: {
            labels: loanLabels,
            datasets: [{ data: loanData, backgroundColor: '#0d6efd', borderRadius: 3 }],
        },
        options: Object.assign({}, defaults, {
            scales: Object.assign({}, defaults.scales, {
                x: Object.assign({}, defaults.scales.x, { ticks: { font: { size: 10 }, maxTicksLimit: 10 } }),
            }),
        }),
    });

    // New libraries per week, last 8 weeks.
    var growthRaw = series('chart-growth-data');
    new Chart(document.getElementById('chart-growth'), {
        type: 'bar',
        data: {
            labels: growthRaw.map(function (r) { return r.week.slice(5, 10); }), // MM-DD
            datasets: [{ data: growthRaw.map(function (r) { return parseInt(r.count, 10); }), backgroundColor: '#198754', borderRadius: 3 }],
        },
        options: defaults,
    });
})();
