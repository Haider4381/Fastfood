<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Sales Report — FastFood POS</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="style.css" rel="stylesheet">
  <link href="nav.css" rel="stylesheet">
  <style>
    .report-hero { display:flex; gap:12px; align-items:center; flex-wrap:wrap; }
    .report-hero .stat { padding:10px 14px; border-radius:6px; background:#f8fafc; box-shadow: 0 0 0 1px rgba(0,0,0,0.02) inset; }
    .report-hero .stat .val { font-weight:700; font-size:1.1rem; display:block; }
    .muted-small { color:#666; font-size:0.9rem; }
    .grid-3 { display:grid; grid-template-columns: repeat(3,1fr); gap:12px; }
    @media (max-width:900px){ .grid-3{ grid-template-columns: 1fr; } }
    .actions label { margin-right:8px; }
    .pill { display:inline-block; padding:6px 10px; border-radius:6px; background:#f3f4f6; margin-right:6px; }
  </style>
</head>
<body>
  <header class="topbar">
    <div class="brand-inline"><div class="logo small">🍔</div><strong>Sales Report</strong></div>
    <div class="topbar-actions"><a class="btn" href="orders.html">← Orders</a><a class="btn" href="dashboard.html">Dashboard</a></div>
  </header>

  <main class="container">
    <section class="card">
      <h2 class="card-title">Filter</h2>
      <div class="actions" id="filters">
        <label>From <input type="date" id="from"></label>
        <label>To <input type="date" id="to"></label>
        <label>Group
          <select id="group">
            <option value="day">Daily</option>
            <option value="month">Monthly</option>
          </select>
        </label>
        <label>Branch
          <select id="branch">
            <option value="0">All</option>
          </select>
        </label>
        <button id="btnLoad" class="btn">Load Report</button>
        <button id="btnExport" class="btn">Export CSV</button>
        <span id="msg" class="muted small"></span>
      </div>
    </section>

    <section class="card">
      <h3 class="card-title">Summary</h3>
      <div class="report-hero" id="summary">
        <div class="stat"><span class="muted-small">Orders</span><span class="val" id="sumOrders">0</span></div>
        <div class="stat"><span class="muted-small">Total Sales</span><span class="val" id="sumSales">0.00</span></div>
        <div class="stat"><span class="muted-small">Paid</span><span class="val" id="sumPaid">0.00</span></div>
        <div class="stat"><span class="muted-small">Partial</span><span class="val" id="sumPartial">0.00</span></div>
        <div class="stat"><span class="muted-small">Avg Order</span><span class="val" id="sumAvg">0.00</span></div>
      </div>
    </section>

    <section class="card">
      <h3 class="card-title">Sales by Period</h3>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th>Period</th><th class="num">Orders</th><th class="num">Sales</th><th class="num">Paid</th><th class="num">Partial</th></tr></thead>
          <tbody id="body"></tbody>
          <tfoot id="tfoot"></tfoot>
        </table>
      </div>
    </section>

    <section class="card grid-3">
      <div>
        <h3 class="card-title">Payment Breakdown</h3>
        <div id="payments"></div>
      </div>
      <div>
        <h3 class="card-title">Top Items</h3>
        <div class="table-wrap">
          <table class="table">
            <thead><tr><th>Item</th><th class="num">Qty</th><th class="num">Sales</th></tr></thead>
            <tbody id="topItems"></tbody>
          </table>
        </div>
      </div>
      <div>
        <h3 class="card-title">Actions</h3>
        <div class="actions">
          <button id="btnPrint" class="btn">Print</button>
        </div>
      </div>
    </section>
  </main>

  <script src="app.js"></script>
  <script src="nav.js"></script>
  <script>
    const ui = {
      from: document.getElementById('from'),
      to: document.getElementById('to'),
      group: document.getElementById('group'),
      branch: document.getElementById('branch'),
      btnLoad: document.getElementById('btnLoad'),
      btnExport: document.getElementById('btnExport'),
      btnPrint: document.getElementById('btnPrint'),
      msg: document.getElementById('msg'),
      body: document.getElementById('body'),
      tfoot: document.getElementById('tfoot'),
      sumOrders: document.getElementById('sumOrders'),
      sumSales: document.getElementById('sumSales'),
      sumPaid: document.getElementById('sumPaid'),
      sumPartial: document.getElementById('sumPartial'),
      sumAvg: document.getElementById('sumAvg'),
      payments: document.getElementById('payments'),
      topItems: document.getElementById('topItems')
    };

    (function setToday(){
      const d = new Date(), yyyy = d.getFullYear(), mm = String(d.getMonth()+1).padStart(2,'0'), dd = String(d.getDate()).padStart(2,'0');
      ui.from.value = `${yyyy}-${mm}-${dd}`;
      ui.to.value = `${yyyy}-${mm}-${dd}`;
    })();

    function fmt(n){ return Number(n||0).toFixed(2); }

    async function loadBranches(){
      try {
        const res = await apiFetch('../api/branches');
        if (res && Array.isArray(res.data)) {
          res.data.forEach(b=>{
            const opt = document.createElement('option');
            opt.value = b.id;
            opt.textContent = b.name;
            ui.branch.appendChild(opt);
          });
        }
      } catch (e) {
        // ignore if not available
      }
    }

    async function loadReport(){
      ui.msg.textContent = 'Loading...';
      ui.body.innerHTML = '';
      ui.payments.innerHTML = '';
      ui.topItems.innerHTML = '';
      try {
        const from = ui.from.value;
        const to = ui.to.value;
        const group = ui.group.value;
        const branch = ui.branch.value || 0;
        const url = `../api/reports/sales?from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}&group=${encodeURIComponent(group)}&branch_id=${encodeURIComponent(branch)}`;
        const res = await apiFetch(url);

        const rows = res.rows || [];
        const totals = res.totals || {};
        const payments = res.payments || [];
        const top_items = res.top_items || [];

        ui.body.innerHTML = '';
        rows.forEach(r=>{
          const tr = document.createElement('tr');
          tr.innerHTML = `
            <td>${r.period}</td>
            <td class="num">${r.orders_count}</td>
            <td class="num">${fmt(r.sales_total)}</td>
            <td class="num">${fmt(r.paid_total)}</td>
            <td class="num">${fmt(r.partial_total)}</td>
          `;
          ui.body.appendChild(tr);
        });

        ui.tfoot.innerHTML = `
          <tr>
            <th>Total</th>
            <th class="num">${totals.orders_count || 0}</th>
            <th class="num">${fmt(totals.grand_total || totals.sales_total || 0)}</th>
            <th class="num">${fmt(totals.paid_total || 0)}</th>
            <th class="num">${fmt(totals.partial_total || 0)}</th>
          </tr>
        `;

        ui.sumOrders.textContent = totals.orders_count || 0;
        ui.sumSales.textContent = fmt(totals.grand_total || 0);
        ui.sumPaid.textContent = fmt(totals.paid_total || 0);
        ui.sumPartial.textContent = fmt(totals.partial_total || 0);
        ui.sumAvg.textContent = fmt((totals.grand_total && totals.orders_count) ? (totals.grand_total / totals.orders_count) : 0);

        ui.payments.innerHTML = payments.length ? payments.map(p=>`<div class="pill">${p.method}: <strong>${fmt(p.amount)}</strong></div>`).join('') : '<div class="muted-small">No payments</div>';

        ui.topItems.innerHTML = '';
        top_items.forEach(it=>{
          const tr = document.createElement('tr');
          tr.innerHTML = `<td>${it.item_name}</td><td class="num">${Number(it.qty).toFixed(2)}</td><td class="num">${fmt(it.sales)}</td>`;
          ui.topItems.appendChild(tr);
        });

        ui.msg.textContent = `Loaded ${rows.length} rows`;
      } catch (e) {
        ui.msg.textContent = e.message || 'Failed';
      }
    }

    function exportCSV(){
      const rows = [];
      rows.push(['Period','Orders','Sales','Paid','Partial']);
      document.querySelectorAll('#body tr').forEach(tr=>{
        const cols = Array.from(tr.querySelectorAll('td')).map(td=>td.textContent.trim());
        rows.push(cols);
      });
      if (ui.tfoot) {
        const tf = Array.from(document.querySelectorAll('#tfoot tr th, #tfoot tr td')).map(td => td.textContent.trim());
        if (tf.length) {
          rows.push([]);
          rows.push(['Totals'].concat(tf.slice(1)));
        }
      }
      rows.push([]);
      rows.push(['Payment Breakdown']);
      document.querySelectorAll('#payments .pill').forEach(p=>{
        rows.push([p.textContent.trim()]);
      });
      rows.push([]);
      rows.push(['Top Items']);
      rows.push(['Item','Qty','Sales']);
      document.querySelectorAll('#topItems tr').forEach(tr=>{
        const cols = Array.from(tr.querySelectorAll('td')).map(td=>td.textContent.trim());
        rows.push(cols);
      });

      const csv = rows.map(r => r.map(c=>`"${String(c||'').replace(/"/g,'""')}"`).join(',')).join('\n');
      const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
      const link = document.createElement('a');
      const url = URL.createObjectURL(blob);
      link.href = url;
      link.download = `sales_report_${ui.from.value || 'from'}_to_${ui.to.value || 'to'}.csv`;
      document.body.appendChild(link);
      link.click();
      link.remove();
      URL.revokeObjectURL(url);
    }

    ui.btnLoad.addEventListener('click', loadReport);
    ui.btnExport.addEventListener('click', exportCSV);
    ui.btnPrint.addEventListener('click', () => window.print());

    (async function boot(){
      await loadBranches();
      loadReport();
    })();
  </script>
</body>
</html>