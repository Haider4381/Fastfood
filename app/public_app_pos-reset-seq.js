// Insert this script into public/app/pos.js or pos.html (after app.js so apiFetch is available)

(function(){
  // Utility: small toast (if you already have a toast utility use that)
  function toast(msg, ok = true) {
    const t = document.getElementById('toast');
    if (!t) { alert(msg); return; }
    t.textContent = msg;
    t.style.background = ok ? '#064e3b' : '#7f1d1d';
    t.hidden = false;
    setTimeout(()=> t.hidden = true, 1800);
  }

  // Find the existing Reset button container (adjust selector if different)
  // In your screenshot Reset button appears near an input for current order id.
  const resetBtn = document.querySelector('button#resetBtn') || document.querySelector('button.btn[title="Reset"]') || document.querySelector('button:contains("Reset")');

  // Fallback: try to locate the area by the 'Current Order ID' label parent
  let insertAfter = resetBtn;
  if (!insertAfter) {
    const label = [...document.querySelectorAll('label, .muted')].find(el => /Current Order ID/i.test(el.textContent || ''));
    if (label) insertAfter = label.closest('div')?.querySelector('button');
  }

  // Create new button
  const resetSeqBtn = document.createElement('button');
  resetSeqBtn.id = 'resetSeqBtn';
  resetSeqBtn.className = 'btn danger'; // style like your UI (adjust classes)
  resetSeqBtn.textContent = 'Reset Order No';

  // Insert into DOM next to existing Reset (if found) otherwise append to topbar
  if (insertAfter && insertAfter.parentNode) {
    insertAfter.parentNode.insertBefore(resetSeqBtn, insertAfter.nextSibling);
  } else {
    // fallback: place in topbar actions
    const topbar = document.querySelector('.topbar .topbar-actions') || document.querySelector('header.topbar');
    if (topbar) topbar.appendChild(resetSeqBtn);
  }

  // Handler
  resetSeqBtn.addEventListener('click', async function(){
    try {
      // get branch id from UI input (adjust selector to match your page)
      const branchInput = document.querySelector('input#branchId') || document.querySelector('input[name="branch_id"]') || document.querySelector('input[type="number"]');
      const branch_id = branchInput ? Number(branchInput.value || branchInput.getAttribute('value') || 0) : 0;
      if (!branch_id) {
        toast('Branch ID missing', false);
        return;
      }

      // First attempt: safe-check (force=0)
      let res = await apiFetch('../api/orders/reset-sequence', {
        method: 'POST',
        body: JSON.stringify({ branch_id: branch_id, force: 0 })
      });

      // If the server returned status 409 with existing_orders we will get thrown earlier.
      // But apiFetch may throw; to be safe handle response status by using fetch directly if needed.
      toast('Sequence reset successful', true);
    } catch (err) {
      // If error indicates existing orders, ask user to confirm force
      // err may be an object from json_error or a thrown Error. We'll try to parse.
      let parsed = null;
      try { parsed = JSON.parse(err.message || err); } catch (e) { parsed = err; }

      // Alternative: do fetch manual to inspect 409 response
      try {
        const resp = await fetch('../api/orders/reset-sequence', {
          method: 'POST',
          headers: {'Content-Type':'application/json'},
          body: JSON.stringify({ branch_id: branch_id, force: 0 })
        });
        if (resp.status === 409) {
          const body = await resp.json();
          const cnt = body.count || 0;
          if (!confirm(`There are ${cnt} existing orders for this date. Click OK to FORCE rename existing orders to unique values and reset sequence (this will change order_no for those orders). Proceed?`)) {
            toast('Cancelled', false);
            return;
          }
          // Force reset
          const resp2 = await fetch('../api/orders/reset-sequence', {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ branch_id: branch_id, force: 1 })
          });
          if (!resp2.ok) {
            const j = await resp2.json().catch(()=>({}));
            toast(j.message || 'Reset failed', false);
            return;
          }
          const j2 = await resp2.json();
          toast('Sequence reset (forced). Next order will start from 1', true);
          return;
        } else {
          // other status
          const j = await resp.json().catch(()=>({}));
          toast(j.error || 'Reset failed', false);
          return;
        }
      } catch (e2) {
        console.error('Reset error', e2);
        toast('Reset failed (network/server)', false);
      }
    }
  });
})();