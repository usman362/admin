<?php
/**
 * admin/index.php — Admin Panel
 * Features: User management, multi-keyword add, dynamic risk types/levels
 */
require_once __DIR__ . '/../config.php';
session_start();

// Admin login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    if ($_POST['password'] === ADMIN_PASSWORD) {
        $_SESSION['admin_auth'] = true;
        $_SESSION['admin_ip']   = $_SERVER['REMOTE_ADDR'];
        header('Location: index.php'); exit;
    } else {
        $loginError = 'Incorrect password.';
        sleep(1);
    }
}
if (isset($_GET['logout'])) { session_destroy(); header('Location: index.php'); exit; }

if (empty($_SESSION['admin_auth'])) { ?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Admin Login</title>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;600&display=swap" rel="stylesheet">
<style>
:root{--bg:#0a0c10;--surface:#111318;--border:#252830;--accent:#00d4ff;--text:#c8cdd8;--high:#ff3b5c;}
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--text);font-family:'IBM Plex Mono',monospace;display:flex;align-items:center;justify-content:center;min-height:100vh;}
.box{background:var(--surface);border:1px solid var(--border);padding:48px;width:380px;}
.title{font-size:.8rem;letter-spacing:.15em;color:var(--accent);margin-bottom:32px;text-align:center;}
label{display:block;font-size:.7rem;letter-spacing:.1em;color:#5a6070;margin-bottom:8px;}
input{width:100%;background:#0a0c10;border:1px solid var(--border);color:var(--text);padding:12px;font-family:inherit;font-size:.9rem;outline:none;margin-bottom:20px;}
input:focus{border-color:var(--accent);}
button{width:100%;background:var(--accent);color:#000;border:none;padding:12px;font-family:inherit;font-size:.8rem;font-weight:600;cursor:pointer;letter-spacing:.1em;}
.err{color:var(--high);font-size:.72rem;margin-bottom:16px;text-align:center;}
</style></head><body>
<div class="box">
  <div class="title">⬡ OSINT DASHBOARD · ADMIN</div>
  <?php if (!empty($loginError)) echo '<p class="err">'.e($loginError).'</p>'; ?>
  <form method="POST"><label>ADMIN PASSWORD</label><input type="password" name="password" autofocus><button type="submit">ENTER</button></form>
</div></body></html>
<?php exit; }

// -----------------------------------------------
$section = $_GET['section'] ?? ($_POST['section'] ?? 'dashboard');
$message = '';
$db = getDB();

// Helper: get dynamic risk types
function getRiskTypes() {
    try { return getDB()->query("SELECT name FROM risk_types WHERE active=1 ORDER BY name")->fetchAll(PDO::FETCH_COLUMN); }
    catch(Exception $e) { return ['Security','Financial','Supply Chain','Geopolitical','Other']; }
}
// Helper: get dynamic risk levels
function getRiskLevels() {
    try { return getDB()->query("SELECT name FROM risk_levels WHERE active=1 ORDER BY sort_order")->fetchAll(PDO::FETCH_COLUMN); }
    catch(Exception $e) { return ['High','Medium','Low']; }
}

// -----------------------------------------------
// POST handlers
// -----------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // SETTINGS
    if ($section === 'settings' && isset($_POST['save_settings'])) {
        foreach (['email_notifications','notification_email','smtp_host','smtp_port','smtp_user','alert_retention_days','min_risk_notify','dashboard_title'] as $k) {
            if (isset($_POST[$k])) setSetting($k, trim($_POST[$k]));
        }
        $message = 'Settings saved.';
    }

    // ADMIN PASSWORD CHANGE
    if ($section === 'settings' && isset($_POST['change_admin_password'])) {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        if (empty($current) || empty($new) || empty($confirm)) {
            $message = 'ERROR: All password fields are required.';
        } elseif ($current !== ADMIN_PASSWORD) {
            sleep(1);
            $message = 'ERROR: Current password is incorrect.';
        } elseif (strlen($new) < 6) {
            $message = 'ERROR: New password must be at least 6 characters.';
        } elseif ($new !== $confirm) {
            $message = 'ERROR: New passwords do not match.';
        } else {
            $configFile = __DIR__ . '/../config.php';
            $content2 = file_get_contents($configFile);
            $escaped = addslashes($new);
            $content2 = preg_replace("/define\('ADMIN_PASSWORD',\s*'.*?'\);$/m", "define('ADMIN_PASSWORD', '{$escaped}');", $content2);
            if (file_put_contents($configFile, $content2) !== false) {
                session_destroy();
                header('Location: index.php?msg=' . urlencode('Password changed successfully. Please login with your new password.'));
                exit;
            } else {
                $message = 'ERROR: Could not save. Check config.php file permissions (chmod 644).';
            }
        }
    }

    // SUPPLIERS
    if ($section === 'suppliers') {
        if (isset($_POST['add_supplier'])) {
            $supplierName = trim($_POST['name'] ?? '');
            if (empty($supplierName)) {
                $message = 'ERROR: Supplier name is required.';
            } else {
                try {
                    $db->prepare('INSERT INTO suppliers (name,aliases,category,country,criticality,contact_email,notes) VALUES (?,?,?,?,?,?,?)')->execute([$supplierName,trim($_POST['aliases'] ?? ''),trim($_POST['category'] ?? ''),trim($_POST['country'] ?? ''),$_POST['criticality'] ?? 'Medium',trim($_POST['contact_email'] ?? ''),trim($_POST['notes'] ?? '')]);
                    $message = 'Supplier added.';
                } catch (PDOException $e) {
                    if ($e->getCode() == 23000) {
                        $message = 'ERROR: A supplier with this name already exists.';
                    } else {
                        error_log('Supplier add error: ' . $e->getMessage());
                        $message = 'ERROR: Could not add supplier. ' . $e->getMessage();
                    }
                }
            }
        }
        if (isset($_POST['delete_supplier'])) {
            try {
                $db->prepare('DELETE FROM suppliers WHERE id=?')->execute([(int)$_POST['id']]);
                $message = 'Supplier removed.';
            } catch (PDOException $e) {
                error_log('Supplier delete error: ' . $e->getMessage());
                $message = 'ERROR: Could not delete supplier. ' . $e->getMessage();
            }
        }
        if (isset($_POST['toggle_supplier'])) {
            try {
                $db->prepare('UPDATE suppliers SET active=NOT active WHERE id=?')->execute([(int)$_POST['id']]);
                $message = 'Supplier status updated.';
            } catch (PDOException $e) {
                error_log('Supplier toggle error: ' . $e->getMessage());
                $message = 'ERROR: Could not update supplier status. ' . $e->getMessage();
            }
        }
    }

    // KEYWORDS — multi-add support
    if ($section === 'keywords') {
        if (isset($_POST['add_keyword'])) {
            $raw = trim($_POST['keywords_input'] ?? '');
            $riskType  = trim($_POST['risk_type'] ?? 'Security');
            $riskLevel = trim($_POST['risk_level'] ?? 'Medium');
            // Split by newline or comma
            $lines = preg_split('/[\n,]+/', $raw);
            $added = 0;
            $stmt = $db->prepare('INSERT IGNORE INTO keywords (keyword,risk_type,risk_level) VALUES (?,?,?)');
            foreach ($lines as $line) {
                $kw = strtolower(trim($line));
                if ($kw !== '') { $stmt->execute([$kw, $riskType, $riskLevel]); $added++; }
            }
            $message = $added . ' keyword(s) added.';
        }
        if (isset($_POST['delete_keyword'])) { $db->prepare('DELETE FROM keywords WHERE id=?')->execute([(int)$_POST['id']]); $message='Keyword removed.'; }
        if (isset($_POST['toggle_keyword'])) { $db->prepare('UPDATE keywords SET active=NOT active WHERE id=?')->execute([(int)$_POST['id']]); }
    }

    // SOURCES
    if ($section === 'sources') {
        if (isset($_POST['add_source'])) {
            $db->prepare('INSERT INTO sources (name,type,url,fetch_interval,notes) VALUES (?,?,?,?,?)')->execute([trim($_POST['name']),$_POST['type'],trim($_POST['url']),(int)$_POST['fetch_interval'],trim($_POST['notes'])]);
            $message = 'Source added.';
        }
        if (isset($_POST['delete_source'])) { $db->prepare('DELETE FROM sources WHERE id=?')->execute([(int)$_POST['id']]); $message='Source removed.'; }
        if (isset($_POST['toggle_source'])) { $db->prepare('UPDATE sources SET active=NOT active WHERE id=?')->execute([(int)$_POST['id']]); }
    }

    // RISK TYPES
    if ($section === 'risk_types') {
        if (isset($_POST['add_risk_type'])) {
            $name  = trim($_POST['name'] ?? '');
            $color = trim($_POST['color'] ?? '#6b7280');
            if ($name) { $db->prepare('INSERT IGNORE INTO risk_types (name,color) VALUES (?,?)')->execute([$name,$color]); $message='Risk type added.'; }
        }
        if (isset($_POST['delete_risk_type'])) { $db->prepare('DELETE FROM risk_types WHERE id=?')->execute([(int)$_POST['id']]); $message='Risk type removed.'; }
        if (isset($_POST['toggle_risk_type'])) { $db->prepare('UPDATE risk_types SET active=NOT active WHERE id=?')->execute([(int)$_POST['id']]); }
    }

    // RISK LEVELS
    if ($section === 'risk_levels') {
        if (isset($_POST['add_risk_level'])) {
            $name  = trim($_POST['name'] ?? '');
            $color = trim($_POST['color'] ?? '#6b7280');
            $order = (int)($_POST['sort_order'] ?? 99);
            if ($name) { $db->prepare('INSERT IGNORE INTO risk_levels (name,color,sort_order) VALUES (?,?,?)')->execute([$name,$color,$order]); $message='Risk level added.'; }
        }
        if (isset($_POST['delete_risk_level'])) { $db->prepare('DELETE FROM risk_levels WHERE id=?')->execute([(int)$_POST['id']]); $message='Risk level removed.'; }
        if (isset($_POST['toggle_risk_level'])) { $db->prepare('UPDATE risk_levels SET active=NOT active WHERE id=?')->execute([(int)$_POST['id']]); }
    }

    // USERS
    if ($section === 'users') {
        if (isset($_POST['add_user'])) {
            $name  = trim($_POST['name'] ?? '');
            $email = strtolower(trim($_POST['email'] ?? ''));
            $pass  = trim($_POST['password'] ?? '');
            $role  = in_array($_POST['role']??'', ['user','admin']) ? $_POST['role'] : 'user';
            if ($name && $email && $pass) {
                $hash = password_hash($pass, PASSWORD_BCRYPT);
                $db->prepare('INSERT IGNORE INTO users (name,email,password,role) VALUES (?,?,?,?)')->execute([$name,$email,$hash,$role]);
                $message = 'User added.';
            }
        }
        if (isset($_POST['delete_user'])) { $db->prepare('DELETE FROM users WHERE id=?')->execute([(int)$_POST['id']]); $message='User removed.'; }
        if (isset($_POST['toggle_user'])) { $db->prepare('UPDATE users SET active=NOT active WHERE id=?')->execute([(int)$_POST['id']]); }
        if (isset($_POST['approve_user'])) {
            $db->prepare('UPDATE users SET active=1 WHERE id=?')->execute([(int)$_POST['id']]);
            $message = 'User approved successfully.';
        }
        if (isset($_POST['reject_user'])) {
            $db->prepare('UPDATE users SET active=2 WHERE id=?')->execute([(int)$_POST['id']]);
            $message = 'User rejected.';
        }
        if (isset($_POST['reset_password'])) {
            $pass = trim($_POST['new_password'] ?? '');
            if ($pass && strlen($pass) >= 6) {
                $hash = password_hash($pass, PASSWORD_BCRYPT);
                $db->prepare('UPDATE users SET password=? WHERE id=?')->execute([$hash,(int)$_POST['id']]);
                $message = 'Password reset.';
            }
        }
    }

    if ($message) { header("Location: index.php?section={$section}&msg=".urlencode($message)); exit; }
}

if (isset($_GET['msg'])) $message = $_GET['msg'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Admin Panel — OSINT Dashboard</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;600&family=IBM+Plex+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/dashboard.css">
<link rel="stylesheet" href="admin.css">
</head>
<body>

<header class="topbar">
  <div class="topbar-inner">
    <div class="logo"><span class="logo-icon">⬡</span><span class="logo-text">OSINT Dashboard · Admin Panel</span></div>
    <nav class="topbar-nav">
      <a href="../index.php" class="nav-link">← Dashboard</a>
      <a href="?logout=1" class="nav-link" style="color:var(--high);border-color:var(--high)">⏻ Logout</a>
    </nav>
  </div>
</header>

<div class="admin-layout">
<aside class="admin-sidebar">
<?php
$sections = [
  'dashboard'   => ['⊞', 'Overview'],
  'users'       => ['👤', 'Users'],
  'suppliers'   => ['🏭', 'Suppliers'],
  'keywords'    => ['🏷', 'Keywords'],
  'risk_types'  => ['🎨', 'Risk Types'],
  'risk_levels' => ['⚡', 'Risk Levels'],
  'sources'     => ['📡', 'Sources'],
  'alerts'      => ['🔔', 'Alerts Log'],
  'cron'        => ['⏱', 'Cron Log'],
  'settings'    => ['⚙', 'Settings'],
];
foreach ($sections as $key => [$icon, $label]):
?>
  <a href="?section=<?= $key ?>" class="sidebar-link <?= $section===$key?'active':'' ?>"><?= $icon ?> <?= $label ?></a>
<?php endforeach; ?>
</aside>

<main class="admin-content">
<?php if ($message): ?>
<div class="admin-msg" style="<?= strpos($message,'ERROR')===0 ? 'background:#2d1418;border-color:#ff3b5c;color:#ff6b82;' : 'background:#0f2418;border-color:#22c55e;color:#4ade80;' ?> padding:12px 16px;border:1px solid;margin-bottom:16px;font-size:0.82rem;">
  <?= e($message) ?>
</div>
<?php endif; ?>

<?php
// ==============================================
// OVERVIEW
// ==============================================
if ($section === 'dashboard'):
    $stats   = $db->query("SELECT COUNT(*) AS total, SUM(risk_level='High') AS high, SUM(risk_level='Medium') AS med, SUM(risk_level='Low') AS low FROM alerts WHERE dismissed=0")->fetch();
    $lastCron = $db->query("SELECT * FROM cron_log ORDER BY run_at DESC LIMIT 1")->fetch();
    $totalUsers     = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $totalSuppliers = $db->query("SELECT COUNT(*) FROM suppliers WHERE active=1")->fetchColumn();
    $totalSources   = $db->query("SELECT COUNT(*) FROM sources WHERE active=1")->fetchColumn();
    $totalKeywords  = $db->query("SELECT COUNT(*) FROM keywords WHERE active=1")->fetchColumn();
?>
<h1 class="admin-h1">System Overview</h1>
<div class="overview-grid">
  <div class="ov-card"><div class="ov-val"><?= (int)$stats['total'] ?></div><div class="ov-label">Active Alerts</div></div>
  <div class="ov-card red"><div class="ov-val"><?= (int)$stats['high'] ?></div><div class="ov-label">High Risk</div></div>
  <div class="ov-card yellow"><div class="ov-val"><?= (int)$stats['med'] ?></div><div class="ov-label">Medium Risk</div></div>
  <div class="ov-card green"><div class="ov-val"><?= (int)$stats['low'] ?></div><div class="ov-label">Low Risk</div></div>
  <div class="ov-card"><div class="ov-val"><?= (int)$totalUsers ?></div><div class="ov-label">Users</div></div>
  <div class="ov-card"><div class="ov-val"><?= (int)$totalSuppliers ?></div><div class="ov-label">Suppliers</div></div>
  <div class="ov-card"><div class="ov-val"><?= (int)$totalSources ?></div><div class="ov-label">Sources</div></div>
  <div class="ov-card"><div class="ov-val"><?= (int)$totalKeywords ?></div><div class="ov-label">Keywords</div></div>
</div>
<?php if ($lastCron): ?>
<div class="info-box"><strong>Last Cron Run:</strong> <?= e($lastCron['run_at']) ?> · Source: <?= e($lastCron['source_name']) ?> · Items: <?= (int)$lastCron['items_fetched'] ?> · Alerts: <?= (int)$lastCron['alerts_created'] ?></div>
<?php endif; ?>
<div class="info-box"><strong>Cron Command:</strong><br><code>*/30 * * * * php <?= e(dirname(__DIR__)) ?>/run_cron.php</code></div>

<?php
// ==============================================
// USERS
// ==============================================
elseif ($section === 'users'):
    $users = $db->query("SELECT * FROM users ORDER BY created_at DESC")->fetchAll();
?>
<h1 class="admin-h1">Manage Users</h1>

<?php
$pendingCount = $db->query("SELECT COUNT(*) FROM users WHERE active=0")->fetchColumn();
if($pendingCount > 0):
?>
<div style="background:rgba(245,158,11,0.1);border:1px solid rgba(245,158,11,0.4);color:#f59e0b;font-family:var(--font-mono);font-size:0.75rem;padding:12px 16px;margin-bottom:20px;border-radius:2px;">
  ⚠ <?= (int)$pendingCount ?> user(s) pending approval — review below
</div>
<?php endif; ?>

<form method="POST" class="admin-form">
  <div class="form-row">
    <input name="name" placeholder="Full Name *" required class="form-input">
    <input name="email" type="email" placeholder="Email *" required class="form-input">
    <input name="password" type="password" placeholder="Password *" required class="form-input">
    <select name="role" class="form-select">
      <option value="user">User</option>
      <option value="admin">Admin</option>
    </select>
    <button type="submit" name="add_user" class="btn-admin-primary">+ Add User</button>
  </div>
</form>
<table class="admin-table">
  <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Last Login</th><th>Status</th><th>Actions</th></tr></thead>
  <tbody>
  <?php foreach ($users as $u):
    $status = (int)$u['active'];
  ?>
  <tr>
    <td><?= e($u['name']) ?></td>
    <td><?= e($u['email']) ?></td>
    <td><span class="badge <?= $u['role']==='admin'?'badge-high':'badge-low' ?>"><?= e($u['role']) ?></span></td>
    <td><small><?= $u['last_login'] ? e(substr($u['last_login'],0,16)) : 'Never' ?></small></td>
    <td>
      <?php if($status === 0): ?>
        <span style="font-family:var(--font-mono);font-size:0.7rem;color:#f59e0b;background:rgba(245,158,11,0.1);border:1px solid rgba(245,158,11,0.3);padding:2px 8px;border-radius:2px">⏳ Pending</span>
      <?php elseif($status === 2): ?>
        <span style="font-family:var(--font-mono);font-size:0.7rem;color:var(--high);background:rgba(255,59,92,0.1);border:1px solid rgba(255,59,92,0.3);padding:2px 8px;border-radius:2px">✕ Rejected</span>
      <?php else: ?>
        <span class="status-on">✓ Active</span>
      <?php endif; ?>
    </td>
    <td>
      <?php if($status === 0): ?>
        <!-- PENDING — show Approve / Reject -->
        <form method="POST" style="display:inline" onsubmit="return confirm('Approve this user?')">
          <input type="hidden" name="id" value="<?= $u['id'] ?>">
          <button name="approve_user" class="btn-sm" style="color:var(--low);border-color:var(--low)">✓ Approve</button>
        </form>
        <form method="POST" style="display:inline" onsubmit="return confirm('Reject this user?')">
          <input type="hidden" name="id" value="<?= $u['id'] ?>">
          <button name="reject_user" class="btn-sm btn-danger">✕ Reject</button>
        </form>
      <?php else: ?>
        <form method="POST" style="display:inline">
          <input type="hidden" name="id" value="<?= $u['id'] ?>">
          <button name="toggle_user" class="btn-sm"><?= $status===1?'Disable':'Re-enable' ?></button>
        </form>
      <?php endif; ?>
      <form method="POST" style="display:inline" onsubmit="return confirm('Delete user?')">
        <input type="hidden" name="id" value="<?= $u['id'] ?>">
        <button name="delete_user" class="btn-sm btn-danger">Delete</button>
      </form>
      <?php if($status === 1): ?>
      <form method="POST" style="display:inline" onsubmit="return confirm('Reset password?')">
        <input type="hidden" name="id" value="<?= $u['id'] ?>">
        <input type="password" name="new_password" placeholder="New password" class="form-input" style="width:120px;display:inline;padding:3px 8px;font-size:0.72rem">
        <button name="reset_password" class="btn-sm">Reset PW</button>
      </form>
      <?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<?php
// ==============================================
// SUPPLIERS
// ==============================================
elseif ($section === 'suppliers'):
    $suppliers = $db->query("SELECT * FROM suppliers ORDER BY criticality, name")->fetchAll();
?>
<h1 class="admin-h1">Manage Suppliers</h1>
<form method="POST" action="?section=suppliers" class="admin-form">
  <input type="hidden" name="section" value="suppliers">
  <input type="hidden" name="add_supplier" value="1">
  <div class="form-row">
    <input name="name" placeholder="Company Name *" required class="form-input">
    <input name="aliases" placeholder="Aliases (comma-separated)" class="form-input">
    <input name="category" placeholder="Category" class="form-input">
    <input name="country" placeholder="Country" class="form-input">
    <select name="criticality" class="form-select">
      <option>Critical</option><option>High</option><option selected>Medium</option><option>Low</option>
    </select>
    <input name="contact_email" placeholder="Contact Email" type="email" class="form-input">
  </div>
  <textarea name="notes" placeholder="Notes" class="form-textarea" rows="2"></textarea>
  <button type="submit" class="btn-admin-primary">+ Add Supplier</button>
</form>
<table class="admin-table">
  <thead><tr><th>Name</th><th>Aliases</th><th>Category</th><th>Country</th><th>Criticality</th><th>Status</th><th>Actions</th></tr></thead>
  <tbody>
  <?php foreach ($suppliers as $s): ?>
  <tr class="<?= $s['active']?'':'row-disabled' ?>">
    <td><?= e($s['name']) ?></td>
    <td><small><?= e($s['aliases']) ?></small></td>
    <td><?= e($s['category']) ?></td>
    <td><?= e($s['country']) ?></td>
    <td><span class="badge crit-<?= strtolower((string)($s['criticality'] ?? 'Medium')) ?>"><?= e($s['criticality'] ?? 'Medium') ?></span></td>
    <td><?= $s['active']?'<span class="status-on">Active</span>':'<span class="status-off">Inactive</span>' ?></td>
    <td>
      <form method="POST" action="?section=suppliers" style="display:inline"><input type="hidden" name="section" value="suppliers"><input type="hidden" name="id" value="<?= $s['id'] ?>"><input type="hidden" name="toggle_supplier" value="1"><button type="submit" class="btn-sm"><?= $s['active']?'Disable':'Enable' ?></button></form>
      <form method="POST" action="?section=suppliers" style="display:inline" onsubmit="return confirm('Delete?')"><input type="hidden" name="section" value="suppliers"><input type="hidden" name="id" value="<?= $s['id'] ?>"><input type="hidden" name="delete_supplier" value="1"><button type="submit" class="btn-sm btn-danger">Delete</button></form>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<?php
// ==============================================
// KEYWORDS — Multi-add
// ==============================================
elseif ($section === 'keywords'):
    $keywords  = $db->query("SELECT * FROM keywords ORDER BY risk_level, keyword")->fetchAll();
    $riskTypes  = getRiskTypes();
    $riskLevels = getRiskLevels();
?>
<h1 class="admin-h1">Manage Keywords</h1>

<div class="info-box" style="margin-bottom:16px">
  💡 <strong>Multi-add tip:</strong> Enter multiple keywords — one per line, or comma-separated. All will be added with the same Risk Type and Risk Level in one click.
</div>

<form method="POST" class="admin-form">
  <div class="form-row" style="align-items:flex-start">
    <div style="flex:2">
      <label style="font-family:var(--font-mono);font-size:0.65rem;letter-spacing:0.1em;color:var(--text-dim);display:block;margin-bottom:6px">KEYWORDS (one per line or comma-separated)</label>
      <textarea name="keywords_input" placeholder="ransomware&#10;data breach&#10;supply disruption&#10;bankruptcy" class="form-textarea" rows="5" required style="width:100%"></textarea>
    </div>
    <div style="display:flex;flex-direction:column;gap:8px;min-width:160px">
      <div>
        <label style="font-family:var(--font-mono);font-size:0.65rem;letter-spacing:0.1em;color:var(--text-dim);display:block;margin-bottom:6px">RISK TYPE</label>
        <select name="risk_type" class="form-select" style="width:100%">
          <?php foreach ($riskTypes as $rt): ?>
          <option value="<?= e($rt) ?>"><?= e($rt) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label style="font-family:var(--font-mono);font-size:0.65rem;letter-spacing:0.1em;color:var(--text-dim);display:block;margin-bottom:6px">RISK LEVEL</label>
        <select name="risk_level" class="form-select" style="width:100%">
          <?php foreach ($riskLevels as $rl): ?>
          <option value="<?= e($rl) ?>"><?= e($rl) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" name="add_keyword" class="btn-admin-primary" style="margin-top:4px">+ Add Keywords</button>
    </div>
  </div>
</form>

<table class="admin-table">
  <thead><tr><th>Keyword</th><th>Risk Type</th><th>Risk Level</th><th>Status</th><th>Actions</th></tr></thead>
  <tbody>
  <?php foreach ($keywords as $k): ?>
  <tr class="<?= $k['active']?'':'row-disabled' ?>">
    <td><code><?= e($k['keyword']) ?></code></td>
    <td><?= e($k['risk_type']) ?></td>
    <td><span class="badge badge-<?= strtolower($k['risk_level']) ?>"><?= e($k['risk_level']) ?></span></td>
    <td><?= $k['active']?'<span class="status-on">Active</span>':'<span class="status-off">Inactive</span>' ?></td>
    <td>
      <form method="POST" style="display:inline"><input type="hidden" name="id" value="<?= $k['id'] ?>"><button name="toggle_keyword" class="btn-sm"><?= $k['active']?'Disable':'Enable' ?></button></form>
      <form method="POST" style="display:inline" onsubmit="return confirm('Delete?')"><input type="hidden" name="id" value="<?= $k['id'] ?>"><button name="delete_keyword" class="btn-sm btn-danger">Delete</button></form>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<?php
// ==============================================
// RISK TYPES
// ==============================================
elseif ($section === 'risk_types'):
    $riskTypes = $db->query("SELECT * FROM risk_types ORDER BY name")->fetchAll();
?>
<h1 class="admin-h1">Manage Risk Types</h1>
<div class="info-box" style="margin-bottom:16px">Add custom risk categories that will appear in keyword dropdowns and dashboard filters.</div>
<form method="POST" class="admin-form">
  <div class="form-row">
    <input name="name" placeholder="Risk Type Name *" required class="form-input">
    <div style="display:flex;align-items:center;gap:8px">
      <label style="font-size:0.72rem;color:var(--text-dim)">Color:</label>
      <input name="color" type="color" value="#8b5cf6" style="width:48px;height:34px;border:1px solid var(--border2);background:var(--surface2);cursor:pointer;padding:2px;">
    </div>
    <button type="submit" name="add_risk_type" class="btn-admin-primary">+ Add Risk Type</button>
  </div>
</form>
<table class="admin-table">
  <thead><tr><th>Name</th><th>Color</th><th>Status</th><th>Actions</th></tr></thead>
  <tbody>
  <?php foreach ($riskTypes as $rt): ?>
  <tr class="<?= $rt['active']?'':'row-disabled' ?>">
    <td><strong><?= e($rt['name']) ?></strong></td>
    <td><span style="display:inline-block;width:20px;height:20px;background:<?= e($rt['color']) ?>;border-radius:2px;vertical-align:middle"></span> <code style="font-size:0.7rem"><?= e($rt['color']) ?></code></td>
    <td><?= $rt['active']?'<span class="status-on">Active</span>':'<span class="status-off">Inactive</span>' ?></td>
    <td>
      <form method="POST" style="display:inline"><input type="hidden" name="id" value="<?= $rt['id'] ?>"><button name="toggle_risk_type" class="btn-sm"><?= $rt['active']?'Disable':'Enable' ?></button></form>
      <form method="POST" style="display:inline" onsubmit="return confirm('Delete risk type?')"><input type="hidden" name="id" value="<?= $rt['id'] ?>"><button name="delete_risk_type" class="btn-sm btn-danger">Delete</button></form>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<?php
// ==============================================
// RISK LEVELS
// ==============================================
elseif ($section === 'risk_levels'):
    $riskLevels = $db->query("SELECT * FROM risk_levels ORDER BY sort_order")->fetchAll();
?>
<h1 class="admin-h1">Manage Risk Levels</h1>
<div class="info-box" style="margin-bottom:16px">Add custom risk levels (e.g. Critical, Informational). Sort order controls the display sequence — lower number = higher priority.</div>
<form method="POST" class="admin-form">
  <div class="form-row">
    <input name="name" placeholder="Level Name *" required class="form-input">
    <div style="display:flex;align-items:center;gap:8px">
      <label style="font-size:0.72rem;color:var(--text-dim)">Color:</label>
      <input name="color" type="color" value="#ef4444" style="width:48px;height:34px;border:1px solid var(--border2);background:var(--surface2);cursor:pointer;padding:2px;">
    </div>
    <input name="sort_order" type="number" placeholder="Sort order" value="5" class="form-input" style="width:100px">
    <button type="submit" name="add_risk_level" class="btn-admin-primary">+ Add Level</button>
  </div>
</form>
<table class="admin-table">
  <thead><tr><th>Name</th><th>Color</th><th>Sort Order</th><th>Status</th><th>Actions</th></tr></thead>
  <tbody>
  <?php foreach ($riskLevels as $rl): ?>
  <tr class="<?= $rl['active']?'':'row-disabled' ?>">
    <td><span class="badge" style="background:<?= e($rl['color']) ?>20;color:<?= e($rl['color']) ?>;border:1px solid <?= e($rl['color']) ?>"><?= e($rl['name']) ?></span></td>
    <td><span style="display:inline-block;width:20px;height:20px;background:<?= e($rl['color']) ?>;border-radius:2px;vertical-align:middle"></span> <code style="font-size:0.7rem"><?= e($rl['color']) ?></code></td>
    <td><?= (int)$rl['sort_order'] ?></td>
    <td><?= $rl['active']?'<span class="status-on">Active</span>':'<span class="status-off">Inactive</span>' ?></td>
    <td>
      <form method="POST" style="display:inline"><input type="hidden" name="id" value="<?= $rl['id'] ?>"><button name="toggle_risk_level" class="btn-sm"><?= $rl['active']?'Disable':'Enable' ?></button></form>
      <form method="POST" style="display:inline" onsubmit="return confirm('Delete risk level?')"><input type="hidden" name="id" value="<?= $rl['id'] ?>"><button name="delete_risk_level" class="btn-sm btn-danger">Delete</button></form>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<?php
// ==============================================
// SOURCES
// ==============================================
elseif ($section === 'sources'):
    $sources = $db->query("SELECT * FROM sources ORDER BY name")->fetchAll();
?>
<h1 class="admin-h1">Manage Sources</h1>
<form method="POST" class="admin-form">
  <div class="form-row">
    <input name="name" placeholder="Source Name *" required class="form-input" style="flex:1">
    <select name="type" class="form-select"><option>RSS</option><option>API</option><option>Scrape</option></select>
    <input name="fetch_interval" type="number" placeholder="Interval (min)" value="60" class="form-input" style="width:80px">
  </div>
  <div class="form-row">
    <input name="url" placeholder="Feed URL *" required class="form-input" style="flex:2">
    <input name="notes" placeholder="Notes" class="form-input" style="flex:1">
    <button type="submit" name="add_source" class="btn-admin-primary">+ Add Source</button>
  </div>
</form>
<table class="admin-table">
  <thead><tr><th>Name</th><th>Type</th><th>URL</th><th>Interval</th><th>Last Fetched</th><th>Status</th><th>Actions</th></tr></thead>
  <tbody>
  <?php foreach ($sources as $s): ?>
  <tr class="<?= $s['active']?'':'row-disabled' ?>">
    <td><?= e($s['name']) ?></td>
    <td><code><?= e($s['type']) ?></code></td>
    <td><a href="<?= e($s['url']) ?>" target="_blank" class="table-link"><?= e(substr($s['url'],0,50)) ?>…</a></td>
    <td><?= (int)$s['fetch_interval'] ?> min</td>
    <td><?= $s['last_fetched']?e($s['last_fetched']):'<em>Never</em>' ?></td>
    <td><?= $s['active']?'<span class="status-on">Active</span>':'<span class="status-off">Inactive</span>' ?></td>
    <td>
      <form method="POST" style="display:inline"><input type="hidden" name="id" value="<?= $s['id'] ?>"><button name="toggle_source" class="btn-sm"><?= $s['active']?'Disable':'Enable' ?></button></form>
      <form method="POST" style="display:inline" onsubmit="return confirm('Delete?')"><input type="hidden" name="id" value="<?= $s['id'] ?>"><button name="delete_source" class="btn-sm btn-danger">Delete</button></form>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<?php
// ==============================================
// ALERTS LOG
// ==============================================
elseif ($section === 'alerts'):
    $showDismissed = isset($_GET['dismissed']);
    $alerts = $db->query("SELECT * FROM alerts WHERE dismissed=".($showDismissed?'1':'0')." ORDER BY alert_date DESC LIMIT 200")->fetchAll();
?>
<h1 class="admin-h1">Alerts Log <?= $showDismissed?'(Dismissed)':'' ?></h1>
<div style="margin-bottom:12px">
  <a href="?section=alerts" class="btn-sm <?= !$showDismissed?'active':'' ?>">Active</a>
  <a href="?section=alerts&dismissed=1" class="btn-sm <?= $showDismissed?'active':'' ?>">Dismissed</a>
  <a href="../api.php?action=export&format=csv" class="btn-sm">Export CSV</a>
  <a href="../api.php?action=export&format=json" class="btn-sm">Export JSON</a>
</div>
<table class="admin-table">
  <thead><tr><th>Risk</th><th>Type</th><th>Title</th><th>Supplier</th><th>Source</th><th>Date</th><th>Link</th></tr></thead>
  <tbody>
  <?php foreach ($alerts as $a): ?>
  <tr>
    <td><span class="badge badge-<?= strtolower($a['risk_level']) ?>"><?= e($a['risk_level']) ?></span></td>
    <td><?= e($a['risk_type']) ?></td>
    <td><small><?= e(substr($a['title'],0,80)) ?></small></td>
    <td><?= e($a['supplier_name']) ?></td>
    <td><small><?= e($a['source']) ?></small></td>
    <td><small><?= e(substr($a['alert_date'],0,16)) ?></small></td>
    <td><?= $a['link']?'<a href="'.e($a['link']).'" target="_blank" class="table-link">↗</a>':'' ?></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<?php
// ==============================================
// CRON LOG
// ==============================================
elseif ($section === 'cron'):
    $logs = $db->query("SELECT * FROM cron_log ORDER BY run_at DESC LIMIT 100")->fetchAll();
?>
<h1 class="admin-h1">Cron Execution Log</h1>
<div class="info-box" style="margin-bottom:16px"><strong>Manual run:</strong> <code>php <?= e(dirname(__DIR__)) ?>/run_cron.php</code></div>
<table class="admin-table">
  <thead><tr><th>Time</th><th>Source</th><th>Items</th><th>Alerts</th><th>Duration</th><th>Errors</th></tr></thead>
  <tbody>
  <?php foreach ($logs as $l): ?>
  <tr>
    <td><small><?= e($l['run_at']) ?></small></td>
    <td><?= e($l['source_name']) ?></td>
    <td><?= (int)$l['items_fetched'] ?></td>
    <td><?= (int)$l['alerts_created'] ?></td>
    <td><?= number_format($l['duration_ms']/1000,2) ?>s</td>
    <td><small style="color:var(--high)"><?= e($l['errors']) ?></small></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<?php
// ==============================================
// SETTINGS
// ==============================================
elseif ($section === 'settings'):
?>
<h1 class="admin-h1">Settings</h1>
<form method="POST" class="admin-form settings-form" style="max-width:600px">
  <div class="settings-group">
    <h3>Dashboard</h3>
    <label>Dashboard Title<input name="dashboard_title" value="<?= e(getSetting('dashboard_title','OSINT Supplier Risk Dashboard')) ?>" class="form-input"></label>
    <label>Alert Retention (days)<input name="alert_retention_days" type="number" value="<?= e(getSetting('alert_retention_days','90')) ?>" class="form-input" style="width:100px"></label>
  </div>
  <div class="settings-group">
    <h3>Email Notifications</h3>
    <label style="flex-direction:row;align-items:center;gap:8px"><input type="checkbox" name="email_notifications" value="1" <?= getSetting('email_notifications')==='1'?'checked':'' ?>> Enable email notifications</label>
    <label>Notification Email<input name="notification_email" type="email" value="<?= e(getSetting('notification_email')) ?>" class="form-input"></label>
    <label>Min Risk Level
      <select name="min_risk_notify" class="form-select">
        <?php foreach (getRiskLevels() as $l): ?>
        <option <?= getSetting('min_risk_notify','High')===$l?'selected':'' ?>><?= e($l) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>SMTP Host<input name="smtp_host" value="<?= e(getSetting('smtp_host')) ?>" class="form-input" placeholder="smtp.gmail.com"></label>
    <label>SMTP Port<input name="smtp_port" type="number" value="<?= e(getSetting('smtp_port','587')) ?>" class="form-input" style="width:100px"></label>
    <label>SMTP Username<input name="smtp_user" value="<?= e(getSetting('smtp_user')) ?>" class="form-input"></label>
  </div>
  <button type="submit" name="save_settings" class="btn-admin-primary">💾 Save Settings</button>
</form>

<hr style="border:none;border-top:1px solid var(--border);margin:32px 0">
<h2 style="font-family:var(--font-mono);font-size:0.78rem;letter-spacing:0.12em;color:var(--high);text-transform:uppercase;margin-bottom:20px">🔐 Change Admin Password</h2>

<form method="POST" class="admin-form settings-form" style="max-width:400px">
  <div class="settings-group">
    <label>Current Password
      <input type="password" name="current_password" class="form-input" placeholder="Enter current password" required>
    </label>
    <label>New Password
      <input type="password" name="new_password" class="form-input" placeholder="Min. 6 characters" required>
    </label>
    <label>Confirm New Password
      <input type="password" name="confirm_password" class="form-input" placeholder="Repeat new password" required>
    </label>
  </div>
  <button type="submit" name="change_admin_password" class="btn-admin-primary" style="background:var(--high)">🔐 Change Password</button>
</form>
<?php endif; ?>

</main>
</div>

<footer style="max-width:1600px;margin:0 auto;padding:16px 24px;text-align:center;font-family:var(--font-mono);font-size:0.68rem;color:var(--text-dim)">
  OSINT Dashboard Admin v<?= APP_VERSION ?> · IP: <?= e($_SESSION['admin_ip']??'') ?>
</footer>
</body>
</html>