(function initGlobalNav(){
  try{
    // Mark body to hide any old header and add top padding
    document.body.classList.add('has-global-nav');

    // Menu config
    const items = [
      { key: 'dashboard',   label: 'Dashboard',    href: 'dashboard.html' },
      { key: 'new-order',   label: 'New Order',    href: 'pos.html' },
      { key: 'order-list',  label: 'Order List',   href: 'orders.html' },
      { key: 'new-billing', label: 'New Billing',  href: 'billing.html' },
      { key: 'billing-list',label: 'Billing List', href: 'billing-list.html' },
      { key: 'menu',        label: 'Menu Manager', href: 'menu.html' },
      { key: 'expenses',    label: 'Expenses',     href: 'expenses.html' },
      // Show both reports: legacy reports.html and the new Sales Report page
      { key: 'reports',     label: 'Reports (Legacy)', href: 'reports.html' },
      { key: 'sales-report',label: 'Sales Report', href: 'sales_report.html' },
      { key: 'logout',      label: 'Logout',       href: 'logout.html' },
    ];

    // Build DOM
    const nav = document.createElement('nav');
    nav.className = 'global-nav';
    nav.innerHTML = `
      <div class="gn-inner">
        <div class="brand">
          <img src="assets/logo.png" alt="Logo">
      
        </div>
        <div class="menu" role="menubar" aria-label="Main">
          ${items.map(i => `<a role="menuitem" data-key="${i.key}" href="${i.href}">${i.label}</a>`).join('')}
        </div>
        <div class="right">
          <button class="hamburger" aria-label="Toggle Menu" title="Menu">☰</button>
        </div>
      </div>
    `;
    document.body.prepend(nav);

    // Highlight active
    const path = (location.pathname.split('/').pop() || '').toLowerCase();
    const links = nav.querySelectorAll('.menu a');
    links.forEach(a=>{
      const href = (a.getAttribute('href') || '').toLowerCase();
      if (href && path === href) a.classList.add('active');
      // If you want startsWith matching, uncomment:
      // if (href && path.indexOf(href) === 0) a.classList.add('active');
    });

    // Mobile toggle
    const burger = nav.querySelector('.hamburger');
    if (burger) burger.addEventListener('click', ()=>{ nav.classList.toggle('open'); });

    // Adjust body padding to header height (handles custom toolbars)
    function adjustTopPadding(){
      const h = nav.offsetHeight || 56;
      document.body.style.paddingTop = h + 'px';
    }
    window.addEventListener('resize', adjustTopPadding);
    adjustTopPadding();
  }catch(e){
    console.error('Global nav init failed:', e);
  }
})();