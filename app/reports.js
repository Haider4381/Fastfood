// Reports UI

const ui = {
  logoutBtn: document.getElementById('logoutBtn'),

  from: document.getElementById('from'),
  to: document.getElementById('to'),
  branchId: document.getElementById('branchId'),
  btnToday: document.getElementById('btnToday'),
  btn7: document.getElementById('btn7'),
  btnMonth: document.getElementById('btnMonth'),
  btnRun: document.getElementById('btnRun'),
  filterMsg: document.getElementById('filterMsg'),

  kOrders: document.getElementById('kOrders'),
  kGrand: document.getElementById('kGrand'),
  kPaid: document.getElementById('kPaid'),
  kOutstanding: document.getElementById('kOutstanding'),
  payBadges: document.getElementById('payBadges'),
  rangeNote: document.getElementById('rangeNote'),

  bodyQty: document.getElementById('bodyQty'),
  bodyAmt: document.getElementById('bodyAmt'),
  bodyCat: document.getElementById('bodyCat'),

  expQty: document.getElementById('expQty'),
  expAmt: document.getElementById('expAmt'),
  expCat: document.getElementById('expCat'),

  // Profit & Loss KPIs
  kSalesPL: document.getElementById('kSalesPL'),
  kExpensesPL: document.getElementById('kExpensesPL'),
  kProfitPL: document.getElementById('kProfitPL'),

  toast: document.getElementById('toast'),
};

function toast(msg, ok = true) {
  ui.toast.textContent = msg;
  ui.toast.style.background = ok ? '#064e3b' : '#7f1d1d';
  ui.toast.style.borderColor = ok ? '#10b98166' : '#ef444466';
  ui.toast.hidden = false;
  setTimeout(() => ui.toast.hidden = true, 2000);
}
function fmt(n){ return Number(n || 0).toFixed(2); }
function todayStr(d = new Date()){ return d.toISOString().slice(0,10); }

async function guard() {
  try {
    const me = await apiFetch('../api/me');
    if (!me || !me.user) throw new Error('unauthorized');
    if (me.user.branch_id && Number(ui.branchId.value || 0) === 0) {
      ui.branchId.value = me.user.branch_id; // default to user branch if present
    }
  } catch {
    location.href = '../';
  }
}

ui.logoutBtn.addEventListener('click', async () => {
  try { await apiFetch('../api/logout', { method: 'POST' }); } catch {}
  localStorage.removeItem('ffpos_user');
  location.href = '../';
});

// Quick ranges (now also run the report automatically)
ui.btnToday.addEventListener('click', () => {
  const t = todayStr();
  ui.from.value = t; ui.to.value = t;
  runReports();
});
ui.btn7.addEventListener('click', () => {
  const now = new Date();
  const to = todayStr(now);
  const fromDate = new Date(now.getTime() - 6*24*3600*1000);
  ui.from.value = todayStr(fromDate);
  ui.to.value = to;
  runReports();
});
ui.btnMonth.addEventListener('click', () => {
  const now = new Date();
  const y = now.getFullYear(), m = now.getMonth();
  const from = new Date(y, m, 1);
  const to = todayStr(now);
  ui.from.value = todayStr(from);
  ui.to.value = to;
  runReports();
});

// CSV export
function toCSV(rows, headers){
  const esc = v => {
    const s = (v == null ? '' : String(v));
    return /[",\n]/.test(s) ? '"' + s.replace(/"/g,'""') + '"' : s;
  };
  const lines = [];
  if (headers) lines.push(headers.map(esc).join(','));
  rows.forEach(r => lines.push(r.map(esc).join(',')));
  return lines.join('\n');
}
function downloadCSV(name, csv){
  const blob = new Blob([csv], {type: 'text/csv;charset=utf-8;'});
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url; a.download = name;
  document.body.appendChild(a); a.click(); a.remove();
  URL.revokeObjectURL(url);
}

ui.expQty.addEventListener('click', () => {
  const rows = [...ui.bodyQty.querySelectorAll('tr')].map(tr =>
    [...tr.children].map(td => td.textContent.trim())
  );
  const csv = toCSV(rows, ['#','Item','Category','Qty','Amount']);
  downloadCSV('top_items_by_qty.csv', csv);
});
ui.expAmt.addEventListener('click', () => {
  const rows = [...ui.bodyAmt.querySelectorAll('tr')].map(tr =>
    [...tr.children].map(td => td.textContent.trim())
  );
  const csv = toCSV(rows, ['#','Item','Category','Qty','Amount']);
  downloadCSV('top_items_by_amount.csv', csv);
});
ui.expCat.addEventListener('click', () => {
  const rows = [...ui.bodyCat.querySelectorAll('tr')].map(tr =>
    [...tr.children].map(td => td.textContent.trim())
  );
  const csv = toCSV(rows, ['#','Category','Qty','Amount']);
  downloadCSV('category_sales.csv', csv);
});

// Load report
ui.btnRun.addEventListener('click', runReports);

async function runReports(){
  const from = ui.from.value || todayStr();
  const to   = ui.to.value   || from;
  const branch_id = Number(ui.branchId.value || 0);
  const q = `from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}${branch_id>0?`&branch_id=${branch_id}`:''}`;
  ui.filterMsg.textContent = 'Loading...';

  try {
    const [sum, items, cats, pl] = await Promise.all([
      apiFetch(`../api/reports/sales-summary?${q}`),
      apiFetch(`../api/reports/items?${q}`),
      apiFetch(`../api/reports/categories?${q}`),
      apiFetch(`../api/reports/profit-loss?${q}`),
    ]);

    // Summary KPIs
    const t = sum.totals || {};
    ui.kOrders.textContent = String(t.orders_count ?? '0');
    ui.kGrand.textContent = fmt(t.grand_total);
    ui.kPaid.textContent = fmt(t.paid_total);
    ui.kOutstanding.textContent = fmt(t.outstanding);
    ui.rangeNote.textContent = `From ${sum.range.from} to ${sum.range.to}` + (sum.range.branch_id ? ` • Branch ${sum.range.branch_id}` : '');

    // Payments badges
    ui.payBadges.innerHTML = '';
    (sum.payments_by_method || []).forEach(r => {
      const b = document.createElement('span');
      b.className = 'pill mono';
      b.textContent = `${r.method}: ${fmt(r.total)}`;
      ui.payBadges.appendChild(b);
    });
    if ((sum.payments_by_method || []).length === 0) {
      const b = document.createElement('span');
      b.className = 'subtle';
      b.textContent = 'No payments in range';
      ui.payBadges.appendChild(b);
    }

    // Items tables
    renderItems(ui.bodyQty, items.top_by_qty || []);
    renderItems(ui.bodyAmt, items.top_by_amount || []);

    // Categories
    renderCategories(ui.bodyCat, cats.categories || []);

    // Profit & Loss
    ui.kSalesPL.textContent = fmt(pl.sales || 0);
    ui.kExpensesPL.textContent = fmt(pl.expenses || 0);
    const pr = (pl && typeof pl.profit !== 'undefined') ? Number(pl.profit) : 0;
    ui.kProfitPL.textContent = fmt(pr);
    ui.kProfitPL.style.color = pr >= 0 ? '#10b981' : '#ef4444';

    ui.filterMsg.textContent = '';
  } catch (e) {
    console.error('Reports load failed:', e);
    ui.filterMsg.textContent = e.message || 'Failed';
  }
}

function renderItems(tbody, rows){
  tbody.innerHTML = '';
  rows.forEach((r, idx) => {
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td>${idx+1}</td>
      <td>${r.item_name || ''}</td>
      <td>${r.category_name || ''}</td>
      <td class="num">${fmt(r.qty)}</td>
      <td class="num">${fmt(r.amount)}</td>
    `;
    tbody.appendChild(tr);
  });
  if (rows.length === 0) {
    const tr = document.createElement('tr');
    tr.innerHTML = `<td colspan="5" class="muted">No data in selected range</td>`;
    tbody.appendChild(tr);
  }
}

function renderCategories(tbody, rows){
  tbody.innerHTML = '';
  rows.forEach((r, idx) => {
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td>${idx+1}</td>
      <td>${r.category_name || 'Uncategorized'}</td>
      <td class="num">${fmt(r.qty)}</td>
      <td class="num">${fmt(r.amount)}</td>
    `;
    tbody.appendChild(tr);
  });
  if (rows.length === 0) {
    const tr = document.createElement('tr');
    tr.innerHTML = `<td colspan="4" class="muted">No data in selected range</td>`;
    tbody.appendChild(tr);
  }
}

// Boot
(async function boot() {
  await guard();
  // Default to Last 7 days (more likely to have data than "today")
  const now = new Date();
  const to = todayStr(now);
  const fromDate = new Date(now.getTime() - 6*24*3600*1000);
  ui.from.value = todayStr(fromDate);
  ui.to.value = to;
  await runReports();
})();