<?php
require_once 'includes/config.php';
if (isset($_SESSION['user_id'])) { header("Location: dashboard.php"); exit(); }

$error = '';
$success = '';
$security_questions = [
    "What is the name of your first pet?",
    "What city were you born in?",
    "What is your mother's maiden name?",
    "What was the name of your first school?",
    "What is your oldest sibling's middle name?",
    "What was the make of your first car?",
    "What is your favorite movie?",
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name      = trim($_POST['name'] ?? '');
    $username  = trim($_POST['username'] ?? '');
    $password  = $_POST['password'] ?? '';
    $confirm   = $_POST['confirm_password'] ?? '';
    $gender    = $_POST['gender'] ?? '';
    $dob       = $_POST['date_of_birth'] ?? '';
    $phone     = trim($_POST['phone'] ?? '');
    $address   = trim($_POST['address'] ?? '');
    $sq        = $_POST['security_question'] ?? '';
    $sa        = trim($_POST['security_answer'] ?? '');

    // Validation
    if (empty($name) || empty($username) || empty($password) || empty($gender) || empty($sq) || empty($sa)) {
        $error = 'Please fill in all required fields.';
    } elseif (strlen($username) < 4) {
        $error = 'Username must be at least 4 characters.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } elseif (!empty($dob) && ($dob < '1900-01-01' || $dob > date('Y-m-d'))) {
        $error = 'Date of birth must be between 1900 and at least 1 year ago.';
    } else {
        // Check username uniqueness
        $check = mysqli_prepare($conn, "SELECT user_id FROM user WHERE username = ?");
        mysqli_stmt_bind_param($check, 's', $username);
        mysqli_stmt_execute($check);
        mysqli_stmt_store_result($check);
        if (mysqli_stmt_num_rows($check) > 0) {
            $error = 'Username already taken. Please choose another.';
        } else {
            // Insert patient record
            $stmt = mysqli_prepare($conn, "INSERT INTO patient (name, gender, date_of_birth, phone, address) VALUES (?, ?, ?, ?, ?)");
            mysqli_stmt_bind_param($stmt, 'sssss', $name, $gender, $dob, $phone, $address);
            mysqli_stmt_execute($stmt);
            $patient_id = mysqli_insert_id($conn);

            // Insert user record (no ref_id anymore)
            $hashed   = password_hash($password, PASSWORD_BCRYPT);
            // FIX #3: Use password_hash instead of MD5 for security answer
            $hashed_a = password_hash(strtolower($sa), PASSWORD_DEFAULT);
            $stmt2 = mysqli_prepare($conn, "INSERT INTO user (username, password, role, is_active, security_question, security_answer) VALUES (?, ?, 'patient', 1, ?, ?)");
            mysqli_stmt_bind_param($stmt2, 'ssss', $username, $hashed, $sq, $hashed_a);
            mysqli_stmt_execute($stmt2);
            $user_id = mysqli_insert_id($conn);

            // Link user_id back to patient
            $stmt3 = mysqli_prepare($conn, "UPDATE patient SET user_id = ? WHERE patient_id = ?");
            mysqli_stmt_bind_param($stmt3, 'ii', $user_id, $patient_id);
            mysqli_stmt_execute($stmt3);

            $success = 'Account created successfully! You can now sign in.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Register — IS Class 2 Healthcare</title>
<link rel="stylesheet" href="includes/theme.css">
<style>
body {
    display:flex; align-items:center; justify-content:center;
    min-height:100vh; padding:32px 24px; background:var(--bg);
    position:relative; overflow-y:auto;
}

/* Blobs */
.blob { position:fixed; border-radius:50%; pointer-events:none; z-index:0; filter:blur(70px); opacity:0.5; animation:blobFloat 9s ease-in-out infinite; }
.blob-1 { width:400px; height:400px; background:radial-gradient(circle, rgba(249,168,212,0.2) 0%, transparent 70%); top:-120px; right:-100px; animation-delay:0s; }
.blob-2 { width:300px; height:300px; background:radial-gradient(circle, rgba(147,197,253,0.13) 0%, transparent 70%); bottom:-80px; left:-60px; animation-delay:-4s; }
@keyframes blobFloat {
    0%,100% { transform:translateY(0) scale(1) rotate(0deg); }
    40%      { transform:translateY(-18px) scale(1.04) rotate(2deg); }
    70%      { transform:translateY(12px) scale(0.97) rotate(-1deg); }
}
#particles { position:fixed; inset:0; z-index:0; pointer-events:none; }

.register-wrapper {
    width:100%; max-width:620px;
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

.register-header {
    background:linear-gradient(135deg, #f9a8d4, #93c5fd);
    padding:32px 40px;
    position:relative; overflow:hidden;
}
.register-header::before {
    content:''; position:absolute;
    width:260px; height:260px; border-radius:50%;
    background:rgba(0,0,0,0.07);
    bottom:-80px; right:-60px;
    animation:blobFloat 7s ease-in-out infinite;
}
.register-header::after {
    content:''; position:absolute;
    width:150px; height:150px; border-radius:50%;
    background:rgba(255,255,255,0.08);
    top:-40px; left:-40px;
    animation:blobFloat 8s ease-in-out infinite reverse;
}
.register-header h2 {
    font-family:'Playfair Display', serif;
    font-size:22px; font-weight:400; color:#3b0764;
    position:relative; z-index:1;
    animation:slideInLeft 0.7s 0.1s cubic-bezier(0.16,1,0.3,1) both;
}
.register-header p {
    font-size:12px; color:rgba(59,7,100,0.6); margin-top:4px;
    position:relative; z-index:1;
    animation:slideInLeft 0.7s 0.18s cubic-bezier(0.16,1,0.3,1) both;
}
@keyframes slideInLeft {
    from { opacity:0; transform:translateX(-16px); }
    to   { opacity:1; transform:translateX(0); }
}

.register-body { padding:36px 40px; }

/* Staggered form fields */
.form-group {
    animation:fadeUp 0.5s cubic-bezier(0.16,1,0.3,1) both;
}
.form-group:nth-child(1) { animation-delay:0.15s; }
.form-group:nth-child(2) { animation-delay:0.22s; }
.form-group:nth-child(3) { animation-delay:0.29s; }
.form-group:nth-child(4) { animation-delay:0.36s; }
.form-group:nth-child(5) { animation-delay:0.43s; }
.form-group:nth-child(6) { animation-delay:0.50s; }
@keyframes fadeUp {
    from { opacity:0; transform:translateY(14px); }
    to   { opacity:1; transform:translateY(0); }
}

.form-row { display:grid; grid-template-columns:1fr 1fr; gap:16px; }

.section-divider {
    font-size:10px; text-transform:uppercase; letter-spacing:.08em;
    color:var(--text-faint); margin:20px 0 14px;
    display:flex; align-items:center; gap:10px;
    animation:fadeUp 0.5s cubic-bezier(0.16,1,0.3,1) both;
}
.section-divider::after { content:''; flex:1; height:1px; background:var(--border); }

/* Input interactions */
.form-control {
    transition: border-color .2s, box-shadow .2s, transform .15s !important;
}
.form-control:focus {
    box-shadow:0 0 0 3px rgba(249,168,212,0.2), 0 0 0 1px rgba(249,168,212,0.5) !important;
    transform:translateY(-1px);
}

.password-hint { font-size:11px; color:var(--text-muted); margin-top:4px; }
.strength-bar { height:3px; border-radius:2px; background:var(--border); margin-top:6px; overflow:hidden; }
.strength-fill { height:100%; border-radius:2px; transition:width .3s, background .3s; width:0%; }

/* Submit button */
.btn-register {
    width:100%; justify-content:center; padding:13px; margin-top:8px;
    background:linear-gradient(135deg,#f9a8d4,#93c5fd);
    color:#3b0764; border:none;
    position:relative; overflow:hidden;
    transition:transform .2s, box-shadow .2s !important;
    animation:fadeUp 0.5s 0.56s cubic-bezier(0.16,1,0.3,1) both;
}
.btn-register::after {
    content:'';
    position:absolute; top:0; left:-100%;
    width:60%; height:100%;
    background:linear-gradient(90deg, transparent, rgba(255,255,255,0.25), transparent);
    transform:skewX(-20deg);
    animation:shimmer 2.8s 1.4s infinite;
}
@keyframes shimmer {
    0%   { left:-100%; }
    100% { left:200%; }
}
.btn-register:hover { transform:translateY(-2px) !important; box-shadow:0 8px 24px rgba(249,168,212,0.35) !important; }
.btn-register:active { transform:translateY(0) !important; }

.sign-in-link {
    text-align:center; margin-top:20px;
    font-size:12px; color:var(--text-muted);
    animation:fadeUp 0.5s 0.6s cubic-bezier(0.16,1,0.3,1) both;
}
.sign-in-link a { color:#c026d3; text-decoration:none; font-weight:500; transition:color .15s; }
.sign-in-link a:hover { color:#93c5fd; }

/* Error shake */
.alert-danger { animation: shake 0.4s cubic-bezier(.36,.07,.19,.97) both; }
@keyframes shake {
    10%,90% { transform:translateX(-2px); }
    20%,80% { transform:translateX(4px); }
    30%,50%,70% { transform:translateX(-5px); }
    40%,60% { transform:translateX(5px); }
}

/* Success bounce */
.alert-success { animation: bounceIn 0.5s cubic-bezier(0.16,1,0.3,1) both; }
@keyframes bounceIn {
    from { opacity:0; transform:scale(0.92) translateY(-8px); }
    to   { opacity:1; transform:scale(1) translateY(0); }
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

<div class="register-wrapper">
    <div class="register-header">
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:10px;position:relative;z-index:1;">
            <div style="width:48px;height:48px;border-radius:50%;background:rgba(255,255,255,0.35);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
            </div>
            <div>
                <h2 style="margin:0;line-height:1;">IS Class 2</h2>
                <div style="font-size:10px;color:rgba(59,7,100,0.6);letter-spacing:2px;text-transform:uppercase;font-weight:500;margin-top:2px;">Healthcare</div>
            </div>
        </div>
        <p>Register to book appointments and access your medical records</p>
    </div>

    <div class="register-body">
        <?php if ($success): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success) ?> <a href="login.php" style="color:inherit;font-weight:500;">Sign in →</a></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if (!$success): ?>
        <form method="POST">
            <div class="section-divider">Personal Information</div>
            <div class="form-group">
                <label class="form-label">Full Name <span style="color:var(--danger)">*</span></label>
                <input type="text" name="name" class="form-control" placeholder="Enter your full name"
                    value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" required>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Gender <span style="color:var(--danger)">*</span></label>
                    <select name="gender" class="form-control" required>
                        <option value="">Select gender</option>
                        <option value="Male"   <?= (($_POST['gender']??'')==='Male')?'selected':'' ?>>Male</option>
                        <option value="Female" <?= (($_POST['gender']??'')==='Female')?'selected':'' ?>>Female</option>
                        <option value="Other"  <?= (($_POST['gender']??'')==='Other')?'selected':'' ?>>Other</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Date of Birth</label>
                    <input type="date" name="date_of_birth" class="form-control"
                        value="<?= htmlspecialchars($_POST['date_of_birth'] ?? '') ?>"
                        min="1900-01-01"
                        max="<?= date('Y-m-d') ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Phone Number</label>
                    <input type="text" name="phone" class="form-control" placeholder="e.g. 08123456789"
                        value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Address</label>
                    <input type="text" name="address" class="form-control" placeholder="City / Address"
                        value="<?= htmlspecialchars($_POST['address'] ?? '') ?>">
                </div>
            </div>

            <div class="section-divider">Account Credentials</div>
            <div class="form-group">
                <label class="form-label">Username <span style="color:var(--danger)">*</span></label>
                <input type="text" name="username" class="form-control" placeholder="Choose a username (min 4 chars)"
                    value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required minlength="4">
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Password <span style="color:var(--danger)">*</span></label>
                    <div style="position:relative;">
                        <input type="password" name="password" id="pw" class="form-control"
                            placeholder="Min 6 characters" required minlength="6" oninput="checkStrength(this.value)" style="padding-right:44px;">
                        <button type="button" onclick="togglePw('pw',this)" style="position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#93c5fd;font-size:16px;padding:0;line-height:1;">👁</button>
                    </div>
                    <div class="strength-bar"><div class="strength-fill" id="strength-fill"></div></div>
                    <div class="password-hint" id="strength-label">Enter a password</div>
                </div>
                <div class="form-group">
                    <label class="form-label">Confirm Password <span style="color:var(--danger)">*</span></label>
                    <div style="position:relative;">
                        <input type="password" name="confirm_password" id="pw-confirm" class="form-control"
                            placeholder="Re-enter password" required style="padding-right:44px;">
                        <button type="button" onclick="togglePw('pw-confirm',this)" style="position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#93c5fd;font-size:16px;padding:0;line-height:1;">👁</button>
                    </div>
                </div>
            </div>

            <div class="section-divider">Security Question</div>
            <div class="form-group">
                <label class="form-label">Security Question <span style="color:var(--danger)">*</span></label>
                <select name="security_question" class="form-control" required>
                    <option value="">Select a question</option>
                    <?php foreach ($security_questions as $q): ?>
                    <option value="<?= htmlspecialchars($q) ?>"
                        <?= (($_POST['security_question']??'')===$q)?'selected':'' ?>>
                        <?= htmlspecialchars($q) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Your Answer <span style="color:var(--danger)">*</span></label>
                <input type="text" name="security_answer" class="form-control"
                    placeholder="Answer is case-insensitive" required>
                <div class="password-hint">Remember this answer — you'll need it to reset your password.</div>
            </div>

            <button type="submit" class="btn btn-primary btn-register">
                Create Account →
            </button>
        </form>
        <?php endif; ?>

        <div class="sign-in-link">
            Already have an account? <a href="login.php">Sign in</a>
        </div>
    </div>
</div>

<script>
function checkStrength(pw) {
    const fill  = document.getElementById('strength-fill');
    const label = document.getElementById('strength-label');
    let score = 0;
    if (pw.length >= 6)  score++;
    if (pw.length >= 10) score++;
    if (/[A-Z]/.test(pw)) score++;
    if (/[0-9]/.test(pw)) score++;
    if (/[^A-Za-z0-9]/.test(pw)) score++;
    const levels = [
        {w:'0%',   c:'transparent', t:'Enter a password'},
        {w:'25%',  c:'#ef4444',     t:'Weak'},
        {w:'50%',  c:'#f97316',     t:'Fair'},
        {w:'75%',  c:'#eab308',     t:'Good'},
        {w:'100%', c:'#22c55e',     t:'Strong'},
    ];
    const l = levels[Math.min(score, 4)];
    fill.style.width = l.w;
    fill.style.background = l.c;
    label.textContent = l.t;
    label.style.color = l.c === 'transparent' ? 'var(--text-muted)' : l.c;
}
(function(){
    const saved = localStorage.getItem('clinic-theme') || 'dark';
    applyTheme(saved);
    function applyTheme(theme){
        document.documentElement.setAttribute('data-theme', theme);
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
    for(let i=0;i<45;i++) dots.push({x:Math.random()*W,y:Math.random()*H,r:Math.random()*1.6+0.3,dx:(Math.random()-.5)*.3,dy:(Math.random()-.5)*.3,o:Math.random()*.35+0.08});
    function draw(){ctx.clearRect(0,0,W,H);dots.forEach(d=>{ctx.beginPath();ctx.arc(d.x,d.y,d.r,0,Math.PI*2);ctx.fillStyle=`rgba(249,168,212,${d.o})`;ctx.fill();d.x+=d.dx;d.y+=d.dy;if(d.x<0||d.x>W)d.dx*=-1;if(d.y<0||d.y>H)d.dy*=-1;});requestAnimationFrame(draw);}
    draw();
})();
</script>
<script src="includes/interactions.js"></script>
</body>
</html>
