<?php
require_once 'includes/config.php';
requireLogin();

$error   = '';
$success = '';
$user_id = (int)$_SESSION['user_id'];

// ── Fetch current user ────────────────────────────────────────────────────────
// FIX #1: Use prepared statement — no raw $user_id in query
$stmt_u = mysqli_prepare($conn, "SELECT * FROM user WHERE user_id = ?");
mysqli_stmt_bind_param($stmt_u, 'i', $user_id);
mysqli_stmt_execute($stmt_u);
$user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_u));

// Fetch linked profile (patient / doctor)
$profile = null;
if (isPatient()) {
    $stmt_p = mysqli_prepare($conn, "SELECT * FROM patient WHERE user_id = ?");
    mysqli_stmt_bind_param($stmt_p, 'i', $user_id);
    mysqli_stmt_execute($stmt_p);
    $profile = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_p));
} elseif (isDoctor()) {
    $stmt_d = mysqli_prepare($conn, "SELECT * FROM doctor WHERE user_id = ?");
    mysqli_stmt_bind_param($stmt_d, 'i', $user_id);
    mysqli_stmt_execute($stmt_d);
    $profile = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_d));
}

// ── SAVE PROFILE INFO ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_profile'])) {
    $username = trim($_POST['username'] ?? '');
    $name     = trim($_POST['name']     ?? '');
    $phone    = trim($_POST['phone']    ?? '');

    if (empty($username)) {
        $error = 'Username is required.';
    } else {
        // Check username uniqueness (excluding self)
        $dup = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT user_id FROM user WHERE username='".mysqli_real_escape_string($conn,$username)."' AND user_id != $user_id"));
        if ($dup) {
            $error = 'Username already taken.';
        } else {
            // Update username in user table
            $stmt = mysqli_prepare($conn, "UPDATE user SET username=? WHERE user_id=?");
            mysqli_stmt_bind_param($stmt, 'si', $username, $user_id);
            mysqli_stmt_execute($stmt);
            $_SESSION['username'] = $username;

            // Update linked profile
            if (isPatient() && $profile) {
                $gender  = $_POST['gender']  ?? '';
                $dob     = $_POST['dob']     ?? null;
                $address = trim($_POST['address'] ?? '');
                $dob_val = !empty($dob) ? $dob : null;
                $stmt2 = mysqli_prepare($conn,
                    "UPDATE patient SET name=?, phone=?, gender=?, date_of_birth=?, address=? WHERE user_id=?");
                mysqli_stmt_bind_param($stmt2, 'sssssi', $name, $phone, $gender, $dob_val, $address, $user_id);
                mysqli_stmt_execute($stmt2);
            } elseif (isDoctor() && $profile) {
                $stmt2 = mysqli_prepare($conn,
                    "UPDATE doctor SET name=?, phone=? WHERE user_id=?");
                mysqli_stmt_bind_param($stmt2, 'ssi', $name, $phone, $user_id);
                mysqli_stmt_execute($stmt2);
            } elseif (isAdmin()) {
                // Admin has no separate profile table — username only
            }

            $success = 'Profile updated successfully.';
            // Refresh data — FIX #1: use prepared statements
            $stmt_r = mysqli_prepare($conn, "SELECT * FROM user WHERE user_id = ?");
            mysqli_stmt_bind_param($stmt_r, 'i', $user_id);
            mysqli_stmt_execute($stmt_r);
            $user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_r));
            if (isPatient()) {
                $stmt_rp = mysqli_prepare($conn, "SELECT * FROM patient WHERE user_id = ?");
                mysqli_stmt_bind_param($stmt_rp, 'i', $user_id);
                mysqli_stmt_execute($stmt_rp);
                $profile = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_rp));
            }
            if (isDoctor()) {
                $stmt_rd = mysqli_prepare($conn, "SELECT * FROM doctor WHERE user_id = ?");
                mysqli_stmt_bind_param($stmt_rd, 'i', $user_id);
                mysqli_stmt_execute($stmt_rd);
                $profile = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_rd));
            }
        }
    }
}

// ── CHANGE PASSWORD ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current = $_POST['current_password'] ?? '';
    $new     = $_POST['new_password']     ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (!password_verify($current, $user['password'])) {
        $error = 'Current password is incorrect.';
    } elseif (strlen($new) < 6) {
        $error = 'New password must be at least 6 characters.';
    } elseif ($new !== $confirm) {
        $error = 'New passwords do not match.';
    } else {
        $hashed = password_hash($new, PASSWORD_DEFAULT);
        $stmt = mysqli_prepare($conn, "UPDATE user SET password=? WHERE user_id=?");
        mysqli_stmt_bind_param($stmt, 'si', $hashed, $user_id);
        mysqli_stmt_execute($stmt);
        $success = 'Password changed successfully.';
    }
}

// ── DISPLAY NAME for header ───────────────────────────────────────────────────
$display_name = $profile['name'] ?? $user['username'];
$display_role = ucfirst($user['role']);
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Profile — IS Class 2 Healthcare</title>
<link rel="stylesheet" href="includes/theme.css">
<style>
.profile-card {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: var(--radius-lg); padding: 28px 32px;
    margin-bottom: 20px;
}
.profile-card h3 {
    font-size: 14px; font-weight: 700; color: var(--text-muted);
    text-transform: uppercase; letter-spacing: .06em;
    margin-bottom: 20px; padding-bottom: 12px;
    border-bottom: 1px solid var(--border);
}
.form-group { margin-bottom: 16px; }
.form-group label {
    display: block; font-size: 11px; font-weight: 700;
    color: var(--text-muted); margin-bottom: 6px;
    text-transform: uppercase; letter-spacing: .05em;
}
.form-group input, .form-group select, .form-group textarea {
    width: 100%; padding: 10px 14px;
    background: var(--surface2); border: 1.5px solid var(--border);
    border-radius: var(--radius-sm); color: var(--text);
    font-size: 13px; font-family: 'Nunito', sans-serif;
    transition: border-color .15s;
}
.form-group input:focus, .form-group select:focus, .form-group textarea:focus {
    outline: none; border-color: var(--accent);
}
.form-group textarea { resize: vertical; min-height: 80px; }
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.btn { padding: 10px 24px; border: none; border-radius: var(--radius-sm);
       font-size: 13px; font-weight: 700; cursor: pointer; transition: all .15s; }
.btn-primary { background: var(--accent); color: #fff; }
.btn-primary:hover { background: var(--accent-h); }
.alert { padding: 12px 16px; border-radius: var(--radius-sm); margin-bottom: 18px; font-size: 13px; font-weight: 600; }
.alert-success { background: rgba(16,185,129,0.12); color: var(--success); border: 1px solid rgba(16,185,129,0.25); }
.alert-danger  { background: rgba(244,63,94,0.10);  color: var(--danger);  border: 1px solid rgba(244,63,94,0.2); }
.avatar-circle {
    width: 72px; height: 72px; border-radius: 50%;
    background: linear-gradient(135deg, var(--accent), var(--accent2));
    display: flex; align-items: center; justify-content: center;
    font-size: 28px; font-weight: 700; color: #fff;
    flex-shrink: 0;
}
.role-badge {
    display: inline-block; padding: 4px 12px; border-radius: 20px;
    font-size: 11px; font-weight: 700; text-transform: capitalize;
    background: var(--accent-bg); color: var(--accent-text);
}
.readonly-field {
    width: 100%; padding: 10px 14px;
    background: var(--surface2); border: 1.5px solid var(--border);
    border-radius: var(--radius-sm); color: var(--text-muted);
    font-size: 13px; opacity: .7;
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
<main style="margin-left:220px; padding:32px; flex:1; max-width:760px;">

<!-- Header -->
<div style="display:flex; align-items:center; gap:20px; margin-bottom:28px;">
    <div class="avatar-circle"><?= strtoupper(substr($display_name, 0, 1)) ?></div>
    <div>
        <h2 style="font-family:'Playfair Display',serif; font-size:22px; font-weight:400;">
            <?= htmlspecialchars($display_name) ?>
        </h2>
        <div style="margin-top:6px; display:flex; gap:8px; align-items:center;">
            <span class="role-badge"><?= $display_role ?></span>
            <span style="font-size:12px; color:var(--text-muted);">@<?= htmlspecialchars($user['username']) ?></span>
        </div>
    </div>
</div>

<?php if ($success): ?>
<div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- PROFILE INFO FORM -->
<div class="profile-card">
    <h3>Profile Information</h3>
    <form method="POST">
        <div class="form-group">
            <label>Username</label>
            <input type="text" name="username" value="<?= htmlspecialchars($user['username']) ?>" required>
        </div>

        <?php if (!isAdmin()): ?>
        <div class="form-group">
            <label><?= isDoctor() ? 'Doctor' : 'Full' ?> Name</label>
            <input type="text" name="name" value="<?= htmlspecialchars($profile['name'] ?? '') ?>" required>
        </div>
        <div class="form-group">
            <label>Phone</label>
            <input type="text" name="phone" value="<?= htmlspecialchars($profile['phone'] ?? '') ?>">
        </div>
        <?php endif; ?>

        <?php if (isPatient()): ?>
        <div class="form-row">
            <div class="form-group">
                <label>Gender</label>
                <select name="gender">
                    <option value="">— Select —</option>
                    <?php foreach (['Male','Female','Other'] as $g): ?>
                    <option value="<?= $g ?>" <?= ($profile['gender'] ?? '') === $g ? 'selected' : '' ?>><?= $g ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Date of Birth</label>
                <input type="date" name="dob" value="<?= htmlspecialchars($profile['date_of_birth'] ?? '') ?>">
            </div>
        </div>
        <div class="form-group">
            <label>Address</label>
            <textarea name="address"><?= htmlspecialchars($profile['address'] ?? '') ?></textarea>
        </div>
        <?php endif; ?>

        <?php if (isDoctor()): ?>
        <div class="form-group">
            <label>Specialization</label>
            <div class="readonly-field"><?= htmlspecialchars($profile['specialization'] ?? '—') ?></div>
            <small style="font-size:11px; color:var(--text-muted); margin-top:4px; display:block;">Contact admin to change specialization.</small>
        </div>
        <?php endif; ?>

        <div class="form-group">
            <label>Role</label>
            <div class="readonly-field"><?= $display_role ?></div>
        </div>

        <div style="display:flex; justify-content:flex-end; margin-top:8px;">
            <button type="submit" name="save_profile" class="btn btn-primary">Save Changes</button>
        </div>
    </form>
</div>

<!-- CHANGE PASSWORD FORM -->
<div class="profile-card">
    <h3>Change Password</h3>
    <form method="POST">
        <div class="form-group">
            <label>Current Password</label>
            <input type="password" name="current_password" required>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>New Password</label>
                <input type="password" name="new_password" minlength="6" required>
            </div>
            <div class="form-group">
                <label>Confirm New Password</label>
                <input type="password" name="confirm_password" required>
            </div>
        </div>
        <div style="display:flex; justify-content:flex-end; margin-top:8px;">
            <button type="submit" name="change_password" class="btn btn-primary">Change Password</button>
        </div>
    </form>
</div>

<!-- Account Info -->
<div class="profile-card" style="padding:20px 32px;">
    <h3>Account Info</h3>
    <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px;">
        <div>
            <div style="font-size:11px; color:var(--text-muted); text-transform:uppercase; letter-spacing:.05em;">Member since</div>
            <div style="font-size:14px; margin-top:4px;"><?= date('d M Y', strtotime($user['created_at'])) ?></div>
        </div>
        <div>
            <div style="font-size:11px; color:var(--text-muted); text-transform:uppercase; letter-spacing:.05em;">Account status</div>
            <div style="font-size:14px; margin-top:4px; color:<?= $user['is_active'] ? 'var(--success)' : 'var(--danger)' ?>;">
                <?= $user['is_active'] ? '● Active' : '● Inactive' ?>
            </div>
        </div>
    </div>
</div>

</main>
</div>
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
