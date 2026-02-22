// Small helper for same-origin API calls (updated for deployed /public/ path + credentials)
//
// Changes made:
// - Detects if app is served under "/public" and rewrites ../api/ and /api/ calls to include that prefix.
// - Always includes credentials ('include') so PHP session cookie is preserved when logging in.
// - Better error object with status and response body for easier debugging.

(function(){
  // compute API base depending on whether the site is served under a "public" folder
  let API_BASE = '';
  try {
    const p = location.pathname || '/';
    // If path contains "/public/" use exactly that prefix (keeps subfolder structure)
    const idx = p.indexOf('/public/');
    if (idx !== -1) {
      API_BASE = p.slice(0, idx + '/public'.length); // e.g. "/restaurant/public"
    } else if (p.endsWith('/public') || p === '/public/') {
      API_BASE = p.replace(/\/+$/, ''); // trim trailing slash
    } else {
      API_BASE = ''; // document-root deployment
    }
  } catch (e) {
    API_BASE = '';
  }
  // Expose to other scripts if needed
  window.__API_BASE = API_BASE;
})();

async function apiFetch(path, options = {}) {
  // Normalize path:
  // - '../api/...', './api/...' and '/api/...' should map to API_BASE + '/api/...'
  // - absolute URLs (http(s)://) are left untouched
  let url = path;
  const API_BASE = window.__API_BASE || '';

  if (typeof url === 'string') {
    const isAbsolute = url.match(/^https?:\/\//i);
    if (!isAbsolute) {
      if (url.startsWith('../api/')) {
        url = (API_BASE ? API_BASE : '') + '/api/' + url.substring('../api/'.length);
      } else if (url.startsWith('./api/')) {
        url = (API_BASE ? API_BASE : '') + '/api/' + url.substring('./api/'.length);
      } else if (url.startsWith('/api/')) {
        url = (API_BASE ? API_BASE : '') + url;
      } else if (url.indexOf('api/') === 0) {
        // relative 'api/...' -> prefix with API_BASE
        url = (API_BASE ? API_BASE : '') + '/' + url;
      }
      // any other relative paths left as-is
    }
  }

  // Prepare fetch options: default Accept header, JSON content-type only when body is provided
  const headers = Object.assign(
    { 'Accept': 'application/json' },
    (options.headers || {})
  );

  let body = options.body;
  // If body provided as object and Content-Type not set, assume JSON
  if (body && typeof body === 'object' && !(body instanceof FormData) && !headers['Content-Type']) {
    headers['Content-Type'] = 'application/json';
    try { body = JSON.stringify(body); } catch (e) { /* leave as-is */ }
  }

  const fetchOpts = {
    method: options.method || 'GET',
    headers,
    credentials: options.credentials || 'include', // include cookies for session auth
    body: (typeof body !== 'undefined') ? body : undefined,
    // allow callers to pass mode, redirect, etc.
    ...(['mode','cache','redirect','referrer','referrerPolicy','integrity'].reduce((acc,k)=>{
      if (k in options) acc[k] = options[k];
      return acc;
    }, {}))
  };

  const res = await fetch(url, fetchOpts);

  // Read text and try parse JSON when content-type is application/json
  const ct = res.headers.get('content-type') || '';
  let text = null;
  try { text = await res.text(); } catch (e) { text = null; }

  let data = text;
  if (text && ct.indexOf('application/json') !== -1) {
    try { data = JSON.parse(text); } catch (e) { data = text; }
  }

  if (!res.ok) {
    const errMsg = (data && (data.error || data.message)) || res.statusText || 'Request failed';
    const err = new Error(errMsg);
    err.status = res.status;
    err.body = data;
    err.url = url;
    throw err;
  }

  // return parsed JSON, otherwise raw text (or null)
  if (ct.indexOf('application/json') !== -1) return data;
  return data || null;
}

// Always send user to the correct /public/ root (improved detection)
function goPublicRoot() {
  try {
    const p = location.pathname.replace(/[/\\]+/g, '/');
    const i = p.indexOf('/public/');
    if (i !== -1) {
      const root = p.slice(0, i + '/public/'.length); // includes trailing slash
      location.href = location.origin + root;
      return;
    }
    // If the URL itself ends with /public or /public/ use that
    if (p.endsWith('/public') || p === '/public/') {
      location.href = location.origin + p.replace(/\/+$/, '') + '/';
      return;
    }
    // Fallback: navigate to /public/ at site root
    location.href = location.origin + '/public/';
  } catch {
    try {
      location.href = '/public/';
    } catch (e) {
      /* ignore */
    }
  }
}

// Expose apiFetch and goPublicRoot for other scripts
window.apiFetch = apiFetch;
window.goPublicRoot = goPublicRoot;