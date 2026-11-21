/* Graf využitia úložiska (Chart.js) – jedna séria, bez legendy, tooltip s krížom */
(function () {
  const s = document.createElement('script');
  s.src = window.CLOUD_CHART_JS || 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js'; // knižnica zo servera (assets/vendor)
  s.onload = draw;
  document.head.appendChild(s);

  function draw() {
    const el = document.getElementById('usageChart');
    if (!el || !window.USAGE) return;
    const css = getComputedStyle(document.documentElement);
    const ink = css.getPropertyValue('--bs-secondary-color').trim() || '#6c757d';
    const grid = css.getPropertyValue('--c-border').trim() || '#e3e7ee';
    const surface = css.getPropertyValue('--c-surface').trim() || '#fff';
    const blue = document.documentElement.getAttribute('data-bs-theme') === 'dark' ? '#6ea8fe' : '#1a73e8';
    const fmtDay = (d) => new Date(d + 'T00:00').toLocaleDateString('sk-SK', { day: 'numeric', month: 'short' });
    const ctx = el.getContext('2d');
    const grad = ctx.createLinearGradient(0, 0, 0, el.parentElement.clientHeight);
    grad.addColorStop(0, blue + '33'); grad.addColorStop(1, blue + '00');
    new Chart(el, {
      type: 'line',
      data: {
        labels: USAGE.map(p => p.d),
        datasets: [{ data: USAGE.map(p => p.b), borderColor: blue, backgroundColor: grad, fill: true, borderWidth: 2, tension: .25,
          pointRadius: USAGE.length < 20 ? 3 : 0, pointHoverRadius: 5, pointBackgroundColor: blue, pointBorderColor: surface, pointBorderWidth: 2, pointHitRadius: 12 }],
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { display: false },
          tooltip: { displayColors: false, callbacks: { title: (i) => new Date(i[0].label + 'T00:00').toLocaleDateString('sk-SK', { day: 'numeric', month: 'long', year: 'numeric' }), label: (i) => 'Využité: ' + Cloud.formatBytes(i.raw) } },
        },
        scales: {
          x: { grid: { display: false }, border: { color: grid }, ticks: { color: ink, maxTicksLimit: 7, callback: function (v) { return fmtDay(this.getLabelForValue(v)); } } },
          y: { beginAtZero: true, grid: { color: grid }, border: { display: false }, ticks: { color: ink, maxTicksLimit: 5, callback: (v) => Cloud.formatBytes(v) } },
        },
      },
      plugins: [{ id: 'crosshair', afterDraw(c) { // zvislá čiara pri prechode myšou
        const a = c.tooltip && c.tooltip.getActiveElements();
        if (!a || !a.length) return;
        const x = a[0].element.x, { top, bottom } = c.chartArea, g = c.ctx;
        g.save(); g.strokeStyle = ink; g.globalAlpha = .35; g.lineWidth = 1; g.beginPath(); g.moveTo(x, top); g.lineTo(x, bottom); g.stroke(); g.restore();
      } }],
    });
  }
})();
