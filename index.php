<?php
/**
 * index.php — Main OSINT Supplier Risk Dashboard
 */
require_once __DIR__ . '/config.php';
session_start();

// Require login
if (empty($_SESSION['user_id'])) {
    header('Location: auth.php?action=login');
    exit;
}

$userName = $_SESSION['user_name'] ?? 'User';
$userRole = $_SESSION['user_role'] ?? 'user';
$title = getSetting('dashboard_title', 'OSINT Supplier Risk Dashboard');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($title) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;600&family=IBM+Plex+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/dashboard.css">
</head>
<body>

<header class="topbar">
  <div class="topbar-inner">
    <div class="logo">
      <span class="logo-icon">⬡</span>
      <span class="logo-text"><?= e($title) ?></span>
    </div>
    <nav class="topbar-nav">
      <span class="nav-badge" id="high-count-badge">— HIGH</span>
      <span class="nav-user">👤 <?= e($userName) ?></span>
      <?php if ($userRole === 'admin'): ?>
      <a href="admin/" class="nav-link">⚙ Admin</a>
      <?php endif; ?>
      <button class="btn-icon" id="btn-export-json" title="Export JSON">⬇ JSON</button>
      <button class="btn-icon" id="btn-export-csv" title="Export CSV">⬇ CSV</button>
      <button class="btn-icon" id="btn-refresh" title="Refresh">↻</button>
      <a href="logout.php" class="nav-link" style="color:var(--high);border-color:var(--high)">⏻ Logout</a>
    </nav>
  </div>
</header>

<main class="container">

  <!-- Summary Cards -->
  <section class="cards-row" id="stats-cards">
    <div class="stat-card total">
      <div class="stat-label">TOTAL ALERTS</div>
      <div class="stat-value" id="stat-total">—</div>
      <div class="stat-sub" id="stat-last-updated">Loading…</div>
    </div>
    <div class="stat-card high">
      <div class="stat-label">HIGH RISK</div>
      <div class="stat-value" id="stat-high">—</div>
      <div class="stat-sub" id="stat-unread-high">— unread</div>
    </div>
    <div class="stat-card medium">
      <div class="stat-label">MEDIUM RISK</div>
      <div class="stat-value" id="stat-medium">—</div>
    </div>
    <div class="stat-card low">
      <div class="stat-label">LOW RISK</div>
      <div class="stat-value" id="stat-low">—</div>
    </div>
  </section>

  <!-- Risk Type Breakdown -->
  <section class="breakdown-row" id="type-breakdown">
    <!-- filled by JS -->
  </section>

  <!-- Filters -->
  <section class="filter-bar">
    <div class="filter-group">
      <input type="search" id="filter-q" placeholder="Search title, keyword…" class="filter-input">
    </div>
    <div class="filter-group">
      <select id="filter-risk" class="filter-select">
        <option value="">All Risk Levels</option>
        <option value="High">High</option>
        <option value="Medium">Medium</option>
        <option value="Low">Low</option>
      </select>
    </div>
    <div class="filter-group">
      <select id="filter-type" class="filter-select">
        <option value="">All Risk Types</option>
        <option value="Security">Security</option>
        <option value="Financial">Financial</option>
        <option value="Supply Chain">Supply Chain</option>
        <option value="Geopolitical">Geopolitical</option>
        <option value="Other">Other</option>
      </select>
    </div>
    <div class="filter-group">
      <select id="filter-source" class="filter-select">
        <option value="">All Sources</option>
      </select>
    </div>
    <div class="filter-group">
      <select id="filter-supplier" class="filter-select">
        <option value="">All Suppliers</option>
      </select>
    </div>
    <div class="filter-group">
      <select id="filter-days" class="filter-select">
        <option value="">Any Time</option>
        <option value="1">Last 24h</option>
        <option value="7">Last 7 Days</option>
        <option value="30">Last 30 Days</option>
      </select>
    </div>
    <button class="btn-clear" id="btn-clear-filters">✕ Clear</button>
  </section>

  <!-- Alerts Table -->
  <section class="table-section">
    <div class="table-header">
      <span class="table-title">LIVE ALERTS</span>
      <span class="result-count" id="result-count">—</span>
    </div>

    <div class="table-wrap">
      <table class="alerts-table" id="alerts-table">
        <thead>
          <tr>
            <th class="th-risk sortable" data-sort="risk_level">RISK ▾</th>
            <th class="th-type">TYPE</th>
            <th class="th-date sortable" data-sort="alert_date">DATE ▾</th>
            <th class="th-title">TITLE / HEADLINE</th>
            <th class="th-supplier sortable" data-sort="supplier_name">SUPPLIER</th>
            <th class="th-source sortable" data-sort="source">SOURCE</th>
            <th class="th-actions">ACTIONS</th>
          </tr>
        </thead>
        <tbody id="alerts-tbody">
          <tr><td colspan="7" class="loading-row">Loading alerts…</td></tr>
        </tbody>
      </table>
    </div>

    <!-- Pagination -->
    <div class="pagination" id="pagination"></div>
  </section>

</main>

<!-- Alert Detail Modal -->
<div class="modal-overlay" id="modal-overlay">
  <div class="modal" id="alert-modal">
    <button class="modal-close" id="modal-close">✕</button>
    <div class="modal-body" id="modal-body"></div>
  </div>
</div>

<footer class="footer">
  <span>OSINT Supplier Risk Dashboard v<?= APP_VERSION ?> · Auto-refreshes every 5 min</span>
  <span id="last-cron-info"></span>
</footer>

<script src="assets/js/dashboard.js"></script>
</body>
</html>
