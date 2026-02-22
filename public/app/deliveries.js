// Deliveries UI

const ui = {
  logoutBtn: document.getElementById('logoutBtn'),

  orderId: document.getElementById('orderId'),
  btnLoadOrder: document.getElementById('btnLoadOrder'),
  branchId: document.getElementById('branchId'),
  custPhone: document.getElementById('custPhone'),
  btnCreateDeliveryOrder: document.getElementById('btnCreateDeliveryOrder'),
  topMsg: document.getElementById('topMsg'),

  delForm: document.getElementById('delForm'),
  fOrderId: document.getElementById('fOrderId'),
  customerName: document.getElementById('customerName'),
  customerPhone: document.getElementById('customerPhone'),
  address1: document.getElementById('address1'),
  address2: document.getElementById('address2'),
  area: document.getElementById('area'),
  city: document.getElementById('city'),
  deliveryFee: document.getElementById('deliveryFee'),
  riderName: document.getElementById('riderName'),
  riderPhone: document.getElementById('riderPhone'),
  status: document.getElementById('status'),
  notes: document.getElementById('notes'),

  btnSave: document.getElementById('btnSave'),
  btnAssign: document.getElementById('btnAssign'),
  btnOut: document.getElementById('btnOut'),
  btnDelivered: document.getElementById('btnDelivered'),
  btnCancelled: document.getElementById('btnCancelled'),
  formMsg: document.getElementById('formMsg'),

  orderNo: document.getElementById('orderNo'),
  orderStatus: document.getElementById('orderStatus'),
  orderGrand: document.getElementById('orderGrand'),
  itemsBody: document.getElementById('itemsBody'),

  recentMsg: document.getElementById('recentMsg'),
  recentBody: document.getElementById('recentBody'),

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
    if (me.user.branch_id) ui.branchId.value = me.user.branch_id;
  } catch {
    location.href = '../';
  }
}

ui.logoutBtn.addEventListener('click', async () => {
  try { await apiFetch('../api/logout', { method: 'POST' }); } catch {}
  localStorage.removeItem('ffpos_user');
  location.href = '../';
});

// Create new delivery order
ui.btnCreateDeliveryOrder.addEventListener('click', async () => {
  try {
    ui.topMsg.textContent = 'Creating...';
    const payload = {
      order_type: 'DELIVERY',
      branch_id: Number(ui.branchId.value || 0),
      customer_phone: ui.custPhone.value.trim() || undefined
    };
    if (payload.branch_id <= 0) throw new Error('Branch ID required');
    const res = await apiFetch('../api/orders', { method:'POST', body: JSON.stringify(payload) });
    ui.topMsg.textContent = '';
    toast('Order #' + res.order_id + ' created');
    ui.orderId.value = res.order_id;
    await loadOrderById(res.order_id);
  } catch (e) {
    ui.topMsg.textContent = e.message || 'Failed';
  }
});

// Load order
ui.btnLoadOrder.addEventListener('click', async () => {
  const id = Number(ui.orderId.value || 0);
  if (!id) return toast('Enter Order ID', false);
  await loadOrderById(id);
});

async function loadOrderById(orderId) {
  try {
    const data = await apiFetch('../api/orders/' + orderId);
    const o = data.order;
    ui.fOrderId.value = o.id;
    ui.orderNo.textContent = o.order_no || o.id;
    ui.orderStatus.textContent = o.status;
    ui.orderGrand.textContent = fmt(o.grand_total);

    // Items
    ui.itemsBody.innerHTML = '';
    (data.items || []).forEach((it, idx) => {
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td>${idx + 1}</td>
        <td>${it.item_name_snapshot}</td>
        <td class="num">${Number(it.qty).toFixed(2)}</td>
        <td class="num">${fmt(it.unit_price)}</td>
        <td class="num">${fmt(it.line_total)}</td>
      `;
      ui.itemsBody.appendChild(tr);
    });

    // Try to load delivery details (optional endpoint)
    await loadDeliveryDetails(orderId);
  } catch (e) {
    toast(e.message || 'Failed to load order', false);
  }
}

async function loadDeliveryDetails(orderId) {
  // This GET is optional; skip silently if 404
  try {
    const d = await apiFetch('../api/deliveries/' + orderId);
    fillDeliveryForm(d.delivery || d || {});
  } catch (_) {
    // Fallback: empty form (user can save to create)
    fillDeliveryForm({});
  }
}

function fillDeliveryForm(d) {
  ui.customerName.value = d.customer_name || '';
  ui.customerPhone.value = d.customer_phone || '';
  ui.address1.value = d.address_line1 || d.address1 || '';
  ui.address2.value = d.address_line2 || d.address2 || '';
  ui.area.value = d.area || '';
  ui.city.value = d.city || '';
  ui.deliveryFee.value = d.delivery_fee != null ? Number(d.delivery_fee) : '';
  ui.riderName.value = d.rider_name || '';
  ui.riderPhone.value = d.rider_phone || '';
  ui.status.value = d.status || '';
  ui.notes.value = d.notes || '';
}

// Save/Upsert
ui.delForm.addEventListener('submit', async (e) => {
  e.preventDefault();
  await saveDelivery();
});

async function quickStatus(newStatus) {
  if (!ui.fOrderId.value) return toast('Load or create an order first', false);
  ui.status.value = newStatus;
  await saveDelivery(true);
}

ui.btnAssign.addEventListener('click', () => quickStatus('ASSIGNED'));
ui.btnOut.addEventListener('click', () => quickStatus('OUT_FOR_DELIVERY'));
ui.btnDelivered.addEventListener('click', () => quickStatus('DELIVERED'));
ui.btnCancelled.addEventListener('click', () => quickStatus('CANCELLED'));

async function saveDelivery(silent = false) {
  try {
    if (!ui.fOrderId.value) throw new Error('No order selected');
    ui.formMsg.textContent = 'Saving...';
    const payload = {
      customer_name: ui.customerName.value.trim() || null,
      customer_phone: ui.customerPhone.value.trim() || null,
      address_line1: ui.address1.value.trim() || null,
      address_line2: ui.address2.value.trim() || null,
      area: ui.area.value.trim() || null,
      city: ui.city.value.trim() || null,
      notes: ui.notes.value.trim() || null,
      rider_name: ui.riderName.value.trim() || null,
      rider_phone: ui.riderPhone.value.trim() || null,
      status: ui.status.value || null,
      delivery_fee: ui.deliveryFee.value !== '' ? Number(ui.deliveryFee.value).toFixed(2) : null
    };
    await apiFetch('../api/deliveries/' + ui.fOrderId.value, {
      method: 'POST',
      body: JSON.stringify(payload)
    });
    ui.formMsg.textContent = '';
    if (!silent) toast('Saved');
    // Refresh recent list to reflect changes
    loadRecent();
  } catch (e) {
    ui.formMsg.textContent = e.message || 'Failed';
  }
}

// Recent deliveries (optional endpoint)
async function loadRecent() {
  ui.recentMsg.textContent = 'Loading...';
  ui.recentBody.innerHTML = '';
  try {
    const res = await apiFetch('../api/deliveries');
    const rows = res.data || [];
    rows.forEach(r => {
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td>${r.id}</td>
        <td class="mono">${r.order_no || ''}</td>
        <td><span class="pill">${r.order_status || r.status || ''}</span></td>
        <td>${r.customer_name || ''}</td>
        <td>${r.area || ''}</td>
        <td class="mono">${r.delivery_fee != null ? fmt(r.delivery_fee) : ''}</td>
        <td>${(r.created_at || '').replace('T',' ').replace('Z','')}</td>
        <td><button data-id="${r.id}" class="btn">Open</button></td>
      `;
      tr.querySelector('button').addEventListener('click', async () => {
        ui.orderId.value = r.id;
        await loadOrderById(r.id);
        window.scrollTo({ top: 0, behavior: 'smooth' });
      });
      ui.recentBody.appendChild(tr);
    });
    ui.recentMsg.textContent = rows.length ? `Showing ${rows.length} recent` : 'No recent deliveries';
  } catch (e) {
    ui.recentMsg.textContent = 'Endpoint not available (optional).';
  }
}

// Boot
(async function boot() {
  await guard();
  // If navigated with last order id
  const last = localStorage.getItem('ffpos_last_order_id');
  if (last) {
    ui.orderId.value = last;
    await loadOrderById(Number(last));
  }
  loadRecent(); // best-effort
})();