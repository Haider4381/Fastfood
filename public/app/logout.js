// Logout
on(ui.logoutBtn, 'click', async () => {
  try { await apiFetch('../api/logout', { method: 'POST' }); } catch (_){}
  try { localStorage.removeItem('ffpos_user'); localStorage.removeItem('ffpos_last_order_id'); } catch(_){}
  goPublicRoot();
});
