// Expenses UI

const ui = {
  logoutBtn: document.getElementById('logoutBtn'),

  // Expense categories
  expCatName: document.getElementById('expCatName'),
  expCatActive: document.getElementById('expCatActive'),
  btnAddExpCat: document.getElementById('btnAddExpCat'),
  expCatMsg: document.getElementById('expCatMsg'),
  expCatBody: document.getElementById('expCatBody'),

  // Expense form
  expForm: document.getElementById('expForm'),
  branchId: document.getElementById('branchId'),
  categoryId: document.getElementById('categoryId'),
  amount: document.getElementById('amount'),
  vendor: document.getElementById('vendor'),
  notes: document.getElementById('notes'),
  attachment: document.getElementById('attachment'),
  btnSave: document.getElementById('btnSave'),
  expMsg: document.getElementById('expMsg'),

  btnReload: document.getElementById('btnReload'),
  expBody: document.getElementById('expBody'),

  toast: document.getElementById('toast'),
};

// Global variable to store expense being edited
let currentExpense = null;

function toast(msg, ok = true) {
  if (!ui.toast) { alert(msg); return; }
  ui.toast.textContent = msg;
  ui.toast.style.background = ok ? '#064e3b' : '#7f1d1d';
  ui.toast.style.borderColor = ok ? '#10b98166' : '#ef444466';
  ui.toast.hidden = false;
  setTimeout(() => ui.toast.hidden = true, 2000);
}

// Direct POST helper (diagnostic): shows exact server error
async function postJSON(url, payload) {
  console.debug('POST', url, 'payload:', payload);
  const res = await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  });
  const raw = await res.text();
  let data = null;
  try { data = JSON.parse(raw); } catch (_) {}

  if (!res.ok) {
    // Try to surface server-side details
    const xerr = res.headers.get('X-Error');
    let msg = `HTTP ${res.status}`;
    if (data?.message) msg += ` - ${data.message}`;
    if (data?.error) msg += ` | ${data.error}`;
    if (data?.error_detail) msg += ` | ${data.error_detail}`;
    if (xerr) msg += ` | ${xerr}`;
    console.error('POST failed:', { status: res.status, headers: Object.fromEntries(res.headers), body: raw });
    throw new Error(msg);
  }
  return data ?? {};
}

async function guard() {
  try {
    const me = await apiFetch('../api/me');
    if (!me || !me.user) throw new Error('unauthorized');
    if (!['ADMIN','MANAGER'].includes(me.user.role)) {
      toast('Access denied', false);
      setTimeout(() => location.href = 'dashboard.html', 800);
      return;
    }
    // Agar user ke pass branch_id hai to default set kar do
    if (me.user.branch_id) ui.branchId.value = me.user.branch_id;
  } catch {
    location.href = '../';
  }
}

async function loadExpenseCategories() {
  try {
    const res = await apiFetch('../api/expense-categories');
    const cats = (res && res.data) ? res.data : [];
    // fill dropdown
    if (ui.categoryId) {
      ui.categoryId.innerHTML = '';
      cats.forEach(c => {
        const opt = document.createElement('option');
        opt.value = c.id;
        opt.textContent = c.name + (Number(c.active) ? '' : ' (inactive)');
        ui.categoryId.appendChild(opt);
      });
    }
    // fill list
    if (ui.expCatBody) {
      ui.expCatBody.innerHTML = '';
      cats.forEach(c=>{
        const tr = document.createElement('tr');
        tr.innerHTML = `<td>${c.id}</td><td>${c.name}</td><td>${Number(c.active)?'Active':'Inactive'}</td>`;
        ui.expCatBody.appendChild(tr);
      });
    }
  } catch (e) {
    toast(e.message || 'Failed to load expense categories', false);
    if (ui.categoryId && !ui.categoryId.children.length) {
      ui.categoryId.innerHTML = '';
    }
    if (ui.expCatBody) ui.expCatBody.innerHTML = '';
  }
}

async function loadExpenses() {
  try {
    const res = await apiFetch('../api/expenses');
    const rows = (res && res.data) ? res.data : [];
    ui.expBody.innerHTML = '';
    rows.forEach(r => {
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td>${r.id}</td>
        <td>${r.branch_name || ''}</td>
        <td>${r.category_name || ''}</td>
        <td class="num">${Number(r.amount).toFixed(2)}</td>
        <td>${r.vendor || ''}</td>
        <td>${r.notes || ''}</td>
        <td>${r.created_by_name || ''}</td>
        <td>${(r.created_at || '').replace('T',' ').replace('Z','')}</td>
        <td class="row-actions">
          <button class="btn" data-act="edit">Edit</button>
        </td>
      `;
      ui.expBody.appendChild(tr);
      tr.querySelector('button[data-act="edit"]').addEventListener('click', () => {
        editExpense(r);
      });
    });
  } catch (e) {
    toast(e.message || 'Failed to load expenses', false);
    ui.expBody.innerHTML = '';
  }
}

// Function to load the expense data into the form for editing
function editExpense(expense) {
  currentExpense = expense;
  // Fill the form fields (assuming expense contains branch_id, category_id, amount, vendor, notes, attachment_url)
  if (expense.branch_id) ui.branchId.value = expense.branch_id;
  if (expense.category_id) ui.categoryId.value = expense.category_id;
  ui.amount.value = expense.amount || '';
  ui.vendor.value = expense.vendor || '';
  ui.notes.value = expense.notes || '';
  ui.attachment.value = expense.attachment_url || '';
  // Change button text to "Update Expense"
  ui.btnSave.textContent = "Update Expense";
  // Optionally, scroll to the form section
  ui.expForm.scrollIntoView({ behavior: 'smooth' });
}

// Save expense with detailed error surfacing
if (ui.expForm) {
  ui.expForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    ui.expMsg.textContent = 'Saving...';
    try {
      // Build payload (amount ko number bhej rahe hain)
      const payload = {
        branch_id: Number(ui.branchId.value || 0),
        category_id: Number(ui.categoryId.value || 0),
        amount: Number(ui.amount.value || 0), // number (server DECIMAL handle kar lega)
        vendor: (ui.vendor.value || '').trim() || null,
        notes: (ui.notes.value || '').trim() || null,
        attachment_url: (ui.attachment.value || '').trim() || null,
      };

      // Client-side required fields
      if (payload.branch_id <= 0 || payload.category_id <= 0 || !(payload.amount > 0)) {
        ui.expMsg.textContent = 'Required fields missing';
        return;
      }

      if (currentExpense && currentExpense.id) {
        // Update mode: send PATCH request to update the expense
        const res = await fetch(`../api/expenses/${currentExpense.id}`, {
          method: 'PATCH',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload),
        });
        const raw = await res.text();
        let data = null;
        try { data = JSON.parse(raw); } catch (_) {}
        if (!res.ok) {
          throw new Error(data?.message || 'Update failed');
        }
        toast('Expense updated');
        // Reset currentExpense after successful update
        currentExpense = null;
        ui.btnSave.textContent = "Save Expense";
      } else {
        // Create mode: new expense creation
        await postJSON('../api/expenses', payload);
        toast('Expense saved');
      }
      ui.expMsg.textContent = '';
      // Reset form fields if needed
      ui.amount.value = '';
      ui.vendor.value = '';
      ui.notes.value = '';
      ui.attachment.value = '';
      await loadExpenses();
    } catch (e2) {
      console.error('Save expense error:', e2);
      ui.expMsg.textContent = (e2 && e2.message) ? e2.message : 'Failed';
    }
  });
}

// Add expense category (diagnostic POST)
if (ui.btnAddExpCat) {
  ui.btnAddExpCat.addEventListener('click', async () => {
    const name = (ui.expCatName.value || '').trim();
    const active = Number(ui.expCatActive.value || 1);
    if (!name) { ui.expCatMsg.textContent = 'Name required'; return; }
    ui.expCatMsg.textContent = 'Saving...';
    try {
      await postJSON('../api/expense-categories', { name, active });
      ui.expCatMsg.textContent = '';
      ui.expCatName.value = '';
      ui.expCatActive.value = '1';
      toast('Category added');
      await loadExpenseCategories();
    } catch (e) {
      console.error('Add category error:', e);
      ui.expCatMsg.textContent = e.message || 'Failed';
    }
  });
}

// Boot
(async function boot() {
  await guard();
  await loadExpenseCategories();
  await loadExpenses();
})();