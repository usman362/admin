<?php
/**
 * auth.php — User Authentication (Login / Register / Logout)
 * Register → Pending Approval → Admin approves → Login allowed
 */
require_once __DIR__ . '/config.php';
session_start();

// Already logged in → go to dashboard
if (!empty($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$action  = $_GET['action'] ?? 'login';
$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db = getDB();

    // ── REGISTER ──────────────────────────────
    if ($action === 'register') {
        $name     = trim($_POST['name'] ?? '');
        $email    = strtolower(trim($_POST['email'] ?? ''));
        $password = $_POST['password'] ?? '';
        $confirm  = $_POST['confirm'] ?? '';

        if (empty($name) || empty($email) || empty($password)) {
            $error = 'All fields are required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } elseif (strlen($password) < 6) {
            $error = 'Password must be at least 6 characters.';
        } elseif ($password !== $confirm) {
            $error = 'Passwords do not match.';
        } else {
            $stmt = $db->prepare('SELECT id FROM users WHERE email = ?');
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $error = 'An account with this email already exists.';
            } else {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                // active = 0 → pending approval
                $stmt = $db->prepare('INSERT INTO users (name, email, password, role, active) VALUES (?, ?, ?, "user", 0)');
                $stmt->execute([$name, $email, $hash]);
                $success = 'pending';
                $action  = 'pending';
            }
        }
    }

    // ── LOGIN ─────────────────────────────────
    if ($action === 'login') {
        $email    = strtolower(trim($_POST['email'] ?? ''));
        $password = $_POST['password'] ?? '';

        if (empty($email) || empty($password)) {
            $error = 'Email and password are required.';
        } else {
            $stmt = $db->prepare('SELECT * FROM users WHERE email = ?');
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                // Check approval status
                if ((int)$user['active'] === 0) {
                    $error = 'Your account is pending admin approval. Please wait.';
                } elseif ((int)$user['active'] === 2) {
                    $error = 'Your account has been rejected. Please contact the administrator.';
                } else {
                    $_SESSION['user_id']   = $user['id'];
                    $_SESSION['user_name'] = $user['name'];
                    $_SESSION['user_role'] = $user['role'];
                    $db->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')->execute([$user['id']]);
                    header('Location: index.php');
                    exit;
                }
            } else {
                sleep(1);
                $error = 'Invalid email or password.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $action === 'register' ? 'Create Account' : ($action === 'pending' ? 'Registration Submitted' : 'Login') ?> — OSINT Dashboard</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;600&family=IBM+Plex+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
:root {
  --bg:#0a0c10;--surface:#111318;--surface2:#1a1d24;
  --border:#252830;--border2:#2e3140;
  --text:#c8cdd8;--text-dim:#5a6070;--text-bright:#e8ecf4;
  --accent:#00d4ff;--accent-dim:#0099bb;
  --high:#ff3b5c;--low:#22c55e;--yellow:#f59e0b;
  --font-mono:'IBM Plex Mono',monospace;
  --font-sans:'IBM Plex Sans',sans-serif;
}
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--text);font-family:var(--font-sans);min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:24px;}
body::before{content:'';position:fixed;inset:0;background:repeating-linear-gradient(0deg,transparent,transparent 2px,rgba(0,0,0,0.05) 2px,rgba(0,0,0,0.05) 4px);pointer-events:none;z-index:0;}
.auth-wrap{position:relative;z-index:1;width:100%;max-width:420px;}
.auth-logo{text-align:center;margin-bottom:32px;}
.auth-logo-icon{font-size:2rem;color:var(--accent);filter:drop-shadow(0 0 10px var(--accent));display:block;margin-bottom:8px;}
.auth-logo-text{font-family:var(--font-mono);font-size:0.75rem;letter-spacing:0.15em;color:var(--text-dim);text-transform:uppercase;}
.auth-box{background:var(--surface);border:1px solid var(--border);padding:36px;}
.auth-title{font-family:var(--font-mono);font-size:0.8rem;letter-spacing:0.12em;color:var(--accent);text-transform:uppercase;margin-bottom:28px;padding-bottom:14px;border-bottom:1px solid var(--border);}
.form-group{margin-bottom:18px;}
.form-label{display:block;font-family:var(--font-mono);font-size:0.65rem;letter-spacing:0.1em;color:var(--text-dim);margin-bottom:7px;text-transform:uppercase;}
.form-input{width:100%;background:var(--surface2);border:1px solid var(--border2);color:var(--text);font-family:var(--font-sans);font-size:0.85rem;padding:10px 14px;outline:none;transition:border-color 0.15s;border-radius:2px;}
.form-input:focus{border-color:var(--accent-dim);}
.btn-primary{width:100%;background:var(--accent);color:#000;border:none;font-family:var(--font-mono);font-size:0.78rem;font-weight:600;letter-spacing:0.1em;padding:12px;cursor:pointer;border-radius:2px;transition:opacity 0.15s;margin-top:6px;}
.btn-primary:hover{opacity:0.85;}
.auth-error{background:rgba(255,59,92,0.1);border:1px solid rgba(255,59,92,0.3);color:var(--high);font-size:0.75rem;padding:10px 14px;margin-bottom:18px;border-radius:2px;}
.auth-success{background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.3);color:var(--low);font-size:0.75rem;padding:10px 14px;margin-bottom:18px;border-radius:2px;}
.auth-pending{background:rgba(245,158,11,0.1);border:1px solid rgba(245,158,11,0.3);color:var(--yellow);font-size:0.75rem;padding:16px;margin-bottom:18px;border-radius:2px;text-align:center;line-height:1.7;}
.auth-switch{text-align:center;margin-top:20px;font-size:0.78rem;color:var(--text-dim);}
.auth-switch a{color:var(--accent);text-decoration:none;}
.auth-switch a:hover{text-decoration:underline;}
.pending-icon{font-size:2.5rem;display:block;margin-bottom:12px;}
</style>
</head>
<body>
<div class="auth-wrap">
  <div class="auth-logo">
    <span class="auth-logo-icon">⬡</span>
    <span class="auth-logo-text">OSINT Supplier Risk Dashboard</span>
  </div>

  <div class="auth-box">

    <?php if ($action === 'pending'): ?>
      <!-- PENDING APPROVAL PAGE -->
      <div class="auth-title">Registration Submitted</div>
      <div style="text-align:center;padding:20px 0">
        <span class="pending-icon">⏳</span>
        <p style="font-size:0.9rem;color:var(--text-bright);font-weight:500;margin-bottom:12px">Your account is pending approval</p>
        <p style="font-size:0.8rem;color:var(--text-dim);line-height:1.7">Your registration request has been submitted. An administrator will review and approve your account shortly. You will then be able to log in.</p>
      </div>
      <div class="auth-switch">
        Already approved? <a href="auth.php?action=login">Sign In</a>
      </div>

    <?php elseif ($action === 'register'): ?>
      <!-- REGISTER FORM -->
      <div class="auth-title">Create Account</div>
      <?php if ($error): ?><div class="auth-error"><?= e($error) ?></div><?php endif; ?>
      <form method="POST">
        <div class="form-group">
          <label class="form-label">Full Name</label>
          <input type="text" name="name" class="form-input" placeholder="John Smith" value="<?= e($_POST['name'] ?? '') ?>" required autofocus>
        </div>
        <div class="form-group">
          <label class="form-label">Email Address</label>
          <input type="email" name="email" class="form-input" placeholder="you@company.com" value="<?= e($_POST['email'] ?? '') ?>" required>
        </div>
        <div class="form-group">
          <label class="form-label">Password</label>
          <input type="password" name="password" class="form-input" placeholder="Min. 6 characters" required>
        </div>
        <div class="form-group">
          <label class="form-label">Confirm Password</label>
          <input type="password" name="confirm" class="form-input" placeholder="Repeat password" required>
        </div>
        <button type="submit" class="btn-primary">SUBMIT REGISTRATION</button>
      </form>
      <div class="auth-switch">
        Already have an account? <a href="auth.php?action=login">Sign In</a>
      </div>

    <?php else: ?>
      <!-- LOGIN FORM -->
      <div class="auth-title">Sign In</div>
      <?php if ($error): ?><div class="auth-error"><?= e($error) ?></div><?php endif; ?>
      <?php if ($success && $success !== 'pending'): ?><div class="auth-success"><?= e($success) ?></div><?php endif; ?>
      <form method="POST">
        <div class="form-group">
          <label class="form-label">Email Address</label>
          <input type="email" name="email" class="form-input" placeholder="you@company.com" required autofocus>
        </div>
        <div class="form-group">
          <label class="form-label">Password</label>
          <input type="password" name="password" class="form-input" placeholder="Your password" required>
        </div>
        <button type="submit" class="btn-primary">SIGN IN</button>
      </form>
      <div class="auth-switch">
        Don't have an account? <a href="auth.php?action=register">Register</a>
      </div>
    <?php endif; ?>

  </div>
</div>
</body>
</html>