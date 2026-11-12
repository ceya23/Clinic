<?php
require_once 'includes/config.php';
if (isset($_SESSION['user_id'])) { header("Location: dashboard.php"); exit(); }

$step    = 1; // 1=enter username, 2=answer question, 3=new password
$error   = '';
$success = '';
$user    = null;

// Step 1 → find user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['step']) && $_POST['step'] == '1') {
    $username = trim($_POST['username'] ?? '');
    if (empty($username)) {
        $error = 'Please enter your username.';
        $step  = 1;
    } else {
        $stmt = mysqli_prepare($conn, "SELECT user_id, username, role, security_question, security_answer FROM user WHERE username = ? AND is_active = 1");
        mysqli_stmt_bind_param($stmt, 's', $username);
        mysqli_stmt_execute($stmt);
        $user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        if (!$user || empty($user['security_question'])) {
            $error = 'Username not found or no security question set.';
            $step  = 1;
        } else {
            $step = 2;
        }
    }
}

// Step 2 → verify answer
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['step']) && $_POST['step'] == '2') {
    $user_id = (int)($_POST['user_id'] ?? 0);
    $answer  = strtolower(trim($_POST['security_answer'] ?? ''));
    $stmt = mysqli_prepare($conn, "SELECT user_id, username, security_question, security_answer FROM user WHERE user_id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $user_id);
    mysqli_stmt_execute($stmt);
    $user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    if (!$user || !password_verify($answer, $user['security_answer'])) {
        $error = 'Incorrect answer. Please try again.';
        $step  = 2;
    } else {
        $step = 3;
    }
}

// Step 3 → reset password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['step']) && $_POST['step'] == '3') {
    $user_id  = (int)($_POST['user_id'] ?? 0);
    $new_pw   = $_POST['new_password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';
    if (strlen($new_pw) < 6) {
        $error = 'Password must be at least 6 characters.';
        $step  = 3;
        $user  = ['user_id' => $user_id];
    } elseif ($new_pw !== $confirm) {
        $error = 'Passwords do not match.';
        $step  = 3;
        $user  = ['user_id' => $user_id];
    } else {
        $hashed = password_hash($new_pw, PASSWORD_BCRYPT);
        $stmt = mysqli_prepare($conn, "UPDATE user SET password = ? WHERE user_id = ?");
        mysqli_stmt_bind_param($stmt, 'si', $hashed, $user_id);
        mysqli_stmt_execute($stmt);
        $success = 'Password reset successfully! You can now sign in.';
        $step    = 4;
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Forgot Password — IS Class 2 Healthcare</title>
<link rel="stylesheet" href="includes/theme.css">
<style>
body {
    display:flex; align-items:center; justify-content:center;
    min-height:100vh; padding:32px 24px; background:var(--bg);
    position:relative; overflow:hidden;
}

.blob { position:fixed; border-radius:50%; pointer-events:none; z-index:0; filter:blur(70px); opacity:0.5; animation:blobFloat 9s ease-in-out infinite; }
.blob-1 { width:380px; height:380px; background:radial-gradient(circle, rgba(249,168,212,0.2) 0%, transparent 70%); top:-110px; right:-90px; animation-delay:0s; }
.blob-2 { width:280px; height:280px; background:radial-gradient(circle, rgba(147,197,253,0.13) 0%, transparent 70%); bottom:-70px; left:-50px; animation-delay:-4s; }
@keyframes blobFloat {
    0%,100% { transform:translateY(0) scale(1) rotate(0deg); }
    40%      { transform:translateY(-18px) scale(1.04) rotate(2deg); }
    70%      { transform:translateY(12px) scale(0.97) rotate(-1deg); }
}
#particles { position:fixed; inset:0; z-index:0; pointer-events:none; }

.fp-wrapper {
    width:100%; max-width:460px;
    background:var(--surface);
    border-radius:22px;
    border:1px solid var(--border);
    box-shadow:0 32px 90px rgba(0,0,0,0.38);
    overflow:hidden;
    position:relative; z-index:1;
    animation:cardEntrance 0.65s cubic-bezier(0.16,1,0.3,1) both;
}
@keyframes cardEntrance {
    from { opacity:0; transform:translateY(36px) scale(0.96); }
    to   { opacity:1; transform:translateY(0) scale(1); }
}

.fp-header {
    background:linear-gradient(135deg, #f9a8d4, #93c5fd);
    padding:28px 36px;
    position:relative; overflow:hidden;
}
.fp-header::before {
    content:''; position:absolute;
    width:220px; height:220px; border-radius:50%;
    background:rgba(0,0,0,0.07);
    bottom:-70px; right:-50px;
    animation:blobFloat 7s ease-in-out infinite;
}
.fp-header::after {
    content:''; position:absolute;
    width:130px; height:130px; border-radius:50%;
    background:rgba(255,255,255,0.08);
    top:-35px; left:-35px;
    animation:blobFloat 8s ease-in-out infinite reverse;
}
.fp-header h2 {
    font-family:'Playfair Display', serif;
    font-size:20px; font-weight:400;
    color:#3b0764; position:relative; z-index:1;
    animation:slideInLeft 0.7s 0.1s cubic-bezier(0.16,1,0.3,1) both;
}
.fp-header p {
    font-size:12px; color:rgba(59,7,100,0.6); margin-top:4px;
    position:relative; z-index:1;
    animation:slideInLeft 0.7s 0.18s cubic-bezier(0.16,1,0.3,1) both;
}
@keyframes slideInLeft {
    from { opacity:0; transform:translateX(-14px); }
    to   { opacity:1; transform:translateX(0); }
}

.fp-body { padding:32px 36px; }

/* Step indicators */
.steps { display:flex; align-items:center; gap:0; margin-bottom:28px; animation:fadeUp 0.5s 0.22s cubic-bezier(0.16,1,0.3,1) both; }
.step-dot {
    width:30px; height:30px; border-radius:50%;
    display:flex; align-items:center; justify-content:center;
    font-size:11px; font-weight:500;
    background:var(--surface2); color:var(--text-muted);
    border:1px solid var(--border); flex-shrink:0;
    transition:all .3s cubic-bezier(0.16,1,0.3,1);
}
.step-dot.active {
    background:linear-gradient(135deg,#f9a8d4,#93c5fd);
    color:#3b0764; border-color:#f9a8d4;
    transform:scale(1.12);
    box-shadow:0 0 0 4px rgba(249,168,212,0.2);
}
.step-dot.done { background:var(--accent-bg); color:var(--accent-text); border-color:var(--accent-bg); }
.step-line { flex:1; height:2px; background:var(--border); transition:background .4s; border-radius:2px; }
.step-line.done { background:linear-gradient(90deg,#f9a8d4,#93c5fd); }

/* Form fields stagger */
.form-group { animation:fadeUp 0.5s cubic-bezier(0.16,1,0.3,1) both; }
.form-group:nth-child(1) { animation-delay:0.28s; }
.form-group:nth-child(2) { animation-delay:0.36s; }
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
    transform:translateY(-1px);
}

/* Question box */
.question-box {
    background:var(--surface2);
    border:1px solid var(--border);
    border-radius:var(--radius-sm);
    padding:12px 16px;
    font-size:13px; color:var(--text);
    margin-bottom:18px; font-style:italic;
    animation:fadeUp 0.5s 0.24s cubic-bezier(0.16,1,0.3,1) both;
}

/* Submit button */
.btn-fp {
    width:100%; justify-content:center; padding:12px;
    background:linear-gradient(135deg,#f9a8d4,#93c5fd);
    color:#3b0764; border:none;
    position:relative; overflow:hidden;
    transition:transform .2s, box-shadow .2s !important;
}
.btn-fp::after {
    content:''; position:absolute; top:0; left:-100%;
    width:60%; height:100%;
    background:linear-gradient(90deg,transparent,rgba(255,255,255,0.25),transparent);
    transform:skewX(-20deg);
    animation:shimmer 2.8s 1s infinite;
}
@keyframes shimmer { 0%{left:-100%} 100%{left:200%} }
.btn-fp:hover { transform:translateY(-2px) !important; box-shadow:0 8px 24px rgba(249,168,212,0.35) !important; }
.btn-fp:active { transform:translateY(0) !important; }

/* Step transition — slide new form in */
.step-content { animation:stepIn 0.45s cubic-bezier(0.16,1,0.3,1) both; }
@keyframes stepIn {
    from { opacity:0; transform:translateX(20px); }
    to   { opacity:1; transform:translateX(0); }
}

.back-link {
    display:inline-flex; align-items:center; gap:6px;
    font-size:12px; color:var(--text-muted); text-decoration:none;
    margin-top:18px; transition:color .15s;
}
.back-link:hover { color:var(--text); }

/* Error shake */
.alert-danger { animation:shake 0.4s cubic-bezier(.36,.07,.19,.97) both; }
@keyframes shake {
    10%,90%{transform:translateX(-2px)}
    20%,80%{transform:translateX(4px)}
    30%,50%,70%{transform:translateX(-5px)}
    40%,60%{transform:translateX(5px)}
}
/* Success bounce */
.alert-success { animation:bounceIn 0.5s cubic-bezier(0.16,1,0.3,1) both; }
@keyframes bounceIn {
    from{opacity:0;transform:scale(0.92) translateY(-8px)}
    to{opacity:1;transform:scale(1) translateY(0)}
}

.theme-btn {
    position:fixed; top:20px; right:20px;
    display:flex; align-items:center; gap:8px;
    font-size:11px; color:var(--text-muted); z-index:10;
}
</style>
</head>
<body>

<canvas id="particles"></canvas>
<div class="blob blob-1"></div>
<div class="blob blob-2"></div>

<div class="theme-btn">
    <span id="tl-label">🌙</span>
    <div class="toggle-switch" id="tl-toggle" onclick="toggleTheme()">
        <div class="toggle-knob"></div>
    </div>
</div>

<div class="fp-wrapper">
    <div class="fp-header">
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:8px;position:relative;z-index:1;">
            <div style="width:46px;height:46px;border-radius:50%;background:rgba(255,255,255,0.35);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
            </div>
            <div>
                <h2 style="margin:0;line-height:1;">IS Class 2</h2>
                <div style="font-size:10px;color:rgba(59,7,100,0.6);letter-spacing:2px;text-transform:uppercase;font-weight:500;margin-top:2px;">Healthcare</div>
            </div>
        </div>
        <p style="position:relative;z-index:1;">Answer your security question to reset your password</p>
    </div>

    <div class="fp-body">

        <!-- Step indicators -->
        <?php if ($step < 4): ?>
        <div class="steps">
            <div class="step-dot <?= $step >= 1 ? ($step > 1 ? 'done' : 'active') : '' ?>">1</div>
            <div class="step-line <?= $step > 1 ? 'done' : '' ?>"></div>
            <div class="step-dot <?= $step >= 2 ? ($step > 2 ? 'done' : 'active') : '' ?>">2</div>
            <div class="step-line <?= $step > 2 ? 'done' : '' ?>"></div>
            <div class="step-dot <?= $step >= 3 ? 'active' : '' ?>">3</div>
        </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($step === 4): ?>
            <!-- Success -->
            <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
            <a href="login.php" class="btn btn-primary btn-fp" style="display:flex;">
                Go to Sign In →
            </a>

        <?php elseif ($step === 1): ?>
            <div class="step-content">
            <p style="font-size:13px;color:var(--text-muted);margin-bottom:20px;">Enter your username and we'll ask your security question.</p>
            <form method="POST">
                <input type="hidden" name="step" value="1">
                <div class="form-group">
                    <label class="form-label">Username</label>
                    <input type="text" name="username" class="form-control"
                        placeholder="Enter your username"
                        value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                        required autofocus>
                </div>
                <button type="submit" class="btn btn-primary btn-fp">
                    Continue →
                </button>
            </form>
            </div>

        <?php elseif ($step === 2): ?>
            <div class="step-content">
            <p style="font-size:13px;color:var(--text-muted);margin-bottom:16px;">Answer the question below to verify your identity.</p>
            <div class="question-box">❓ <?= htmlspecialchars($user['security_question']) ?></div>
            <form method="POST">
                <input type="hidden" name="step" value="2">
                <input type="hidden" name="user_id" value="<?= $user['user_id'] ?>">
                <div class="form-group">
                    <label class="form-label">Your Answer</label>
                    <input type="text" name="security_answer" class="form-control"
                        placeholder="Type your answer (case-insensitive)" required autofocus>
                </div>
                <button type="submit" class="btn btn-primary btn-fp">
                    Verify →
                </button>
            </form>
            </div>

        <?php elseif ($step === 3): ?>
            <div class="step-content">
            <p style="font-size:13px;color:var(--text-muted);margin-bottom:20px;">Great! Now set your new password.</p>
            <form method="POST">
                <input type="hidden" name="step" value="3">
                <input type="hidden" name="user_id" value="<?= $user['user_id'] ?>">
                <div class="form-group">
                    <label class="form-label">New Password</label>
                    <div style="position:relative;">
                        <input type="password" name="new_password" id="pw-new" class="form-control"
                            placeholder="Min 6 characters" required minlength="6" autofocus style="padding-right:44px;">
                        <button type="button" onclick="togglePw('pw-new',this)" style="position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#93c5fd;font-size:16px;padding:0;line-height:1;">👁</button>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Confirm New Password</label>
                    <div style="position:relative;">
                        <input type="password" name="confirm_password" id="pw-confirm" class="form-control"
                            placeholder="Re-enter new password" required style="padding-right:44px;">
                        <button type="button" onclick="togglePw('pw-confirm',this)" style="position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#93c5fd;font-size:16px;padding:0;line-height:1;">👁</button>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary btn-fp">
                    Reset Password →
                </button>
            </form>
            </div>
        <?php endif; ?>

        <?php if ($step < 4): ?>
        <div style="text-align:center;margin-top:20px;">
            <a href="login.php" class="back-link">← Back to Sign In</a>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
(function(){
    const saved=localStorage.getItem('clinic-theme')||'dark';
    applyTheme(saved);
    function applyTheme(theme){
        document.documentElement.setAttribute('data-theme',theme);
        const tog=document.getElementById('tl-toggle');
        const lbl=document.getElementById('tl-label');
        if(!tog)return;
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

// Particles
(function(){
    const canvas=document.getElementById('particles');
    if(!canvas)return;
    const ctx=canvas.getContext('2d');
    let W,H,dots=[];
    function resize(){W=canvas.width=window.innerWidth;H=canvas.height=window.innerHeight;}
    resize(); window.addEventListener('resize',resize);
    for(let i=0;i<40;i++) dots.push({x:Math.random()*W,y:Math.random()*H,r:Math.random()*1.5+0.3,dx:(Math.random()-.5)*.28,dy:(Math.random()-.5)*.28,o:Math.random()*.3+0.08});
    function draw(){ctx.clearRect(0,0,W,H);dots.forEach(d=>{ctx.beginPath();ctx.arc(d.x,d.y,d.r,0,Math.PI*2);ctx.fillStyle=`rgba(249,168,212,${d.o})`;ctx.fill();d.x+=d.dx;d.y+=d.dy;if(d.x<0||d.x>W)d.dx*=-1;if(d.y<0||d.y>H)d.dy*=-1;});requestAnimationFrame(draw);}
    draw();
})();
</script>
<script src="includes/interactions.js"></script>
</body>
</html>
