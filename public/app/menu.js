// Guard: ensure session valid, else go to /public/
(async function guard() {
  try {
    const me = await apiFetch('../api/me');
    if (!me || !me.user) throw new Error('Unauthorized');
    // ok
  } catch (_) {
    location.href = '../';
  }
})();

const els = {
  catForm: document.getElementById('catForm'),
  catName: document.getElementById('catName'),
  catSort: document.getElementById('catSort'),
  catMsg:  document.getElementById('catMsg'),
  catBody: document.getElementById('catBody'),

  itemForm:  document.getElementById('itemForm'),
  itemCat:   document.getElementById('itemCat'),
  itemName:  document.getElementById('itemName'),
  itemSKU:   document.getElementById('itemSKU'),
  itemPrice: document.getElementById('itemPrice'),
  itemMsg:   document.getElementById('itemMsg'),
  itemBody:  document.getElementById('itemBody'),

  filterCat:  document.getElementById('filterCat'),
  clearFilter: document.getElementById('clearFilter'),

  toast: document.getElementById('toast'),
  logoutBtn: document.getElementById('logoutBtn'),
};

let state = {
  categories: [],
  items: [],
  filterCategoryId: null,
};

function showToast(msg, ok = true) {
  els.toast.textContent = msg;
  els.toast.style.background = ok ? '#064e3b' : '#7f1d1d';
  els.toast.style.borderColor = ok ? '#10b98166' : '#ef444466';
  els.toast.hidden = false;
  setTimeout(() => { els.toast.hidden = true; }, 2000);
}

els.logoutBtn.addEventListener('click', async () => {
  try { await apiFetch('../api/logout', { method: 'POST' }); } catch (_){}
  localStorage.removeItem('ffpos_user');
  location.href = '../';
});

async function loadCategories() {
  const res = await apiFetch('../api/menu/categories');
  state.categories = res.data || [];
  renderCategories();
  fillCategorySelects();
}

async function loadItems() {
  const res = await apiFetch('../api/menu/items');
  state.items = res.data || [];
  renderItems();
}

function fillCategorySelects() {
  // For item create
  els.itemCat.innerHTML = '';
  state.categories.forEach(c => {
    const opt = document.createElement('option');
    opt.value = c.id;
    opt.textContent = `${c.name} (#${c.id})`;
    els.itemCat.appendChild(opt);
  });
  // For filter
  els.filterCat.innerHTML = '';
  const optAll = document.createElement('option');
  optAll.value = '';
  optAll.textContent = 'All';
  els.filterCat.appendChild(optAll);
  state.categories.forEach(c => {
    const opt = document.createElement('option');
    opt.value = c.id;
    opt.textContent = c.name;
    els.filterCat.appendChild(opt);
  });
  // Keep selected filter
  if (state.filterCategoryId) {
    els.filterCat.value = String(state.filterCategoryId);
  }
}

function renderCategories() {
  els.catBody.innerHTML = '';
  state.categories
    .slice()
    .sort((a,b) => (a.sort_order ?? 0) - (b.sort_order ?? 0) || a.id - b.id)
    .forEach(c => {
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td>${c.id}</td>
        <td>
          <input data-role="name" type="text" value="${c.name}" style="width:100%">
        </td>
        <td class="num">
          <input data-role="sort" type="number" value="${c.sort_order ?? 0}" style="width:90px">
        </td>
        <td>
          <label class="switch">
            <input data-role="active" type="checkbox" ${c.active ? 'checked':''}>
            <span></span>
          </label>
        </td>
        <td>
          <button data-role="save" class="btn">Save</button>
        </td>
      `;
      // Events
      tr.querySelector('[data-role="save"]').addEventListener('click', async () => {
        const name = tr.querySelector('[data-role="name"]').value.trim();
        const sort = Number(tr.querySelector('[data-role="sort"]').value || 0);
        const active = tr.querySelector('[data-role="active"]').checked ? 1 : 0;
        try {
          await apiFetch(`../api/menu/categories/${c.id}`, {
            method: 'PATCH',
            body: JSON.stringify({ name, sort_order: sort, active })
          });
          showToast('Category updated');
          await loadCategories();
        } catch (e) {
          showToast(e.message || 'Failed', false);
        }
      });
      els.catBody.appendChild(tr);
    });
}

function renderItems() {
  els.itemBody.innerHTML = '';
  const items = state.items.filter(it => {
    if (!state.filterCategoryId) return true;
    return Number(it.category_id) === Number(state.filterCategoryId);
  });
  items.forEach(it => {
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td>${it.id}</td>
      <td>${it.category_name || ''}</td>
      <td><input data-role="name" type="text" value="${it.name}" style="width:100%"></td>
      <td><input data-role="sku" type="text" value="${it.sku ?? ''}" style="width:120px"></td>
      <td class="num"><input data-role="price" type="number" step="0.01" value="${Number(it.price).toFixed(2)}" style="width:120px;text-align:right"></td>
      <td>
        <label class="switch">
          <input data-role="active" type="checkbox" ${it.active ? 'checked':''}>
          <span></span>
        </label>
      </td>
      <td>
        <button data-role="save" class="btn">Save</button>
      </td>
    `;
    tr.querySelector('[data-role="save"]').addEventListener('click', async () => {
      const name = tr.querySelector('[data-role="name"]').value.trim();
      const sku  = tr.querySelector('[data-role="sku"]').value.trim();
      const price= Number(tr.querySelector('[data-role="price"]').value || 0).toFixed(2);
      const active = tr.querySelector('[data-role="active"]').checked ? 1 : 0;
      try {
        await apiFetch(`../api/menu/items/${it.id}`, {
          method: 'PATCH',
          body: JSON.stringify({
            name,
            sku: sku === '' ? null : sku,
            price,
            active
          })
        });
        showToast('Item updated');
        await loadItems();
      } catch (e) {
        showToast(e.message || 'Failed', false);
      }
    });
    els.itemBody.appendChild(tr);
  });
}

els.catForm.addEventListener('submit', async (e) => {
  e.preventDefault();
  els.catMsg.textContent = 'Saving...';
  try {
    const name = els.catName.value.trim();
    const sort_order = Number(els.catSort.value || 0);
    await apiFetch('../api/menu/categories', {
      method: 'POST',
      body: JSON.stringify({ name, sort_order, active: 1 })
    });
    els.catName.value = '';
    els.catSort.value = '0';
    els.catMsg.textContent = '';
    showToast('Category added');
    await loadCategories();
  } catch (e2) {
    els.catMsg.textContent = e2.message || 'Failed';
  }
});

els.itemForm.addEventListener('submit', async (e) => {
  e.preventDefault();
  els.itemMsg.textContent = 'Saving...';
  try {
    const category_id = Number(els.itemCat.value);
    const name = els.itemName.value.trim();
    const sku  = els.itemSKU.value.trim();
    const price= Number(els.itemPrice.value || 0).toFixed(2);
    await apiFetch('../api/menu/items', {
      method: 'POST',
      body: JSON.stringify({
        category_id,
        name,
        sku: sku === '' ? null : sku,
        price,
        active: 1
      })
    });
    els.itemName.value = '';
    els.itemSKU.value = '';
    els.itemPrice.value = '';
    els.itemMsg.textContent = '';
    showToast('Item added');
    await loadItems();
  } catch (e2) {
    els.itemMsg.textContent = e2.message || 'Failed';
  }
});

els.filterCat.addEventListener('change', () => {
  state.filterCategoryId = els.filterCat.value ? Number(els.filterCat.value) : null;
  renderItems();
});
els.clearFilter.addEventListener('click', () => {
  state.filterCategoryId = null;
  els.filterCat.value = '';
  renderItems();
});

// boot
(async function boot() {
  try {
    await loadCategories();
    await loadItems();
  } catch (e) {
    showToast('Failed to load data', false);
  }
})();