// sales_report.js
// Frontend for Sales Report page (works with ReportsController::sales endpoint)

(function () {
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
    topItems: document.getElementById('topItems'),
    toast: document.getElementById('toast'),
  };

  function toast(msg, ok = true) {
    if (!ui.toast) { alert(msg); return; }
    ui.toast.textContent = msg;
    ui.toast.style.background = ok ? '#064e3b' : '#7f1d1d';
    ui.toast.hidden = false;
    setTimeout(() => ui.toast.hidden = true, 1600);
  }
  function fmt(n){ return Number(n||0).toFixed(2); }

  (function setToday(){
    const d = new Date(), yyyy = d.getFullYear(), mm = String(d.getMonth()+1).padStart(2,'0'), dd = String(d.getDate()).padStart(2,'0');
    ui.from.value = `${yyyy}-${mm}-${dd}`;
    ui.to.value = `${yyyy}-${mm}-${dd}`;
  })();

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
      // ignore if API not available
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

      ui.payments.innerHTML = payments.length ? payments.map(p=>`<div class="pill">${p.method}: <strong>${fmt(p.amount || p.total || 0)}</strong></div>`).join('') : '<div class="muted-small">No payments</div>';

      ui.topItems.innerHTML = '';
      top_items.forEach(it=>{
        const tr = document.createElement('tr');
        tr.innerHTML = `<td>${it.item_name}</td><td class="num">${Number(it.qty).toFixed(2)}</td><td class="num">${fmt(it.sales || it.amount)}</td>`;
        ui.topItems.appendChild(tr);
      });

      ui.msg.textContent = `Loaded ${rows.length} rows`;
    } catch (e) {
      ui.msg.textContent = e.message || 'Failed';
      console.error('loadReport error:', e);
    }
  }

  function exportCSV(){
    const rows = [];
    rows.push(['Period','Orders','Sales','Paid','Partial']);
    document.querySelectorAll('#body tr').forEach(tr=>{
      const cols = Array.from(tr.querySelectorAll('td')).map(td => td.textContent.trim());
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
      const cols = Array.from(tr.querySelectorAll('td')).map(td => td.textContent.trim());
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

  // Boot
  (async function boot(){
    await loadBranches();
    await loadReport();
  })();
})();