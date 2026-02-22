// POS screen logic — Deals + Daily Sequence + Reopen Paid/Ready/Partial Orders
// Qty/Disc live line-total preview + autosave, Charges apply fix
// NEW: Edit/Delete saved deals from Show Deals list

try { localStorage.removeItem('ffpos_last_order_id'); } catch(e){}

function $(id) { return document.getElementById(id); }
function on(el, evt, fn) { if (el) el.addEventListener(evt, fn); }
function fmt(n) { return Number(n || 0).toFixed(2); }
function toast(msg, ok = true) {
  const t = $('toast');
  if (!t) { alert(msg); return; }
  t.textContent = msg;
  t.style.background = ok ? '#064e3b' : '#7f1d1d';
  t.style.borderColor = ok ? '#10b98166' : '#ef444466';
  t.hidden = false;
  setTimeout(() => t.hidden = true, 1800);
}
const debounce = (fn, ms=600) => { let t; return (...a)=>{ clearTimeout(t); t=setTimeout(()=>fn(...a), ms); }; };
const computeLine = (unit, qty, disc)=> Math.max(0, (Number(unit)||0)*(Number(qty)||0) - (Number(disc)||0));

const ui = {
  logoutBtn: $('logoutBtn'),
  orderType: $('orderType'),
  custPhone: $('custPhone'),
  branchId: $('branchId'),
  btnNewOrder: $('btnNewOrder'),
  currentOrderId: $('currentOrderId'),
  currentTicketNo: $('currentTicketNo'),
  btnReset: $('btnReset'),
  btnResetSeq: $('btnResetSeq'),
  orderMeta: $('orderMeta'),
  catBar: $('catBar'),
  itemsGrid: $('itemsGrid'),
  cartBody: $('cartBody'),
  servicePct: $('servicePct'),
  taxPct: $('taxPct'),
  deliveryFee: $('deliveryFee'),
  btnApplyCharges: $('btnApplyCharges'),
  chargesMsg: $('chargesMsg'),
  sumSubtotal: $('sumSubtotal'),
  sumDiscount: $('sumDiscount'),
  sumService: $('sumService'),
  sumTax: $('sumTax'),
  sumDelivery: $('sumDelivery'),
  sumGrand: $('sumGrand'),
  sumStatus: $('sumStatus'),

  // Deals UI
  btnShowDeals: $('btnShowDeals'),
  btnBuildDeal: $('btnBuildDeal'),
  dealModal: $('dealModal'),
  closeDealModal: $('closeDealModal'),
  dealPickList: $('dealPickList'),
  dealChosenList: $('dealChosenList'),
  dealName: $('dealName'),
  dealPrice: $('dealPrice'),
  dealQty: $('dealQty'),
  btnAddCustomDeal: $('btnAddCustomDeal'),
  dealMsg: $('dealMsg'),
};

const state = {
  me: null,
  categories: [],
  items: [],
  deals: [],
  currentCategory: 'ALL',
  currentOrder: null,
  showingDeals: false,
  chosen: [],
  editingDealId: null, // NEW: track editing deal
};

// -------- Auth / Boot --------
(async function guard() {
  try {
    const me = await apiFetch('../api/me');
    if (!me || !me.user) throw new Error('unauthorized');
    state.me = me.user;
    if (state.me.branch_id && ui.branchId) ui.branchId.value = state.me.branch_id;
  } catch {
    goPublicRoot();
  }
})();

(async function bootMenu() {
  try {
    const [cats, items] = await Promise.all([
      apiFetch('../api/menu/categories').catch(() => ({ data: [] })),
      apiFetch('../api/menu/items').catch(() => ({ data: [] })),
    ]);
    state.categories = cats?.data || [];
    state.items = items?.data || [];
    await reloadDeals();
    renderCategories();
    renderItems();
  } catch (e) {
    console.error('Failed to load menu/deals:', e);
  }
})();

async function reloadDeals(){
  try {
    const res = await apiFetch('../api/deals?active=1').catch(()=>({data:[]}));
    state.deals = res?.data || [];
  } catch { state.deals = []; }
}

// -------- Category / Items Rendering --------
function renderCategories() {
  if (!ui.catBar) return;
  ui.catBar.innerHTML = '';
  const makeBtn = (label, key) => {
    const b = document.createElement('button');
    b.className = 'cat-btn' + (state.currentCategory === key && !state.showingDeals ? ' active' : '');
    b.textContent = label;
    b.addEventListener('click', () => {
      state.showingDeals = false;
      state.currentCategory = key;
      renderCategories();
      renderItems();
    });
    return b;
  };
  ui.catBar.appendChild(makeBtn('All', 'ALL'));
  state.categories.forEach(c => ui.catBar.appendChild(makeBtn(c.name, 'CAT_' + c.id)));
}

function canManageDeals(){
  const r = String(state.me?.role || '').toUpperCase();
  return r === 'ADMIN' || r === 'MANAGER';
}

function renderItems(forceDeals = false) {
  if (!ui.itemsGrid) return;
  const showDeals = forceDeals || state.showingDeals;
  ui.itemsGrid.innerHTML = '';

  if (showDeals) {
    state.showingDeals = true;
    if (!(state.deals||[]).length) {
      const d = document.createElement('div');
      d.className = 'muted';
      d.textContent = 'No active deals';
      ui.itemsGrid.appendChild(d);
      return;
    }
    state.deals.forEach(d => {
      const card = document.createElement('div');
      card.className = 'item-card';
      card.innerHTML = `
        <div>
          <div class="item-title">Deal: ${d.name}</div>
          <div class="badge">Items: ${d.items_count||0}</div>
        </div>
        <div class="item-price mono">${fmt(d.price)}</div>
      `;
      // Click to add deal to order
      card.addEventListener('click', (e) => {
        // If clicked on action area, ignore add
        if (e.target.closest('.deal-actions')) return;
        addDealToOrderOrHint(d.id);
      });

      // Action buttons (Edit/Delete) for ADMIN/MANAGER
      if (canManageDeals()) {
        const actions = document.createElement('div');
        actions.className = 'deal-actions';
        actions.style.display = 'flex';
        actions.style.gap = '6px';
        actions.style.marginTop = '6px';

        const btnEdit = document.createElement('button');
        btnEdit.className = 'btn small';
        btnEdit.textContent = 'Edit';
        btnEdit.addEventListener('click', async (ev) => {
          ev.stopPropagation();
          await openDealEditor(d.id);
        });

        const btnDelete = document.createElement('button');
        btnDelete.className = 'btn small danger';
        btnDelete.textContent = 'Delete';
        btnDelete.addEventListener('click', async (ev) => {
          ev.stopPropagation();
          await deleteDeal(d.id, d.name);
        });

        const wrap = document.createElement('div');
        wrap.style.gridColumn = '1 / -1';
        wrap.appendChild(actions);
        actions.appendChild(btnEdit);
        actions.appendChild(btnDelete);

        // Place actions under card content
        const container = document.createElement('div');
        container.style.display = 'grid';
        container.style.gridTemplateColumns = '1fr';
        container.appendChild(card.firstElementChild); // left
        container.appendChild(card.lastElementChild); // right price
        container.appendChild(wrap);

        // Recompose card
        card.innerHTML = '';
        card.appendChild(container);
      }

      ui.itemsGrid.appendChild(card);
    });
    return;
  }

  let filtered = state.items.filter(x => Number(x.active));
  if (state.currentCategory !== 'ALL') {
    const catId = Number((state.currentCategory || '').replace('CAT_', ''));
    filtered = filtered.filter(x => Number(x.category_id) === catId);
  }
  filtered.forEach(it => {
    const card = document.createElement('div');
    card.className = 'item-card';
    card.innerHTML = `
      <div>
        <div class="item-title">${it.name}</div>
        <div class="badge">${it.category_name || ''}</div>
      </div>
      <div class="item-price mono">${fmt(it.price)}</div>
    `;
    card.addEventListener('click', () => addItemToOrder(it.id));
    ui.itemsGrid.appendChild(card);
  });
}

// Toggle deals
on(ui.btnShowDeals, 'click', async () => {
  await reloadDeals();
  renderItems(true);
});

// -------- Deal Builder modal (Create or Edit) --------
function openDealModal(){
  if (ui.dealModal) ui.dealModal.classList.add('open');

  // Fill pick list
  if (ui.dealPickList) {
    ui.dealPickList.innerHTML = '';
    state.items.filter(x=>Number(x.active)).forEach(it=>{
      const line = document.createElement('div');
      line.className = 'line';
      line.innerHTML = `
        <div>${it.name} <span class="badge">${fmt(it.price)}</span></div>
        <div>
          <input type="number" step="1" min="0" value="0" style="width:80px" data-pick="${it.id}">
          <button class="btn" data-add="${it.id}">Add</button>
        </div>
      `;
      line.querySelector('[data-add]').addEventListener('click', ()=>{
        const q = Number(line.querySelector('[data-pick]').value||0);
        if (!(q>0)) return;
        addChosen(it.id, it.name, q);
        line.querySelector('[data-pick]').value = '0';
      });
      ui.dealPickList.appendChild(line);
    });
  }

  // Reset state for new or ensure existing is displayed
  renderChosen();
  if (ui.dealMsg) ui.dealMsg.textContent = '';
}

function initNewDealForm(){
  state.chosen = [];
  state.editingDealId = null;
  renderChosen();
  if (ui.dealName) ui.dealName.value = '';
  if (ui.dealPrice) ui.dealPrice.value = '';
  if (ui.dealQty) ui.dealQty.value = '1';
}

function closeDealModal(){ if (ui.dealModal) ui.dealModal.classList.remove('open'); }
on(ui.btnBuildDeal, 'click', () => { initNewDealForm(); openDealModal(); });
on(ui.closeDealModal, 'click', closeDealModal);

function addChosen(menu_item_id, item_name, qty){
  const existing = state.chosen.find(c=>c.menu_item_id===menu_item_id);
  if (existing) existing.qty += qty; else state.chosen.push({ menu_item_id, item_name, qty });
  renderChosen();
}
function removeChosen(menu_item_id){
  state.chosen = state.chosen.filter(c=>c.menu_item_id!==menu_item_id);
  renderChosen();
}
function renderChosen(){
  if (!ui.dealChosenList) return;
  ui.dealChosenList.innerHTML = '';
  if (!state.chosen.length){
    const d = document.createElement('div');
    d.className = 'muted';
    d.textContent = 'No items selected';
    ui.dealChosenList.appendChild(d);
    return;
  }
  state.chosen.forEach(c=>{
    const line = document.createElement('div');
    line.className = 'line';
    line.innerHTML = `
      <div>${c.item_name}</div>
      <div>
        <input type="number" step="1" min="1" value="${c.qty}" style="width:80px" data-chq="${c.menu_item_id}">
        <button class="btn danger" data-del="${c.menu_item_id}">Remove</button>
      </div>
    `;
    line.querySelector('[data-chq]').addEventListener('input', (e)=>{
      const v = Number(e.target.value||0);
      c.qty = v>0 ? v : 1;
    });
    line.querySelector('[data-del]').addEventListener('click', ()=> removeChosen(c.menu_item_id));
    ui.dealChosenList.appendChild(line);
  });
}

// Load deal and open editor (prefill)
async function openDealEditor(dealId){
  try{
    const res = await apiFetch(`../api/deals/${dealId}`);
    const deal = res.deal;
    const items = res.items || [];
    state.editingDealId = deal.id;

    // Prefill
    if (ui.dealName) ui.dealName.value = deal.name || '';
    if (ui.dealPrice) ui.dealPrice.value = Number(deal.price||0).toFixed(2);
    if (ui.dealQty) ui.dealQty.value = '1';

    state.chosen = items.map(it => ({
      menu_item_id: Number(it.menu_item_id),
      item_name: it.item_name,
      qty: Number(it.qty||1)
    }));

    renderChosen();
    openDealModal();
    if (ui.dealMsg) ui.dealMsg.textContent = 'Editing deal #' + deal.id;
  }catch(e){
    toast(e.message || 'Failed to load deal', false);
  }
}

// Save Deal (Create new or Update existing)
on(ui.btnAddCustomDeal, 'click', async ()=>{
  try{
    if (!state.chosen.length) { if(ui.dealMsg) ui.dealMsg.textContent = 'Pick items for deal'; return; }
    const name = (ui.dealName && ui.dealName.value.trim()) || 'Custom Deal';
    const price = Number(ui.dealPrice && ui.dealPrice.value || 0);
    if (!(price>0)) { if(ui.dealMsg) ui.dealMsg.textContent = 'Enter deal price'; return; }

    if (ui.dealMsg) ui.dealMsg.textContent = (state.editingDealId ? 'Updating...' : 'Saving...');

    const payload = {
      name,
      price,
      active: 1,
      items: state.chosen.map(c=>({ menu_item_id: c.menu_item_id, qty: c.qty }))
    };

    if (state.editingDealId) {
      // For update: PATCH header and items
      await apiFetch(`../api/deals/${state.editingDealId}`, {
        method: 'PATCH',
        body: JSON.stringify({ name, price, active: 1 })
      });
      // Easiest approach: delete all components and re-insert (or implement granular updates if preferred)
      // Load old items and delete them, then add new set
      const current = await apiFetch(`../api/deals/${state.editingDealId}`);
      const oldItems = current.items || [];
      for (const it of oldItems) {
        try {
          await apiFetch(`../api/deals/${state.editingDealId}/items/${it.id}`, { method:'DELETE' });
        } catch(_){}
      }
      for (const c of state.chosen) {
        await apiFetch(`../api/deals/${state.editingDealId}/items`, {
          method:'POST',
          body: JSON.stringify({ menu_item_id: c.menu_item_id, qty: c.qty })
        });
      }
      if (ui.dealMsg) ui.dealMsg.textContent = '';
      toast('Deal updated');
    } else {
      const res = await apiFetch('../api/deals', { method: 'POST', body: JSON.stringify(payload) });
      if (!res || typeof res.id === 'undefined') {
        if (ui.dealMsg) ui.dealMsg.textContent = 'Save failed (no id)';
        return;
      }
      if (ui.dealMsg) ui.dealMsg.textContent = '';
      toast('Deal saved');
    }

    await reloadDeals();
    closeDealModal();
    renderItems(true);
    state.editingDealId = null;
  }catch(e){
    if (ui.dealMsg) ui.dealMsg.textContent = e.message || 'Failed';
  }
});

// Delete deal (with confirm)
async function deleteDeal(dealId, name){
  if (!canManageDeals()) { toast('Not allowed', false); return; }
  if (!confirm(`Delete deal "${name}"?`)) return;
  try{
    await apiFetch(`../api/deals/${dealId}`, { method:'DELETE' });
    toast('Deal deleted');
    await reloadDeals();
    renderItems(true);
  }catch(e){
    toast(e.message || 'Failed to delete', false);
  }
}

async function addDealToOrderOrHint(deal_id){
  const id = Number(ui.currentOrderId && ui.currentOrderId.value || 0);
  if (!id) { toast('Create/Open order, then click deal to add', false); return; }
  await addDealToOrder({ deal_id, qty: 1 });
}

// -------- Create New Order --------
on(ui.btnNewOrder, 'click', async () => {
  try {
    if (ui.cartBody) ui.cartBody.innerHTML = '';

    const branch_id = Number(ui.branchId && ui.branchId.value ? ui.branchId.value : (state.me?.branch_id || 1));
    const order_type = (ui.orderType && ui.orderType.value) || 'TAKEAWAY';
    const payload = { branch_id, order_type };
    const ph = ui.custPhone && ui.custPhone.value.trim();
    if (ph) payload.customer_phone = ph;

    const res = await apiFetch('../api/orders', { method: 'POST', body: JSON.stringify(payload) });
    if (!res || typeof res.order_id === 'undefined') {
      toast('Unexpected API response on create order', false);
      return;
    }

    if (ui.currentOrderId) ui.currentOrderId.value = String(res.order_id);
    if (ui.currentTicketNo) ui.currentTicketNo.value = res.order_no || '';
    if (ui.orderMeta) {
      const seq = (res.day_seq != null) ? String(res.day_seq).padStart(4,'0') : '';
      ui.orderMeta.textContent = `Order ID #${res.order_id} • Ticket ${seq} (${res.order_no || ''}) • ${order_type}`;
    }

    await refreshOrder();
    toast('Order created');
  } catch (e) {
    toast(e.message || 'Failed to create order', false);
  }
});

// -------- Reset (clear UI only) --------
on(ui.btnReset, 'click', () => {
  state.currentOrder = null;
  if (ui.currentOrderId) ui.currentOrderId.value = '';
  if (ui.currentTicketNo) ui.currentTicketNo.value = '';
  if (ui.orderMeta) ui.orderMeta.textContent = '';
  if (ui.cartBody) ui.cartBody.innerHTML = '';
  updateSummaryBox();
  try { localStorage.removeItem('ffpos_last_order_id'); } catch(_){}
});

// -------- Reset Sequence (force) --------
on(ui.btnResetSeq, 'click', async () => {
  try {
    const branch_id = Number(ui.branchId && ui.branchId.value ? ui.branchId.value : (state.me?.branch_id || 1));
    if (!branch_id) { toast('Branch ID missing', false); return; }
    if (ui.btnResetSeq) ui.btnResetSeq.disabled = true;

    const resp = await fetch('../api/orders/reset-sequence', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({ branch_id, force: 1 })
    });

    const body = await resp.json().catch(()=>({}));
    if (!resp.ok) {
      console.error('reset-sequence failed', resp.status, body);
      toast(body.message || body.error || 'Reset failed', false);
    } else {
      toast('Sequence reset. Next order will start from 1', true);
      state.currentOrder = null;
      if (ui.currentOrderId) ui.currentOrderId.value = '';
      if (ui.currentTicketNo) ui.currentTicketNo.value = '';
      if (ui.orderMeta) ui.orderMeta.textContent = '';
      if (ui.cartBody) ui.cartBody.innerHTML = '';
      updateSummaryBox();
    }
  } catch (e) {
    console.error('reset sequence error', e);
    toast(e.message || 'Reset failed', false);
  } finally {
    if (ui.btnResetSeq) ui.btnResetSeq.disabled = false;
  }
});

// -------- Reopen / Editable Helper --------
async function ensureEditableOrder(id) {
  let data;
  try { data = await apiFetch(`../api/orders/${id}`); } catch (e) { toast(e.message || 'Failed to load order', false); return false; }
  const st = String(data?.order?.status || '').toUpperCase();
  if (st === 'OPEN' || st === 'KITCHEN') return true;

  if (['PAID','READY','PARTIAL'].includes(st)) {
    if (!confirm(`Order status is ${st}. Reopen for editing? (Payments remain)`)) return false;
    try {
      await apiFetch(`../api/orders/${id}/reopen`, { method: 'POST' });
      toast('Order reopened', true);
      await refreshOrder();
      return true;
    } catch (e) {
      toast(e.message || 'Reopen failed', false);
      return false;
    }
  }

  toast(`Cannot edit order in status: ${st}`, false);
  return false;
}

// -------- Item / Deal / Line Operations --------
async function addItemToOrder(item_id) {
  try {
    const id = Number(ui.currentOrderId && ui.currentOrderId.value || 0);
    if (!id) { toast('Create/Open an order first', false); return; }
    if (!(await ensureEditableOrder(id))) return;
    await apiFetch(`../api/orders/${id}/items`, {
      method: 'POST',
      body: JSON.stringify({ item_id, qty: 1 })
    });
    await refreshOrder();
  } catch (e) { toast(e.message || 'Failed to add item', false); }
}

async function addDealToOrder(payload) {
  try {
    const id = Number(ui.currentOrderId && ui.currentOrderId.value || 0);
    if (!id) { toast('Create/Open an order first', false); return; }
    if (!(await ensureEditableOrder(id))) return;
    await apiFetch(`../api/orders/${id}/deals`, { method:'POST', body: JSON.stringify(payload) });
    await refreshOrder();
    toast('Deal added');
  } catch (e) { toast(e.message || 'Failed to add deal', false); }
}

async function patchOrderItem(orderItemId, qty, line_discount) {
  try {
    const id = Number(ui.currentOrderId && ui.currentOrderId.value || 0);
    if (!id) { toast('No order', false); return; }
    if (!(await ensureEditableOrder(id))) return;
    if (!(qty > 0)) { toast('Qty must be > 0', false); return; }
    if (line_discount < 0) { toast('Discount cannot be negative', false); return; }
    await apiFetch(`../api/orders/${id}/items/${orderItemId}`, {
      method: 'PATCH',
      body: JSON.stringify({ qty: Number(qty), line_discount: Number(line_discount) })
    });
    await refreshOrder();
    toast('Updated');
  } catch (e) { toast(e.message || 'Failed to update', false); }
}

async function removeOrderItem(orderItemId) {
  try {
    const id = Number(ui.currentOrderId && ui.currentOrderId.value || 0);
    if (!id) { toast('No order', false); return; }
    if (!(await ensureEditableOrder(id))) return;
    await apiFetch(`../api/orders/${id}/items/${orderItemId}`, { method: 'DELETE' });
    await refreshOrder();
  } catch (e) { toast(e.message || 'Failed to remove', false); }
}

// -------- Charges --------
on(ui.btnApplyCharges, 'click', async () => {
  try {
    const id = Number(ui.currentOrderId && ui.currentOrderId.value || 0);
    if (!id) { toast('No order', false); return; }
    if (!(await ensureEditableOrder(id))) return;
    if (ui.chargesMsg) ui.chargesMsg.textContent = 'Applying...';
    const service_charge_percent = Number(ui.servicePct && ui.servicePct.value || 0);
    const tax_rate_percent = Number(ui.taxPct && ui.taxPct.value || 0);
    const delivery_fee = Number(ui.deliveryFee && ui.deliveryFee.value || 0).toFixed(2);
    await apiFetch(`../api/orders/${id}/charges`, {
      method: 'POST',
      body: JSON.stringify({ service_charge_percent, tax_rate_percent, delivery_fee })
    });
    if (ui.chargesMsg) ui.chargesMsg.textContent = '';
    await refreshOrder();
    toast('Charges applied');
  } catch (e) {
    if (ui.chargesMsg) ui.chargesMsg.textContent = e.message || 'Failed';
  }
});

// -------- Refresh / Render Cart / Summary --------
async function refreshOrder() {
  try {
    const id = Number(ui.currentOrderId && ui.currentOrderId.value || 0);
    if (!id) return;
    const data = await apiFetch(`../api/orders/${id}`);
    state.currentOrder = data;
    const o = data.order;
    if (ui.currentTicketNo) ui.currentTicketNo.value = o.order_no || '';
    if (ui.orderMeta) {
      const seq = (o.day_seq != null) ? String(o.day_seq).padStart(4,'0') : '';
      ui.orderMeta.textContent = `Order ID #${o.id} • Ticket ${seq} (${o.order_no || ''}) • ${o.order_type}`;
    }
    renderCart(data.items || []);
    updateSummaryBox();
  } catch (e) {
    console.error('refreshOrder failed:', e);
  }
}

function renderCart(items) {
  if (!ui.cartBody) return;
  ui.cartBody.innerHTML = '';
  if (!Array.isArray(items) || !items.length) {
    const tr = document.createElement('tr');
    tr.innerHTML = `<td colspan="7" class="muted">No items</td>`;
    ui.cartBody.appendChild(tr);
    return;
  }
  items.forEach((it, idx) => {
    const isDeal = Number(it.is_deal||0) === 1;
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td>${idx + 1}</td>
      <td>${isDeal ? ('<strong>'+ (String(it.item_name_snapshot||'').split(' — ')[0]) +'</strong>') : it.item_name_snapshot}</td>
      <td class="num">
        <input type="number" step="1" min="1" value="${Number(it.qty)}" data-qty="${it.id}">
      </td>
      <td class="num" data-unit="${it.id}">${fmt(it.unit_price)}</td>
      <td class="num">
        <input type="number" step="0.01" min="0" value="${Number(it.line_discount||0) ? fmt(it.line_discount) : ''}" data-disc="${it.id}">
      </td>
      <td class="num"><span data-lt="${it.id}">${fmt(it.line_total)}</span></td>
      <td class="row-actions">
        <button class="icon-btn" data-upd="${it.id}" title="Update">
          <svg width="16" height="16" viewBox="0 0 20 20" fill="currentColor"><path d="M8.293 13.293a1 1 0 0 0 1.414 0l6-6a1 1 0 1 0-1.414-1.414L9 10.172 5.707 6.879a1 1 0 0 0-1.414 1.414l4 4z"/></svg>
        </button>
        <button class="icon-btn danger" data-del="${it.id}" title="Remove">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M9 3a1 1 0 0 0-1 1v1H4v2h1v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7h1V5h-4V4a1 1 0 0 0-1-1H9zm2 4h2v10h-2V7zm-4 0h2v10H7V7zm8 0h2v10h-2V7z"/></svg>
        </button>
      </td>
    `;

    const qtyInput  = tr.querySelector(`[data-qty="${it.id}"]`);
    const discInput = tr.querySelector(`[data-disc="${it.id}"]`);
    const ltSpan    = tr.querySelector(`[data-lt="${it.id}"]`);
    const updBtn    = tr.querySelector(`[data-upd="${it.id}"]`);
    const unit      = Number(it.unit_price);

    const updatePreview = () => {
      const qty  = Number(qtyInput.value || 0);
      const disc = Number((discInput.value || 0));
      ltSpan.textContent = fmt(computeLine(unit, qty, disc));
    };

    const saveNow = async () => {
      const qty  = Number(qtyInput.value || 0);
      const disc = Number((discInput.value || 0));
      await patchOrderItem(it.id, qty, disc);
    };

    const debouncedSave = debounce(saveNow, 600);

    qtyInput.addEventListener('input', ()=>{ updatePreview(); debouncedSave(); });
    discInput.addEventListener('input', ()=>{ updatePreview(); debouncedSave(); });

    const onCommit = (e)=>{ if (e.type==='blur' || e.key==='Enter') saveNow(); };
    qtyInput.addEventListener('blur', onCommit);
    qtyInput.addEventListener('keydown', onCommit);
    discInput.addEventListener('blur', onCommit);
    discInput.addEventListener('keydown', onCommit);

    updBtn.addEventListener('click', saveNow);

    tr.querySelector('[data-del]').addEventListener('click', () => removeOrderItem(it.id));

    ui.cartBody.appendChild(tr);

    if (isDeal && it.deal_snapshot) {
      let parsed = null;
      try { parsed = typeof it.deal_snapshot === 'string' ? JSON.parse(it.deal_snapshot) : it.deal_snapshot; } catch (_){}
      if (parsed && Array.isArray(parsed.items) && parsed.items.length) {
        const tr2 = document.createElement('tr');
        const itemsList = parsed.items.map(c => `• ${c.item_name} x${c.qty}`).join('<br>');
        tr2.innerHTML = `<td></td><td colspan="6" class="muted small">${itemsList}</td>`;
        ui.cartBody.appendChild(tr2);
      }
    }
  });
}

function updateSummaryBox() {
  const o = state.currentOrder?.order;
  if (!o) {
    if (ui.sumSubtotal) ui.sumSubtotal.textContent = '0.00';
    if (ui.sumDiscount) ui.sumDiscount.textContent = '0.00';
    if (ui.sumService) ui.sumService.textContent = '0.00';
    if (ui.sumTax) ui.sumTax.textContent = '0.00';
    if (ui.sumDelivery) ui.sumDelivery.textContent = '0.00';
    if (ui.sumGrand) ui.sumGrand.textContent = '0.00';
    if (ui.sumStatus) ui.sumStatus.textContent = '—';
    return;
  }
  ui.sumSubtotal && (ui.sumSubtotal.textContent = fmt(o.subtotal));
  ui.sumDiscount && (ui.sumDiscount.textContent = fmt(o.discount_total));
  ui.sumService && (ui.sumService.textContent = fmt(o.service_charge));
  ui.sumTax && (ui.sumTax.textContent = fmt(o.tax_total));
  ui.sumDelivery && (ui.sumDelivery.textContent = fmt(o.delivery_fee));
  ui.sumGrand && (ui.sumGrand.textContent = fmt(o.grand_total));
  ui.sumStatus && (ui.sumStatus.textContent = o.status);
}

// Boot from query
(function bootFromQuery(){
  const id = Number(new URLSearchParams(location.search).get('orderId') || 0);
  if (id && ui.currentOrderId) {
    ui.currentOrderId.value = String(id);
    refreshOrder();
  }
})();

// ESC closes deal modal
document.addEventListener('keydown', (e)=>{
  if(e.key==='Escape') {
    if (ui.dealModal && ui.dealModal.classList.contains('open'))
      ui.dealModal.classList.remove('open');
  }
});