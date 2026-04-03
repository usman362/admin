/**
 * assets/js/dashboard.js
 * OSINT Supplier Risk Dashboard — Frontend Logic
 */

const API = 'https://osint-dashboard.com/api.php';

let state = {
  page: 1,
  sort: 'alert_date',
  dir:  'desc',
  filters: { q: '', risk: '', risk_type: '', source: '', supplier: '', days: '' },
};

let autoRefreshTimer = null;

// -----------------------------------------------
// Initialise
// -----------------------------------------------
document.addEventListener('DOMContentLoaded', () => {
  loadStats();
  loadFilterOptions();
  loadAlerts();
  bindEvents();
  scheduleAutoRefresh();
});

// -----------------------------------------------
// Bind UI events
// -----------------------------------------------
function bindEvents() {
  // Filters
  document.getElementById('filter-q').addEventListener('input',   debounce(() => { state.page = 1; loadAlerts(); }, 400));
  document.getElementById('filter-risk').addEventListener('change',     () => { state.page = 1; loadAlerts(); });
  document.getElementById('filter-type').addEventListener('change',     () => { state.page = 1; loadAlerts(); });
  document.getElementById('filter-source').addEventListener('change',   () => { state.page = 1; loadAlerts(); });
  document.getElementById('filter-supplier').addEventListener('change', () => { state.page = 1; loadAlerts(); });
  document.getElementById('filter-days').addEventListener('change',     () => { state.page = 1; loadAlerts(); });

  document.getElementById('btn-clear-filters').addEventListener('click', clearFilters);
  document.getElementById('btn-refresh').addEventListener('click', refresh);

  // Export
  document.getElementById('btn-export-json').addEventListener('click', () => {
    window.open(`${API}?action=export&format=json`, '_blank');
  });
  document.getElementById('btn-export-csv').addEventListener('click', () => {
    window.open(`${API}?action=export&format=csv`, '_blank');
  });

  // Sort headers
  document.querySelectorAll('.sortable').forEach(th => {
    th.addEventListener('click', () => {
      const col = th.dataset.sort;
      if (state.sort === col) {
        state.dir = state.dir === 'desc' ? 'asc' : 'desc';
      } else {
        state.sort = col;
        state.dir  = 'desc';
      }
      state.page = 1;
      loadAlerts();
    });
  });

  // Modal close
  document.getElementById('modal-close').addEventListener('click', closeModal);
  document.getElementById('modal-overlay').addEventListener('click', e => {
    if (e.target === document.getElementById('modal-overlay')) closeModal();
  });
  document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });
}

// -----------------------------------------------
// Load stats cards
// -----------------------------------------------
async function loadStats() {
  try {
    const data = await apiFetch(`${API}?action=stats`);
    if (!data.success) return;

    const d = data.data;
    document.getElementById('stat-total').textContent  = d.total;
    document.getElementById('stat-high').textContent   = d.high;
    document.getElementById('stat-medium').textContent = d.medium;
    document.getElementById('stat-low').textContent    = d.low;
    document.getElementById('stat-unread-high').textContent = d.unread_high + ' unread';
    document.getElementById('high-count-badge').textContent = d.unread_high + ' HIGH';
    document.getElementById('stat-last-updated').textContent = 'Updated ' + timeAgo(new Date());

    // Risk type breakdown
    const row = document.getElementById('type-breakdown');
    row.innerHTML = '';
    const typeClass = {
      'Security': 'chip-security', 'Financial': 'chip-financial',
      'Supply Chain': 'chip-supply', 'Geopolitical': 'chip-geo', 'Other': 'chip-other'
    };
    (d.by_type || []).forEach(t => {
      const cls = typeClass[t.risk_type] || 'chip-other';
      row.innerHTML += `<span class="breakdown-chip ${cls}">${esc(t.risk_type)}: ${t.cnt}</span>`;
    });

  } catch (e) {
    console.error('Stats error:', e);
  }
}

// -----------------------------------------------
// Load filter options (sources / suppliers)
// -----------------------------------------------
async function loadFilterOptions() {
  try {
    const data = await apiFetch(`${API}?action=filter_options`);
    if (!data.success) return;

    const srcSel = document.getElementById('filter-source');
    data.sources.forEach(s => {
      srcSel.innerHTML += `<option value="${esc(s)}">${esc(s)}</option>`;
    });

    const supSel = document.getElementById('filter-supplier');
    data.suppliers.forEach(s => {
      supSel.innerHTML += `<option value="${esc(s)}">${esc(s)}</option>`;
    });

  } catch (e) {
    console.error('Filter options error:', e);
  }
}

// -----------------------------------------------
// Load alerts table
// -----------------------------------------------
async function loadAlerts() {
  const tbody = document.getElementById('alerts-tbody');
  tbody.innerHTML = '<tr><td colspan="7" class="loading-row">Loading…</td></tr>';

  const params = new URLSearchParams({
    action:   'alerts',
    page:     state.page,
    sort:     state.sort,
    dir:      state.dir,
    q:        document.getElementById('filter-q').value.trim(),
    risk:     document.getElementById('filter-risk').value,
    risk_type: document.getElementById('filter-type').value,
    source:   document.getElementById('filter-source').value,
    supplier: document.getElementById('filter-supplier').value,
    days:     document.getElementById('filter-days').value,
  });

  // Update sort header indicators
  document.querySelectorAll('.sortable').forEach(th => {
    th.classList.toggle('sort-active', th.dataset.sort === state.sort);
  });

  try {
    const data = await apiFetch(`${API}?${params}`);
    if (!data.success) {
      tbody.innerHTML = '<tr><td colspan="7" class="loading-row">Error loading alerts.</td></tr>';
      return;
    }

    document.getElementById('result-count').textContent =
      `${data.meta.total} alert${data.meta.total !== 1 ? 's' : ''}`;

    if (data.data.length === 0) {
      tbody.innerHTML = '<tr><td colspan="7" class="loading-row">No alerts matching current filters.</td></tr>';
    } else {
      tbody.innerHTML = data.data.map(renderRow).join('');
      // Bind row events
      tbody.querySelectorAll('[data-detail]').forEach(el => {
        el.addEventListener('click', () => openModal(el.dataset.detail));
      });
      tbody.querySelectorAll('[data-dismiss]').forEach(el => {
        el.addEventListener('click', () => dismissAlert(el.dataset.dismiss, el));
      });
    }

    renderPagination(data.meta);

  } catch (e) {
    tbody.innerHTML = `<tr><td colspan="7" class="loading-row">Error: ${esc(e.message)}</td></tr>`;
  }
}

// -----------------------------------------------
// Render a single alert row
// -----------------------------------------------
function renderRow(a) {
  const lvl   = a.risk_level || 'Low';
  const rtype = a.risk_type  || 'Other';

  const riskBadge = `<span class="badge badge-${lvl.toLowerCase()}">${esc(lvl)}</span>`;
  const typeBadge = `<span class="badge badge-${rtype.toLowerCase().replace(' ', '-').replace('supply chain','supply').replace('geopolitical','geo')}">${esc(rtype)}</span>`;

  const dateStr = formatDate(a.alert_date);
  const isGeneral = (a.supplier_name === 'General' || !a.supplier_name);
  const supplierCls = isGeneral ? 'td-supplier general' : 'td-supplier';
  const supplierTxt = isGeneral ? '—' : esc(a.supplier_name);

  const keywords = a.keywords_matched
    ? `<span class="td-keywords">🏷 ${esc(a.keywords_matched)}</span>` : '';

  const srcShort = esc((a.source || '').replace(' Feed', '').replace(' Alerts', ''));

  return `
    <tr class="row-${lvl.toLowerCase()}">
      <td>${riskBadge}</td>
      <td>${typeBadge}</td>
      <td class="td-date">${dateStr}</td>
      <td class="td-title">
        <span class="td-title-text" data-detail="${a.id}">${esc(a.title)}</span>
        ${keywords}
      </td>
      <td class="${supplierCls}">${supplierTxt}</td>
      <td class="td-source">${srcShort}</td>
      <td>
        <div class="action-btns">
          <button class="btn-detail" data-detail="${a.id}">Detail</button>
          ${a.link ? `<a href="${esc(a.link)}" target="_blank" rel="noopener" class="btn-link">↗</a>` : ''}
          <button class="btn-dismiss" data-dismiss="${a.id}" title="Dismiss">✕</button>
        </div>
      </td>
    </tr>`;
}

// -----------------------------------------------
// Open detail modal
// -----------------------------------------------
async function openModal(id) {
  const overlay = document.getElementById('modal-overlay');
  const body    = document.getElementById('modal-body');
  body.innerHTML = '<p style="color:var(--text-dim);text-align:center;padding:40px">Loading…</p>';
  overlay.classList.add('open');

  try {
    const data = await apiFetch(`${API}?action=alert&id=${id}`);
    if (!data.success) { body.innerHTML = '<p>Could not load alert.</p>'; return; }

    const a = data.data;
    const lvl = a.risk_level || 'Low';

    body.innerHTML = `
      <div class="modal-risk">
        <span class="badge badge-${lvl.toLowerCase()}">${esc(lvl)} RISK</span>
        &nbsp;
        <span class="badge badge-${(a.risk_type||'other').toLowerCase().replace(' ','-').replace('supply chain','supply')}">${esc(a.risk_type)}</span>
      </div>
      <h2 class="modal-title">${esc(a.title)}</h2>
      <div class="modal-meta">
        <span>📡 <strong>${esc(a.source)}</strong></span>
        <span>🕒 <strong>${formatDateFull(a.alert_date)}</strong></span>
        ${a.supplier_name && a.supplier_name !== 'General'
          ? `<span>🏭 Supplier: <strong>${esc(a.supplier_name)}</strong></span>` : ''}
      </div>
      ${a.summary ? `<div class="modal-summary">${esc(a.summary)}</div>` : ''}
      ${a.keywords_matched ? `<p class="modal-keywords">🏷 Keywords matched: ${esc(a.keywords_matched)}</p>` : ''}
      ${a.link ? `<a href="${esc(a.link)}" target="_blank" rel="noopener" class="modal-link">↗ View Original Source</a>` : ''}
    `;
  } catch (e) {
    body.innerHTML = `<p>Error: ${esc(e.message)}</p>`;
  }
}

function closeModal() {
  document.getElementById('modal-overlay').classList.remove('open');
}

// -----------------------------------------------
// Dismiss an alert
// -----------------------------------------------
async function dismissAlert(id, btn) {
  btn.disabled = true;
  btn.textContent = '…';
  try {
    const fd = new FormData();
    fd.append('id', id);
    await fetch(`${API}?action=dismiss`, { method: 'POST', body: fd });
    // Remove row from DOM
    const row = btn.closest('tr');
    row.style.opacity = '0';
    row.style.transition = 'opacity 0.3s';
    setTimeout(() => { row.remove(); loadStats(); }, 300);
  } catch (e) {
    btn.disabled = false;
    btn.textContent = '✕';
  }
}

// -----------------------------------------------
// Pagination
// -----------------------------------------------
function renderPagination(meta) {
  const pag = document.getElementById('pagination');
  if (meta.pages <= 1) { pag.innerHTML = ''; return; }

  let html = '';
  html += `<button class="page-btn" ${meta.page <= 1 ? 'disabled' : ''} onclick="goPage(${meta.page-1})">‹ Prev</button>`;

  // Show page numbers (max 7 visible)
  const start = Math.max(1, meta.page - 3);
  const end   = Math.min(meta.pages, meta.page + 3);
  for (let p = start; p <= end; p++) {
    html += `<button class="page-btn ${p === meta.page ? 'active' : ''}" onclick="goPage(${p})">${p}</button>`;
  }

  html += `<button class="page-btn" ${meta.page >= meta.pages ? 'disabled' : ''} onclick="goPage(${meta.page+1})">Next ›</button>`;
  pag.innerHTML = html;
}

function goPage(p) {
  state.page = p;
  loadAlerts();
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

// -----------------------------------------------
// Clear filters
// -----------------------------------------------
function clearFilters() {
  document.getElementById('filter-q').value        = '';
  document.getElementById('filter-risk').value     = '';
  document.getElementById('filter-type').value     = '';
  document.getElementById('filter-source').value   = '';
  document.getElementById('filter-supplier').value = '';
  document.getElementById('filter-days').value     = '';
  state.page = 1;
  loadAlerts();
}

// -----------------------------------------------
// Refresh
// -----------------------------------------------
function refresh() {
  loadStats();
  loadAlerts();
}

// -----------------------------------------------
// Auto-refresh every 5 minutes
// -----------------------------------------------
function scheduleAutoRefresh() {
  clearTimeout(autoRefreshTimer);
  autoRefreshTimer = setTimeout(() => {
    refresh();
    scheduleAutoRefresh();
  }, 5 * 60 * 1000);
}

// -----------------------------------------------
// Utility functions
// -----------------------------------------------
async function apiFetch(url) {
  const res = await fetch(url);
  if (!res.ok) throw new Error(`HTTP ${res.status}`);
  return res.json();
}

function esc(str) {
  if (!str) return '';
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

function formatDate(str) {
  if (!str) return '—';
  const d = new Date(str);
  if (isNaN(d)) return str;
  return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short' }) +
         ' ' + d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
}

function formatDateFull(str) {
  if (!str) return '—';
  const d = new Date(str);
  if (isNaN(d)) return str;
  return d.toLocaleString('en-GB', { dateStyle: 'long', timeStyle: 'short' });
}

function timeAgo(date) {
  const secs = Math.floor((Date.now() - date) / 1000);
  if (secs < 60)  return 'just now';
  if (secs < 3600) return Math.floor(secs/60) + 'm ago';
  return Math.floor(secs/3600) + 'h ago';
}

function debounce(fn, delay) {
  let t;
  return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), delay); };
}
