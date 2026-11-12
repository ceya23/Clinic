<?php
require_once 'includes/config.php';
requireRole('admin');

$error   = '';
$success = '';
$action  = $_GET['action'] ?? 'list';
$id      = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// ── DELETE DOCTOR ─────────────────────────────────────────────────────────────
if ($action === 'delete' && $id) {
    $stmt = mysqli_prepare($conn, "DELETE FROM doctor WHERE doctor_id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    header("Location: doctors.php?success=deleted"); exit();
}

// ── DELETE SCHEDULE ───────────────────────────────────────────────────────────
if ($action === 'delete_schedule' && $id) {
    $doctor_id = (int)($_GET['doctor_id'] ?? 0);
    $stmt = mysqli_prepare($conn, "DELETE FROM doctor_schedule WHERE schedule_id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    header("Location: doctors.php?action=view&id=$doctor_id&success=schedule_deleted"); exit();
}

// ── ADD / EDIT DOCTOR ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_doctor'])) {
    $name     = trim($_POST['name']           ?? '');
    $spec     = trim($_POST['specialization'] ?? '');
    $phone    = trim($_POST['phone']          ?? '');
    $username = trim($_POST['username']       ?? '');
    $password = $_POST['password']            ?? '';

    if (empty($name)) {
        $error = 'Doctor name is required.';
    } elseif ($action === 'add' && empty($username)) {
        $error = 'Username is required.';
    } elseif ($action === 'add' && strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } else {
        if ($action === 'add') {
            // Check username not taken
            $check = mysqli_prepare($conn, "SELECT user_id FROM user WHERE username = ?");
            mysqli_stmt_bind_param($check, 's', $username);
            mysqli_stmt_execute($check);
            mysqli_stmt_store_result($check);
            if (mysqli_stmt_num_rows($check) > 0) {
                $error = 'Username already taken. Please choose another.';
            } else {
                // Create user account
                $hashed = password_hash($password, PASSWORD_BCRYPT);
                $role   = 'doctor';
                $stmt_u = mysqli_prepare($conn, "INSERT INTO user (username, password, role, is_active) VALUES (?, ?, ?, 1)");
                mysqli_stmt_bind_param($stmt_u, 'sss', $username, $hashed, $role);
                mysqli_stmt_execute($stmt_u);
                $new_user_id = mysqli_insert_id($conn);

                // Create doctor linked to user
                $stmt_d = mysqli_prepare($conn, "INSERT INTO doctor (user_id, name, specialization, phone) VALUES (?, ?, ?, ?)");
                mysqli_stmt_bind_param($stmt_d, 'isss', $new_user_id, $name, $spec, $phone);
                mysqli_stmt_execute($stmt_d);
                header("Location: doctors.php?success=added"); exit();
            }
        } elseif ($action === 'edit' && $id) {
            $stmt = mysqli_prepare($conn, "UPDATE doctor SET name=?, specialization=?, phone=? WHERE doctor_id=?");
            mysqli_stmt_bind_param($stmt, 'sssi', $name, $spec, $phone, $id);
            mysqli_stmt_execute($stmt);
            header("Location: doctors.php?success=updated"); exit();
        }
    }
}

// ── ADD SCHEDULE ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_schedule'])) {
    $doctor_id = (int)($_POST['doctor_id'] ?? 0);
    $day       = $_POST['day_of_week'] ?? '';
    $start     = $_POST['start_time']  ?? '';
    $end       = $_POST['end_time']    ?? '';
    $quota     = (int)($_POST['quota'] ?? 20);

    if ($doctor_id && $day && $start && $end) {
        $stmt = mysqli_prepare($conn, "INSERT INTO doctor_schedule (doctor_id, day_of_week, start_time, end_time, quota) VALUES (?, ?, ?, ?, ?)");
        mysqli_stmt_bind_param($stmt, 'isssi', $doctor_id, $day, $start, $end, $quota);
        mysqli_stmt_execute($stmt);
        header("Location: doctors.php?action=view&id=$doctor_id&success=schedule_added"); exit();
    }
}

// ── FETCH DATA ────────────────────────────────────────────────────────────────
$doctor = null;
if (in_array($action, ['edit','view']) && $id) {
    $doctor = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM doctor WHERE doctor_id = $id"));
    if (!$doctor) { header("Location: doctors.php"); exit(); }
}

$doctors = mysqli_query($conn, "
    SELECT d.*, COUNT(DISTINCT a.appointment_id) total_appointments
    FROM doctor d
    LEFT JOIN appointment a ON d.doctor_id = a.doctor_id
    GROUP BY d.doctor_id
    ORDER BY d.name ASC
");

$schedules = null;
if ($action === 'view' && $id) {
    $schedules = mysqli_query($conn, "SELECT * FROM doctor_schedule WHERE doctor_id = $id ORDER BY FIELD(day_of_week,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday')");
}

$days = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
$specs = ['General Practitioner','Dentist','Neurologist','Pediatrician','Dermatologist','Cardiologist','Orthopedist','Ophthalmologist','ENT Specialist','Psychiatrist'];
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Doctors — IS Class 2 Healthcare</title>
<link rel="stylesheet" href="includes/theme.css">
<style>
.doc-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    padding: 20px;
    display: flex;
    align-items: center;
    gap: 16px;
    transition: border-color .2s;
}
.doc-card:hover { border-color: var(--border-h); }
.doc-avatar {
    width: 48px; height: 48px;
    border-radius: 50%;
    background: var(--accent-bg);
    border: 2px solid var(--accent);
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; flex-shrink: 0;
}
.doc-info { flex: 1; min-width: 0; }
.doc-name { font-size: 14px; font-weight: 500; color: var(--text); }
.doc-spec {
    font-size: 11px; color: var(--accent-h);
    background: var(--accent-bg);
    padding: 2px 8px; border-radius: 20px;
    display: inline-block; margin-top: 4px;
}
.doc-phone { font-size: 11px; color: var(--text-muted); margin-top: 4px; }
.doc-actions { display: flex; gap: 6px; flex-shrink: 0; }
.doc-stat {
    font-size: 11px; color: var(--text-muted);
    text-align: center; padding: 0 16px;
    border-left: 1px solid var(--border);
}
.doc-stat strong { display: block; font-size: 18px; color: var(--text); font-family: 'Playfair Display', serif; }

.doctors-grid { display: flex; flex-direction: column; gap: 10px; }

.schedule-table { width: 100%; border-collapse: collapse; }
.schedule-table th {
    text-align: left; font-size: 10px; text-transform: uppercase;
    letter-spacing: .06em; color: var(--text-muted);
    padding: 10px 16px; background: var(--surface2);
    border-bottom: 1px solid var(--border); font-weight: 500;
}
.schedule-table td {
    padding: 11px 16px; font-size: 13px;
    color: var(--text); border-bottom: 1px solid var(--border);
}
.schedule-table tr:last-child td { border-bottom: none; }

.day-badge {
    display: inline-block; font-size: 11px; font-weight: 500;
    padding: 3px 10px; border-radius: 20px;
    background: var(--accent-bg); color: var(--accent-text);
}
.view-header {
    display: flex; align-items: center; gap: 16px;
    background: var(--surface); border: 1px solid var(--border);
    border-radius: var(--radius-md); padding: 24px;
    margin-bottom: 20px;
}
.view-avatar {
    width: 64px; height: 64px; border-radius: 50%;
    background: var(--accent-bg); border: 2px solid var(--accent);
    display: flex; align-items: center; justify-content: center;
    font-size: 28px; flex-shrink: 0;
}
.add-schedule-form {
    background: var(--surface2);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    padding: 20px; margin-top: 16px;
}
.form-row-3 { display: grid; grid-template-columns: 1fr 1fr 1fr 80px auto; gap: 12px; align-items: flex-end; }

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
            <?php
            $msgs = ['added'=>'Doctor added!','updated'=>'Doctor updated!','deleted'=>'Doctor deleted!','schedule_added'=>'Schedule added!','schedule_deleted'=>'Schedule removed!'];
            echo $msgs[$_GET['success']] ?? 'Done!';
            ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php /* ── LIST ── */ if ($action === 'list'): ?>

        <div class="page-header" style="display:flex;align-items:center;justify-content:space-between;">
            <div>
                <h2><?= icon('doctors') ?> Doctors</h2>
                <p class="page-sub">Manage clinic doctors and their schedules</p>
            </div>
            <a href="doctors.php?action=add" class="btn btn-primary"><?= icon('users', 14) ?> Add Doctor</a>
        </div>

        <div class="doctors-grid">
        <?php
        $count = mysqli_num_rows($doctors);
        if ($count === 0): ?>
            <div class="panel"><div class="empty-state">No doctors found. <a href="doctors.php?action=add" style="color:var(--accent-h)">Add one →</a></div></div>
        <?php else: while ($doc = mysqli_fetch_assoc($doctors)): ?>
            <div class="doc-card">
                <div class="doc-avatar"><?= icon('doctors', 24) ?></div>
                <div class="doc-info">
                    <div class="doc-name"><?= htmlspecialchars($doc['name']) ?></div>
                    <span class="doc-spec"><?= htmlspecialchars($doc['specialization'] ?? 'General') ?></span>
                    <div class="doc-phone">📞 <?= htmlspecialchars($doc['phone'] ?? '—') ?></div>
                </div>
                <div class="doc-stat">
                    <strong><?= $doc['total_appointments'] ?></strong>
                    Appointments
                </div>
                <div class="doc-actions">
                    <a href="doctors.php?action=view&id=<?= $doc['doctor_id'] ?>" class="btn btn-outline btn-sm"><?= icon('records', 13) ?> View</a>
                    <a href="doctors.php?action=edit&id=<?= $doc['doctor_id'] ?>" class="btn btn-outline btn-sm"><?= icon('records', 13) ?> Edit</a>
                    <a href="doctors.php?action=delete&id=<?= $doc['doctor_id'] ?>"
                       class="btn btn-danger btn-sm"
                       onclick="return confirm('Delete <?= htmlspecialchars($doc['name']) ?>? This cannot be undone.')">
                       🗑</a>
                </div>
            </div>
        <?php endwhile; endif; ?>
        </div>

    <?php /* ── ADD / EDIT ── */ elseif ($action === 'add' || $action === 'edit'): ?>

        <div class="page-header">
            <h2><?= icon('users', 14) ?> <?= $action === 'add' ? 'Add Doctor' : 'Edit Doctor' ?></h2>
            <p class="page-sub"><?= $action === 'add' ? 'Register a new doctor' : 'Update doctor information' ?></p>
        </div>

        <div class="panel" style="max-width:560px;">
            <div class="panel-header">
                <div class="panel-title"><?= icon('doctors', 14) ?> Doctor Information</div>
            </div>
            <div style="padding:24px;">
                <form method="POST">
                    <div class="form-group">
                        <label class="form-label">Full Name <span style="color:var(--danger)">*</span></label>
                        <input type="text" name="name" class="form-control"
                            placeholder="e.g. Dr. John Smith"
                            value="<?= htmlspecialchars($doctor['name'] ?? $_POST['name'] ?? '') ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Specialization</label>
                        <select name="specialization" class="form-control">
                            <option value="">Select specialization</option>
                            <?php foreach ($specs as $s): ?>
                            <option value="<?= $s ?>" <?= (($doctor['specialization'] ?? '') === $s) ? 'selected' : '' ?>>
                                <?= $s ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Phone Number</label>
                        <input type="text" name="phone" class="form-control"
                            placeholder="e.g. 0811234567"
                            value="<?= htmlspecialchars($doctor['phone'] ?? $_POST['phone'] ?? '') ?>">
                    </div>

                    <?php if ($action === 'add'): ?>
                    <hr style="border-color:var(--border);margin:20px 0;">
                    <div style="font-size:12px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:14px;">🔐 Login Credentials</div>
                    <div class="form-group">
                        <label class="form-label">Username <span style="color:var(--danger)">*</span></label>
                        <input type="text" name="username" class="form-control"
                            placeholder="e.g. dr.john"
                            value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Password <span style="color:var(--danger)">*</span></label>
                        <input type="password" name="password" class="form-control"
                            placeholder="Min. 6 characters" required>
                        <div style="font-size:11px;color:var(--text-muted);margin-top:4px;">Share this password with the doctor so they can log in.</div>
                    </div>
                    <?php endif; ?>

                    <div style="display:flex;gap:10px;margin-top:8px;">
                        <button type="submit" name="save_doctor" class="btn btn-primary">
                            <?= icon('users', 14) ?> <?= $action === 'add' ? 'Add Doctor' : 'Save Changes' ?>
                        </button>
                        <a href="doctors.php" class="btn btn-outline">Cancel</a>
                    </div>
                </form>
            </div>
        </div>

    <?php /* ── VIEW ── */ elseif ($action === 'view' && $doctor): ?>

        <div class="page-header" style="display:flex;align-items:center;justify-content:space-between;">
            <div>
                <h2><?= icon('doctors') ?> Doctor Profile</h2>
                <p class="page-sub">View details and manage schedule</p>
            </div>
            <div style="display:flex;gap:8px;">
                <a href="doctors.php?action=edit&id=<?= $id ?>" class="btn btn-outline"><?= icon('records', 13) ?> Edit</a>
                <a href="doctors.php" class="btn btn-outline">← Back</a>
            </div>
        </div>

        <!-- Doctor Info -->
        <div class="view-header">
            <div class="view-avatar"><?= icon('doctors', 36) ?></div>
            <div style="flex:1;">
                <div style="font-family:'Playfair Display',serif;font-size:20px;color:var(--text);">
                    <?= htmlspecialchars($doctor['name']) ?>
                </div>
                <span class="doc-spec"><?= htmlspecialchars($doctor['specialization'] ?? 'General') ?></span>
                <div class="doc-phone" style="margin-top:6px;">📞 <?= htmlspecialchars($doctor['phone'] ?? '—') ?></div>
            </div>
        </div>

        <!-- Schedules -->
        <div class="panel">
            <div class="panel-header">
                <div class="panel-title">Practice Schedule</div>
            </div>
            <?php
            $sched_rows = mysqli_fetch_all($schedules, MYSQLI_ASSOC);
            if (count($sched_rows) > 0): ?>
            <table class="schedule-table">
                <thead><tr>
                    <th>Day</th><th>Start Time</th><th>End Time</th><th>Quota</th><th>Action</th>
                </tr></thead>
                <tbody>
                <?php foreach ($sched_rows as $s): ?>
                <tr>
                    <td><span class="day-badge"><?= $s['day_of_week'] ?></span></td>
                    <td><?= date('H:i', strtotime($s['start_time'])) ?></td>
                    <td><?= date('H:i', strtotime($s['end_time'])) ?></td>
                    <td><?= $s['quota'] ?> patients</td>
                    <td>
                        <a href="doctors.php?action=delete_schedule&id=<?= $s['schedule_id'] ?>&doctor_id=<?= $id ?>"
                           class="btn btn-danger btn-sm"
                           onclick="return confirm('Remove this schedule?')">🗑Remove</a>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
                <div class="empty-state">No schedule set yet.</div>
            <?php endif; ?>

            <!-- Add schedule form -->
            <div class="add-schedule-form">
                <div style="font-size:12px;font-weight:500;color:var(--text);margin-bottom:14px;"><?= icon('appointments', 13) ?> Add Schedule</div>
                <form method="POST">
                    <input type="hidden" name="doctor_id" value="<?= $id ?>">
                    <div class="form-row-3">
                        <div class="form-group" style="margin:0;">
                            <label class="form-label">Day</label>
                            <select name="day_of_week" class="form-control" required>
                                <option value="">Select day</option>
                                <?php foreach ($days as $d): ?>
                                <option value="<?= $d ?>"><?= $d ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group" style="margin:0;">
                            <label class="form-label">Start Time</label>
                            <input type="time" name="start_time" class="form-control" required>
                        </div>
                        <div class="form-group" style="margin:0;">
                            <label class="form-label">End Time</label>
                            <input type="time" name="end_time" class="form-control" required>
                        </div>
                        <div class="form-group" style="margin:0;">
                            <label class="form-label">Quota</label>
                            <input type="number" name="quota" class="form-control" value="20" min="1" max="100" required>
                        </div>
                        <div>
                            <button type="submit" name="save_schedule" class="btn btn-primary" style="width:100%;">Add</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

    <?php endif; ?>
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
