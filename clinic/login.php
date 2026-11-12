<?php
require_once 'includes/config.php';
if (isset($_SESSION['user_id'])) { header("Location: dashboard.php"); exit(); }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    if (empty($username) || empty($password)) {
        $error = 'Please fill in all fields.';
    } else {
        $stmt = mysqli_prepare($conn, "SELECT user_id, username, password, role FROM user WHERE username = ? AND is_active = 1");
        mysqli_stmt_bind_param($stmt, 's', $username);
        mysqli_stmt_execute($stmt);
        $user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id']  = $user['user_id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role']     = $user['role'];

            // Pre-cache the patient/doctor ID using the new FK approach
            if ($user['role'] === 'patient') {
                $r = mysqli_fetch_assoc(mysqli_query($conn, "SELECT patient_id FROM patient WHERE user_id = {$user['user_id']}"));
                $_SESSION['patient_ref_id'] = $r['patient_id'] ?? null;
            } elseif ($user['role'] === 'doctor') {
                $r = mysqli_fetch_assoc(mysqli_query($conn, "SELECT doctor_id FROM doctor WHERE user_id = {$user['user_id']}"));
                $_SESSION['doctor_ref_id'] = $r['doctor_id'] ?? null;
            }

            header("Location: dashboard.php"); exit();
        } else {
            $error = 'Invalid username or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sign In — IS Class 2 Healthcare</title>
<link rel="stylesheet" href="includes/theme.css">
<style>
body {
    display:flex; align-items:center; justify-content:center;
    min-height:100vh; padding:24px; background:var(--bg);
    position:relative; overflow:hidden;
}

/* Blobs */
.blob {
    position:fixed; border-radius:50%; pointer-events:none; z-index:0;
    filter:blur(70px); opacity:0.55;
    animation:blobFloat 9s ease-in-out infinite;
}
.blob-1 { width:420px; height:420px; background:radial-gradient(circle, rgba(249,168,212,0.22) 0%, transparent 70%); top:-120px; right:-90px; animation-delay:0s; }
.blob-2 { width:320px; height:320px; background:radial-gradient(circle, rgba(147,197,253,0.15) 0%, transparent 70%); bottom:-90px; left:-70px; animation-delay:-3.5s; }
.blob-3 { width:220px; height:220px; background:radial-gradient(circle, rgba(192,130,255,0.12) 0%, transparent 70%); top:40%; left:28%; animation-delay:-6s; }

@keyframes blobFloat {
    0%,100% { transform:translateY(0px) scale(1) rotate(0deg); }
    33%      { transform:translateY(-22px) scale(1.04) rotate(2deg); }
    66%      { transform:translateY(14px) scale(0.97) rotate(-1deg); }
}

#particles { position:fixed; inset:0; z-index:0; pointer-events:none; }

/* Card */
.login-wrapper {
    display:flex; width:100%; max-width:920px; min-height:560px;
    border-radius:22px; overflow:hidden;
    border:1px solid var(--border);
    box-shadow:0 32px 90px rgba(0,0,0,0.45);
    position:relative; z-index:1;
    animation:cardEntrance 0.65s cubic-bezier(0.16,1,0.3,1) both;
}
@keyframes cardEntrance {
    from { opacity:0; transform:translateY(32px) scale(0.96); }
    to   { opacity:1; transform:translateY(0) scale(1); }
}

/* Left panel */
.login-left {
    width:44%; background:linear-gradient(135deg, #f9a8d4, #93c5fd);
    padding:48px 44px; display:flex;
    flex-direction:column; justify-content:space-between;
    position:relative; overflow:hidden;
}
.login-left::before {
    content:''; position:absolute;
    width:380px; height:380px; border-radius:50%;
    background:rgba(0,0,0,0.08);
    bottom:-130px; right:-110px;
    animation:blobFloat 7s ease-in-out infinite;
}
.login-left::after {
    content:''; position:absolute;
    width:220px; height:220px; border-radius:50%;
    background:rgba(255,255,255,0.1);
    top:-70px; left:-70px;
    animation:blobFloat 8s ease-in-out infinite reverse;
}
.left-top { position:relative; z-index:1; }
.left-title {
    font-family:'Playfair Display', serif;
    font-size:30px; font-weight:400;
    color:#3b0764; line-height:1.2; margin-bottom:14px;
    animation:slideInLeft 0.7s 0.1s cubic-bezier(0.16,1,0.3,1) both;
}
.left-desc {
    font-size:13px; color:rgba(59,7,100,0.65); line-height:1.7;
    animation:slideInLeft 0.7s 0.18s cubic-bezier(0.16,1,0.3,1) both;
}
@keyframes slideInLeft {
    from { opacity:0; transform:translateX(-20px); }
    to   { opacity:1; transform:translateX(0); }
}

.left-features { position:relative; z-index:1; }
.feature-item {
    display:flex; align-items:center; gap:10px;
    font-size:12px; color:rgba(59,7,100,0.7); margin-bottom:10px;
    animation:featureSlide 0.55s cubic-bezier(0.16,1,0.3,1) both;
}
.feature-item:nth-child(1) { animation-delay:0.25s; }
.feature-item:nth-child(2) { animation-delay:0.35s; }
.feature-item:nth-child(3) { animation-delay:0.45s; }
.feature-item:nth-child(4) { animation-delay:0.55s; }
.feature-item:nth-child(5) { animation-delay:0.65s; }
@keyframes featureSlide {
    from { opacity:0; transform:translateX(-16px); }
    to   { opacity:1; transform:translateX(0); }
}
.feature-item .f-icon {
    width:30px; height:30px; background:rgba(59,7,100,0.12);
    border-radius:9px; display:flex; align-items:center;
    justify-content:center; font-size:14px; flex-shrink:0;
    transition:transform .2s;
}
.feature-item:hover .f-icon { transform:scale(1.15) rotate(-4deg); }
.left-footer { position:relative; z-index:1; font-size:11px; color:rgba(59,7,100,0.4); }

/* Right panel */
.login-right {
    flex:1; background:var(--surface);
    padding:52px 48px; display:flex;
    flex-direction:column; justify-content:center;
}
.login-right h2 {
    font-family:'Playfair Display', serif;
    font-size:26px; font-weight:400;
    color:var(--text); margin-bottom:6px;
    animation:fadeUp 0.55s 0.2s cubic-bezier(0.16,1,0.3,1) both;
}
.login-right .subtitle {
    font-size:13px; color:var(--text-muted); margin-bottom:32px;
    animation:fadeUp 0.55s 0.28s cubic-bezier(0.16,1,0.3,1) both;
}
.form-group {
    animation:fadeUp 0.55s cubic-bezier(0.16,1,0.3,1) both;
}
.form-group:nth-child(1) { animation-delay:0.32s; }
.form-group:nth-child(2) { animation-delay:0.38s; }
@keyframes fadeUp {
    from { opacity:0; transform:translateY(14px); }
    to   { opacity:1; transform:translateY(0); }
}

/* Input interactions */
.form-control {
    transition: border-color .2s, box-shadow .2s, transform .15s !important;
}
.form-control:focus {
    box-shadow:0 0 0 3px rgba(249,168,212,0.2), 0 0 0 1px rgba(249,168,212,0.5) !important;
    transform: translateY(-1px);
}

/* Submit button shimmer */
.btn-login {
    width:100%; justify-content:center; padding:13px;
    background:linear-gradient(135deg,#f9a8d4,#93c5fd);
    color:#3b0764; border:none;
    position:relative; overflow:hidden;
    transition:transform .2s, box-shadow .2s !important;
    animation:fadeUp 0.55s 0.44s cubic-bezier(0.16,1,0.3,1) both;
}
.btn-login::after {
    content:'';
    position:absolute; top:0; left:-100%;
    width:60%; height:100%;
    background:linear-gradient(90deg, transparent, rgba(255,255,255,0.25), transparent);
    transform:skewX(-20deg);
    animation:shimmer 2.5s 1.2s infinite;
}
@keyframes shimmer {
    0%   { left:-100%; }
    100% { left:200%; }
}
.btn-login:hover {
    transform:translateY(-2px) !important;
    box-shadow:0 8px 24px rgba(249,168,212,0.35) !important;
}
.btn-login:active { transform:translateY(0px) !important; }

.forgot-link {
    text-align:right; margin-top:-10px; margin-bottom:20px;
    font-size:12px;
    animation:fadeUp 0.55s 0.4s cubic-bezier(0.16,1,0.3,1) both;
}
.forgot-link a { color:#c026d3; text-decoration:none; transition:color .15s; }
.forgot-link a:hover { color:#f9a8d4; }

.register-prompt {
    text-align:center; margin-top:20px;
    font-size:12px; color:var(--text-muted);
    animation:fadeUp 0.55s 0.5s cubic-bezier(0.16,1,0.3,1) both;
}
.register-prompt a { color:#c026d3; text-decoration:none; font-weight:500; transition:color .15s; }
.register-prompt a:hover { color:#93c5fd; }

.divider-or {
    display:flex; align-items:center; gap:12px;
    margin:20px 0; color:var(--text-faint); font-size:11px;
    animation:fadeUp 0.55s 0.46s cubic-bezier(0.16,1,0.3,1) both;
}
.divider-or::before, .divider-or::after {
    content:''; flex:1; height:1px; background:var(--border);
}

.theme-btn {
    position:absolute; top:20px; right:20px;
    display:flex; align-items:center; gap:8px;
    font-size:11px; color:var(--text-muted); z-index:10;
}

/* Error shake */
.alert-danger { animation: shake 0.4s cubic-bezier(.36,.07,.19,.97) both; }
@keyframes shake {
    10%,90% { transform:translateX(-2px); }
    20%,80% { transform:translateX(4px); }
    30%,50%,70% { transform:translateX(-5px); }
    40%,60% { transform:translateX(5px); }
}

@media(max-width:640px){
    .login-left{display:none;}
    .login-right{padding:36px 28px;}
}
</style>
</head>
<body>

<canvas id="particles"></canvas>
<div class="blob blob-1"></div>
<div class="blob blob-2"></div>
<div class="blob blob-3"></div>

<div class="theme-btn">
    <span id="tl-label">🌙</span>
    <div class="toggle-switch" id="tl-toggle" onclick="toggleTheme()">
        <div class="toggle-knob"></div>
    </div>
</div>

<div class="login-wrapper">
    <div class="login-left">
        <div class="left-top">
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:4px;position:relative;z-index:1;">
                <div style="width:52px;height:52px;border-radius:50%;background:linear-gradient(135deg,#f9a8d4,#93c5fd);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
                </div>
                <div>
                    <div class="left-title" style="margin-bottom:2px;">IS Class 2</div>
                    <div style="font-size:11px;color:rgba(59,7,100,0.6);letter-spacing:2px;text-transform:uppercase;font-weight:500;">Healthcare</div>
                </div>
            </div>
            <p class="left-desc">An integrated clinic platform — manage patients, doctors, medical records, and payments in one place.</p>
        </div>
        <div class="left-features">
            <div class="feature-item"><div class="f-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></div> Patient & Doctor Management</div>
            <div class="feature-item"><div class="f-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div> Online Appointment Booking</div>
            <div class="feature-item"><div class="f-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg></div> Digital Medical Records</div>
            <div class="feature-item"><div class="f-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v4l3 3"/></svg></div> Medication Stock Management</div>
            <div class="feature-item"><div class="f-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg></div> Payment Reports</div>
        </div>
        <div class="left-footer">IS Class 2 Healthcare &copy; <?= date('Y') ?></div>
    </div>

    <div class="login-right">
        <h2>Welcome back 👋</h2>
        <p class="subtitle">Sign in to your account to continue</p>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label class="form-label">Username</label>
                <input type="text" name="username" class="form-control"
                    placeholder="Enter your username"
                    value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                    required autofocus>
            </div>
            <div class="form-group">
                <label class="form-label">Password</label>
                <div style="position:relative;">
                    <input type="password" name="password" id="pw-login" class="form-control"
                        placeholder="Enter your password" required style="padding-right:44px;">
                    <button type="button" onclick="togglePw('pw-login',this)" style="position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#93c5fd;font-size:16px;padding:0;line-height:1;">👁</button>
                </div>
            </div>
            <div class="forgot-link">
                <a href="forgot_password.php">Forgot password?</a>
            </div>
            <button type="submit" class="btn btn-primary btn-login">
                Sign In →
            </button>
        </form>

        <div class="divider-or">or</div>

        <div class="register-prompt">
            Do not have an account? <a href="register.php">Create an account</a>
        </div>
    </div>
</div>

<script>
(function(){
    const saved = localStorage.getItem('clinic-theme') || 'dark';
    applyTheme(saved);
    function applyTheme(theme){
        document.documentElement.setAttribute('data-theme', theme);
        const tog = document.getElementById('tl-toggle');
        const lbl = document.getElementById('tl-label');
        if(!tog) return;
        if(theme==='light'){tog.classList.add('on');lbl.textContent='☀️';}
        else{tog.classList.remove('on');lbl.textContent='🌙';}
    }
    window.toggleTheme=function(){
        const cur=document.documentElement.getAttribute('data-theme')||'dark';
        const next=cur==='dark'?'light':'dark';
        localStorage.setItem('clinic-theme',next);
        applyTheme(next);
    };
})();
function togglePw(id,btn){const i=document.getElementById(id);if(i.type==='password'){i.type='text';btn.textContent='🙈';}else{i.type='password';btn.textContent='👁';}}
</script>
<script>
// Particles
(function(){
    const canvas = document.getElementById('particles');
    const ctx = canvas.getContext('2d');
    let W, H, dots = [];
    function resize(){ W = canvas.width = window.innerWidth; H = canvas.height = window.innerHeight; }
    resize();
    window.addEventListener('resize', resize);
    for(let i=0;i<55;i++){
        dots.push({
            x: Math.random()*window.innerWidth,
            y: Math.random()*window.innerHeight,
            r: Math.random()*1.8+0.4,
            dx: (Math.random()-0.5)*0.3,
            dy: (Math.random()-0.5)*0.3,
            o: Math.random()*0.4+0.1
        });
    }
    function draw(){
        ctx.clearRect(0,0,W,H);
        dots.forEach(d=>{
            ctx.beginPath();
            ctx.arc(d.x,d.y,d.r,0,Math.PI*2);
            ctx.fillStyle=`rgba(249,168,212,${d.o})`;
            ctx.fill();
            d.x+=d.dx; d.y+=d.dy;
            if(d.x<0||d.x>W) d.dx*=-1;
            if(d.y<0||d.y>H) d.dy*=-1;
        });
        requestAnimationFrame(draw);
    }
    draw();
})();
</script>
<script src="includes/interactions.js"></script>
</body>
</html>