<?php
require_once 'includes/config.php';
requireRole('admin');

$error   = '';
$success = '';
$action  = $_GET['action'] ?? 'list';
$id      = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// ── TOGGLE ACTIVE ─────────────────────────────────────────────────────────────
if ($action === 'toggle' && $id) {
    $user = mysqli_fetch_assoc(mysqli_query($conn, "SELECT is_active, username FROM user WHERE user_id = $id"));
    if ($user && $user['username'] !== 'admin') {
        $new = $user['is_active'] ? 0 : 1;
        mysqli_query($conn, "UPDATE user SET is_active = $new WHERE user_id = $id");
    }
    header("Location: users.php?success=toggled"); exit();
}

// ── DELETE ────────────────────────────────────────────────────────────────────
if ($action === 'delete' && $id) {
    $user = mysqli_fetch_assoc(mysqli_query($conn, "SELECT username FROM user WHERE user_id = $id"));
    if ($user && $user['username'] !== 'admin') {
        mysqli_query($conn, "DELETE FROM user WHERE user_id = $id");
    }
    header("Location: users.php?success=deleted"); exit();
}

// ── RESET PASSWORD ────────────────────────────────────────────────────────────
if ($action === 'reset_password' && $id && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_pw = $_POST['new_password'] ?? '';
    if (strlen($new_pw) < 6) {
        $error = 'Password must be at least 6 characters.';
    } else {
        $hashed = password_hash($new_pw, PASSWORD_BCRYPT);
        $stmt = mysqli_prepare($conn, "UPDATE user SET `password` = ? WHERE user_id = ?");
        mysqli_stmt_bind_param($stmt, 'si', $hashed, $id);
        mysqli_stmt_execute($stmt);
        header("Location: users.php?success=password_reset"); exit();
    }
}

// ── ADD USER ──────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_user'])) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $role     = $_POST['role'] ?? 'patient';
    $link_id  = !empty($_POST['ref_id']) ? (int)$_POST['ref_id'] : null;

    if (empty($username) || empty($password)) {
        $error = 'Username and password are required.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } else {
        $check = mysqli_prepare($conn, "SELECT user_id FROM user WHERE username = ?");
        mysqli_stmt_bind_param($check, 's', $username);
        mysqli_stmt_execute($check);
        mysqli_stmt_store_result($check);
        if (mysqli_stmt_num_rows($check) > 0) {
            $error = 'Username already taken.';
        } else {
            $hashed = password_hash($password, PASSWORD_BCRYPT);
            $stmt = mysqli_prepare($conn, "INSERT INTO user (username, `password`, role, is_active) VALUES (?, ?, ?, 1)");
            mysqli_stmt_bind_param($stmt, 'sss', $username, $hashed, $role);
            mysqli_stmt_execute($stmt);
            $new_user_id = mysqli_insert_id($conn);

            if ($link_id && $role === 'patient') {
                mysqli_query($conn, "UPDATE patient SET user_id = $new_user_id WHERE patient_id = $link_id");
            } elseif ($link_id && $role === 'doctor') {
                mysqli_query($conn, "UPDATE doctor SET user_id = $new_user_id WHERE doctor_id = $link_id");
            }

            header("Location: users.php?success=added"); exit();
        }
    }
}

// ── FETCH ─────────────────────────────────────────────────────────────────────
$all_users_result = mysqli_query($conn, "SELECT * FROM user ORDER BY role, username");
$all_users        = mysqli_fetch_all($all_users_result, MYSQLI_ASSOC);

$admins_list   = array_values(array_filter($all_users, fn($u) => $u['role'] === 'admin'));
$doctors_list  = array_values(array_filter($all_users, fn($u) => $u['role'] === 'doctor'));
$patients_list = array_values(array_filter($all_users, fn($u) => $u['role'] === 'patient'));

$patients_ref = mysqli_query($conn, "SELECT patient_id as ref_id, name FROM patient ORDER BY name");
$doctors_ref  = mysqli_query($conn, "SELECT doctor_id as ref_id, name FROM doctor ORDER BY name");

$reset_user = null;
if ($action === 'reset_password' && $id) {
    $reset_user = mysqli_fetch_assoc(mysqli_query($conn, "SELECT user_id, username FROM user WHERE user_id = $id"));
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Users — IS Class 2 Healthcare</title>
<link rel="stylesheet" href="includes/theme.css">
<style>
.role-section { margin-bottom: 24px; }
.role-section-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 14px 20px; background: var(--surface2);
    border-bottom: 1.5px solid var(--border);
    border-radius: var(--radius-md) var(--radius-md) 0 0;
}
.role-section-title {
    display: flex; align-items: center; gap: 10px;
    font-size: 13px; font-weight: 700; color: var(--text);
}
.role-count {
    font-size: 11px; color: var(--text-muted); background: var(--surface);
    border: 1px solid var(--border); border-radius: 20px;
    padding: 2px 10px; font-weight: 600;
}
.role-box {
    border: 1.5px solid var(--border); border-radius: var(--radius-md);
    overflow: hidden;
}
.user-row {
    display: flex; align-items: center; gap: 14px;
    padding: 13px 20px; border-bottom: 1px solid var(--border);
    transition: background .15s;
}
.user-row:last-child { border: none; }
.user-row:hover { background: var(--surface2); }
.user-avatar {
    width: 36px; height: 36px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0; border: 1.5px solid;
}
.avatar-admin   { background: var(--accent-bg); border-color: var(--accent); color: var(--accent-text); }
.avatar-doctor  { background: rgba(59,130,246,0.12); border-color: rgba(59,130,246,0.4); color: #93c5fd; }
.avatar-patient { background: rgba(52,211,153,0.10); border-color: rgba(52,211,153,0.4); color: #6ee7b7; }
.user-name { font-size: 13px; font-weight: 700; color: var(--text); }
.user-meta { font-size: 11px; color: var(--text-muted); margin-top: 2px; }
.status-dot { width: 7px; height: 7px; border-radius: 50%; flex-shrink: 0; }
.status-dot.active   { background: var(--success); }
.status-dot.inactive { background: var(--danger); }
.user-actions { display: flex; gap: 6px; margin-left: auto; flex-shrink: 0; flex-wrap: wrap; justify-content: flex-end; }
.admin-solo {
    padding: 20px; display: flex; align-items: center; gap: 16px;
}
.admin-badge {
    display: inline-flex; align-items: center; gap: 6px;
    font-size: 11px; font-weight: 700; padding: 4px 12px;
    border-radius: 20px; background: var(--accent-bg);
    color: var(--accent-text); border: 1px solid var(--accent);
    text-transform: uppercase; letter-spacing: .05em;
}

.blob{position:fixed;border-radius:50%;pointer-events:none;z-index:0;filter:blur(70px);opacity:0.45;animation:blobFloat 9s ease-in-out infinite;}
.blob-1{width:500px;height:500px;background:radial-gradient(circle,rgba(249,168,212,0.18) 0%,transparent 70%);top:-150px;right:-100px;animation-delay:0s;}
.blob-2{width:380px;height:380px;background:radial-gradient(circle,rgba(147,197,253,0.13) 0%,transparent 70%);bottom:-100px;left:-80px;animation-delay:-3.5s;}
.blob-3{width:260px;height:260px;background:radial-gradient(circle,rgba(192,130,255,0.1) 0%,transparent 70%);top:35%;left:40%;animation-delay:-6s;}
@keyframes blobFloat{0%,100%{transform:translateY(0) scale(1);}33%{transform:translateY(-22px) scale(1.04);}66%{transform:translateY(14px) scale(0.97);}}
#bg-particles{position:fixed;inset:0;z-index:0;pointer-events:none;}
</style>
</head>
<body>
<canvas id="bg-particles"></canvas>
<div class="blob blob-1"></div>
<div class="blob blob-2"></div>
<div class="blob blob-3"></div>

<div class="app-layout">
    <?php include 'includes/sidebar.php'; ?>
    <main class="main-content">

    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success">
            <?php $msgs=['added'=>'User added!','deleted'=>'User deleted!','toggled'=>'User status updated!','password_reset'=>'Password reset successfully!']; echo $msgs[$_GET['success']] ?? 'Done!'; ?>
        </div>
    <?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <?php if ($action === 'list' || $action === 'add'): ?>

        <div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
            <div>
                <h2><?= icon('users') ?> User Management</h2>
                <p class="page-sub">Manage system accounts and access</p>
            </div>
            <button onclick="document.getElementById('add-form').classList.toggle('hidden')" class="btn btn-primary"><?= icon('users', 14) ?> Add User</button>
        </div>

        <!-- Stats -->
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:24px;">
            <div class="stat-card">
                <div class="stat-label"><?= icon('users', 13) ?> Admins</div>
                <div class="stat-val"><?= count($admins_list) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label"><?= icon('doctors', 13) ?> Doctors</div>
                <div class="stat-val"><?= count($doctors_list) ?></div>
            </div>
            <div class="stat-card gold">
                <div class="stat-label"><?= icon('patients', 13) ?> Patients</div>
                <div class="stat-val"><?= count($patients_list) ?></div>
            </div>
        </div>

        <!-- Add user form -->
        <div id="add-form" class="panel <?= ($action !== 'add' && !$error) ? 'hidden' : '' ?>" style="margin-bottom:24px;">
            <div class="panel-header"><div class="panel-title"><?= icon('users', 14) ?> Add New User</div></div>
            <div style="padding:24px;">
                <form method="POST">
                    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;">
                        <div class="form-group">
                            <label class="form-label">Username *</label>
                            <input type="text" name="username" class="form-control" placeholder="Username" required minlength="4">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Password *</label>
                            <input type="password" name="password" class="form-control" placeholder="Min 6 chars" required minlength="6">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Role *</label>
                            <select name="role" class="form-control" id="role-select" onchange="updateRefOptions()">
                                <option value="patient">Patient</option>
                                <option value="doctor">Doctor</option>
                                <option value="admin">Admin</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group" id="ref-group">
                        <label class="form-label">Link to Patient / Doctor record</label>
                        <select name="ref_id" class="form-control" id="ref-select">
                            <option value="">Select...</option>
                            <optgroup label="Patients" id="patient-opts">
                                <?php foreach (mysqli_fetch_all($patients_ref, MYSQLI_ASSOC) as $p): ?>
                                <option value="<?= $p['ref_id'] ?>" data-type="patient"><?= htmlspecialchars($p['name']) ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                            <optgroup label="Doctors" id="doctor-opts" style="display:none">
                                <?php foreach (mysqli_fetch_all($doctors_ref, MYSQLI_ASSOC) as $d): ?>
                                <option value="<?= $d['ref_id'] ?>" data-type="doctor"><?= htmlspecialchars($d['name']) ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        </select>
                    </div>
                    <button type="submit" name="save_user" class="btn btn-primary"><?= icon('users', 14) ?> Add User</button>
                </form>
            </div>
        </div>

        <!-- ═══ ADMIN SECTION ═══ -->
        <div class="role-section">
            <div class="role-box">
                <div class="role-section-header">
                    <div class="role-section-title">
                        <?= icon('users', 16) ?>
                        Administrators
                    </div>
                    <span class="role-count"><?= count($admins_list) ?> account<?= count($admins_list) != 1 ? 's':'' ?></span>
                </div>
                <?php if (count($admins_list) > 0): ?>
                    <?php foreach ($admins_list as $u): ?>
                    <div class="user-row">
                        <div class="user-avatar avatar-admin"><?= icon('users', 16) ?></div>
                        <div style="flex:1;">
                            <div class="user-name"><?= htmlspecialchars($u['username']) ?></div>
                            <div class="user-meta">ID #<?= $u['user_id'] ?> · Full system access</div>
                        </div>
                        <div class="status-dot <?= $u['is_active'] ? 'active' : 'inactive' ?>" title="<?= $u['is_active'] ? 'Active' : 'Inactive' ?>"></div>
                        <div class="user-actions">
                            <a href="users.php?action=reset_password&id=<?= $u['user_id'] ?>" class="btn btn-outline btn-sm"><?= icon('records', 13) ?> Reset PW</a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">No admin accounts.</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ═══ DOCTORS SECTION ═══ -->
        <div class="role-section">
            <div class="role-box">
                <div class="role-section-header">
                    <div class="role-section-title">
                        <?= icon('doctors', 16) ?>
                        Doctors
                    </div>
                    <span class="role-count"><?= count($doctors_list) ?> account<?= count($doctors_list) != 1 ? 's':'' ?></span>
                </div>
                <?php if (count($doctors_list) > 0): ?>
                    <?php foreach ($doctors_list as $u): ?>
                    <div class="user-row">
                        <div class="user-avatar avatar-doctor"><?= icon('doctors', 16) ?></div>
                        <div style="flex:1;">
                            <div class="user-name"><?= htmlspecialchars($u['username']) ?></div>
                            <div class="user-meta">ID #<?= $u['user_id'] ?> · Doctor account</div>
                        </div>
                        <div class="status-dot <?= $u['is_active'] ? 'active' : 'inactive' ?>" title="<?= $u['is_active'] ? 'Active' : 'Inactive' ?>"></div>
                        <div class="user-actions">
                            <a href="users.php?action=reset_password&id=<?= $u['user_id'] ?>" class="btn btn-outline btn-sm"><?= icon('records', 13) ?> Reset PW</a>
                            <a href="users.php?action=toggle&id=<?= $u['user_id'] ?>"
                               class="btn btn-outline btn-sm"
                               onclick="return confirm('<?= $u['is_active'] ? 'Deactivate' : 'Activate' ?> this user?')">
                               <?= $u['is_active'] ? icon('logout', 13).' Deactivate' : icon('users', 13).' Activate' ?>
                            </a>
                            <a href="users.php?action=delete&id=<?= $u['user_id'] ?>"
                               class="btn btn-danger btn-sm"
                               onclick="return confirm('Delete user <?= htmlspecialchars($u['username']) ?>?')"><?= icon('logout', 13) ?></a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">No doctor accounts yet.</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ═══ PATIENTS SECTION ═══ -->
        <div class="role-section">
            <div class="role-box">
                <div class="role-section-header">
                    <div class="role-section-title">
                        <?= icon('patients', 16) ?>
                        Patients
                    </div>
                    <span class="role-count"><?= count($patients_list) ?> account<?= count($patients_list) != 1 ? 's':'' ?></span>
                </div>
                <?php if (count($patients_list) > 0): ?>
                    <?php foreach ($patients_list as $u): ?>
                    <div class="user-row">
                        <div class="user-avatar avatar-patient"><?= icon('patients', 16) ?></div>
                        <div style="flex:1;">
                            <div class="user-name"><?= htmlspecialchars($u['username']) ?></div>
                            <div class="user-meta">ID #<?= $u['user_id'] ?> · Patient account</div>
                        </div>
                        <div class="status-dot <?= $u['is_active'] ? 'active' : 'inactive' ?>" title="<?= $u['is_active'] ? 'Active' : 'Inactive' ?>"></div>
                        <div class="user-actions">
                            <a href="users.php?action=reset_password&id=<?= $u['user_id'] ?>" class="btn btn-outline btn-sm"><?= icon('records', 13) ?> Reset PW</a>
                            <a href="users.php?action=toggle&id=<?= $u['user_id'] ?>"
                               class="btn btn-outline btn-sm"
                               onclick="return confirm('<?= $u['is_active'] ? 'Deactivate' : 'Activate' ?> this user?')">
                               <?= $u['is_active'] ? icon('logout', 13).' Deactivate' : icon('users', 13).' Activate' ?>
                            </a>
                            <a href="users.php?action=delete&id=<?= $u['user_id'] ?>"
                               class="btn btn-danger btn-sm"
                               onclick="return confirm('Delete user <?= htmlspecialchars($u['username']) ?>?')"><?= icon('logout', 13) ?></a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">No patient accounts yet.</div>
                <?php endif; ?>
            </div>
        </div>

    <?php elseif ($action === 'reset_password' && $reset_user): ?>

        <div class="page-header">
            <h2><?= icon('records', 20) ?> Reset Password</h2>
            <p class="page-sub">Reset password for <strong><?= htmlspecialchars($reset_user['username']) ?></strong></p>
        </div>

        <div class="panel" style="max-width:420px;">
            <div class="panel-header"><div class="panel-title"><?= icon('records', 14) ?> New Password</div></div>
            <div style="padding:24px;">
                <form method="POST">
                    <div class="form-group">
                        <label class="form-label">New Password</label>
                        <input type="password" name="new_password" class="form-control"
                            placeholder="Min 6 characters" required minlength="6" autofocus>
                    </div>
                    <div style="display:flex;gap:10px;">
                        <button type="submit" class="btn btn-primary"><?= icon('records', 14) ?> Reset Password</button>
                        <a href="users.php" class="btn btn-outline">Cancel</a>
                    </div>
                </form>
            </div>
        </div>

    <?php endif; ?>
    </main>
</div>

<style>.hidden { display: none !important; }</style>
<script>
function updateRefOptions() {
    const role = document.getElementById('role-select').value;
    const refGroup = document.getElementById('ref-group');
    const patOpts = document.getElementById('patient-opts');
    const docOpts = document.getElementById('doctor-opts');
    if (role === 'admin') {
        refGroup.style.display = 'none';
    } else {
        refGroup.style.display = 'block';
        patOpts.style.display = role === 'patient' ? '' : 'none';
        docOpts.style.display = role === 'doctor'  ? '' : 'none';
    }
}
updateRefOptions();
</script>
<script src="includes/interactions.js"></script>
<script>
(function(){
var c=document.getElementById("bg-particles");
if(!c)return;
var ctx=c.getContext("2d"),W,H,dots=[];
function resize(){W=c.width=window.innerWidth;H=c.height=window.innerHeight;}
resize();window.addEventListener("resize",resize);
for(var i=0;i<55;i++){dots.push({x:Math.random()*W,y:Math.random()*H,r:Math.random()*1.8+0.4,dx:(Math.random()-.5)*.3,dy:(Math.random()-.5)*.3,o:Math.random()*.35+0.08});}
function draw(){ctx.clearRect(0,0,W,H);dots.forEach(function(d){ctx.beginPath();ctx.arc(d.x,d.y,d.r,0,Math.PI*2);ctx.fillStyle="rgba(249,168,212,"+d.o+")";ctx.fill();d.x+=d.dx;d.y+=d.dy;if(d.x<0||d.x>W)d.dx*=-1;if(d.y<0||d.y>H)d.dy*=-1;});requestAnimationFrame(draw);}
draw();
})();
</script>
</body>
</html>
