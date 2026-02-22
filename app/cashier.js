// Cashier Session UI

const ui = {
  logoutBtn: document.getElementById('logoutBtn'),

  noSession: document.getElementById('noSession'),
  hasSession: document.getElementById('hasSession'),

  openForm: document.getElementById('openForm'),
  branchId: document.getElementById('branchId'),
  openingFloat: document.getElementById('openingFloat'),
  btnOpen: document.getElementById('btnOpen'),
  openMsg: document.getElementById('openMsg'),

  sOpened: document.getElementById('sOpened'),
  sOpening: document.getElementById('sOpening'),
  sCash: document.getElementById('sCash'),
  sEstClose: document.getElementById('sEstClose'),

  closeForm: document.getElementById('closeForm'),
  payouts: document.getElementById('payouts'),
  btnClose: document.getElementById('btnClose'),
  closeMsg: document.getElementById('closeMsg'),

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

async function guard() {
  try {
    const me = await apiFetch('../api/me');
    if (!me || !me.user) throw new Error('unauthorized');
    if (!['ADMIN','MANAGER','CASHIER'].includes(me.user.role)) {
      location.href = 'dashboard.html';
    }
    if (me.user.branch_id) {
      ui.branchId.value = me.user.branch_id;
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

async function loadSession() {
  ui.noSession.hidden = true;
  ui.hasSession.hidden = true;
  try {
    const res = await apiFetch('../api/cashier/session');
    if (!res || !res.session) {
      ui.noSession.hidden = false;
      return;
    }
    const s = res.session;
    const m = res.metrics || {};
    ui.sOpened.textContent = (s.opened_at || '').replace('T',' ').replace('Z','');
    ui.sOpening.textContent = fmt(m.opening_float ?? s.opening_float);
    ui.sCash.textContent = fmt(m.cash_sales ?? 0);
    ui.sEstClose.textContent = fmt(m.estimated_closing ?? 0);
    ui.hasSession.hidden = false;
  } catch (e) {
    toast(e.message || 'Failed to load session', false);
  }
}

ui.openForm.addEventListener('submit', async (e) => {
  e.preventDefault();
  ui.openMsg.textContent = 'Opening...';
  try {
    const payload = {
      branch_id: Number(ui.branchId.value || 0),
      opening_float: Number(ui.openingFloat.value || 0).toFixed(2)
    };
    await apiFetch('../api/cashier/open', { method: 'POST', body: JSON.stringify(payload) });
    ui.openMsg.textContent = '';
    toast('Session opened');
    await loadSession();
  } catch (e2) {
    ui.openMsg.textContent = e2.message || 'Failed';
  }
});

ui.closeForm.addEventListener('submit', async (e) => {
  e.preventDefault();
  ui.closeMsg.textContent = 'Closing...';
  try {
    const payload = { payouts: Number(ui.payouts.value || 0).toFixed(2) };
    const res = await apiFetch('../api/cashier/close', { method: 'POST', body: JSON.stringify(payload) });
    ui.closeMsg.textContent = '';
    toast('Session closed. Closing balance: ' + (res && res.closing_balance ? res.closing_balance : '0.00'));
    // Reload to show "Open Session" form again
    await loadSession();
  } catch (e2) {
    ui.closeMsg.textContent = e2.message || 'Failed';
  }
});

// Boot
(async function boot() {
  await guard();
  await loadSession();
  // Auto-refresh cash sales every 20 seconds while session open
  setInterval(async () => {
    if (!ui.hasSession.hidden) {
      await loadSession();
    }
  }, 20000);
})();