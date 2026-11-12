<?php
require_once 'includes/config.php';
requireRole(['admin', 'doctor']);

$error   = '';
$success = '';
$action  = $_GET['action'] ?? 'list';
$id      = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// ── SUCCESS MSG FROM REDIRECT ─────────────────────────────────────────────────
if (isset($_GET['success'])) {
    $msgs = ['added' => 'Schedule added.', 'updated' => 'Schedule updated.', 'deleted' => 'Schedule deleted.'];
    $success = $msgs[$_GET['success']] ?? '';
}

// ── DELETE ────────────────────────────────────────────────────────────────────
if ($action === 'delete' && $id) {
    if (isAdmin()) {
        $stmt = mysqli_prepare($conn, "DELETE FROM doctor_schedule WHERE schedule_id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
    }
    header("Location: doctor_schedule.php?success=deleted"); exit();
}

// ── ADD / EDIT SAVE ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $doctor_id = isDoctor() ? getDoctorId($conn) : (int)($_POST['doctor_id'] ?? 0);
    $day       = $_POST['day_of_week'] ?? '';
    $start     = $_POST['start_time']  ?? '';
    $end       = $_POST['end_time']    ?? '';
    $quota     = max(1, (int)($_POST['quota'] ?? 20));

    $valid_days = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];

    if (!$doctor_id) {
        $error = 'Please select a doctor.';
    } elseif (!in_array($day, $valid_days)) {
        $error = 'Invalid day selected.';
    } elseif (empty($start) || empty($end)) {
        $error = 'Start and end time are required.';
    } elseif ($start >= $end) {
        $error = 'End time must be after start time.';
    } else {
        if ($action === 'add') {
            // Check duplicate day for same doctor — FIX #1: use prepared statement
            $dup_stmt = mysqli_prepare($conn,
                "SELECT schedule_id FROM doctor_schedule WHERE doctor_id = ? AND day_of_week = ?");
            mysqli_stmt_bind_param($dup_stmt, 'is', $doctor_id, $day);
            mysqli_stmt_execute($dup_stmt);
            $dup = mysqli_fetch_assoc(mysqli_stmt_get_result($dup_stmt));
            if ($dup) {
                $error = "This doctor already has a schedule on $day.";
            } else {
                $stmt = mysqli_prepare($conn,
                    "INSERT INTO doctor_schedule (doctor_id, day_of_week, start_time, end_time, quota) VALUES (?, ?, ?, ?, ?)");
                mysqli_stmt_bind_param($stmt, 'isssi', $doctor_id, $day, $start, $end, $quota);
                mysqli_stmt_execute($stmt);
                header("Location: doctor_schedule.php?success=added"); exit();
            }
        } elseif ($action === 'edit' && $id) {
            $stmt = mysqli_prepare($conn,
                "UPDATE doctor_schedule SET doctor_id=?, day_of_week=?, start_time=?, end_time=?, quota=? WHERE schedule_id=?");
            mysqli_stmt_bind_param($stmt, 'isssii', $doctor_id, $day, $start, $end, $quota, $id);
            mysqli_stmt_execute($stmt);
            header("Location: doctor_schedule.php?success=updated"); exit();
        }
    }
}

// ── FETCH SINGLE (edit) ───────────────────────────────────────────────────────
$schedule = null;
if ($action === 'edit' && $id) {
    $stmt = mysqli_prepare($conn,
        "SELECT ds.*, d.name doctor_name FROM doctor_schedule ds JOIN doctor d ON ds.doctor_id=d.doctor_id WHERE ds.schedule_id=?");
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $schedule = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    if (!$schedule || (!isAdmin() && $schedule['doctor_id'] != getDoctorId($conn))) {
        header("Location: doctor_schedule.php"); exit();
    }
}

// ── LIST ──────────────────────────────────────────────────────────────────────
$filter_doc = isset($_GET['doctor_id']) ? (int)$_GET['doctor_id'] : 0;

if (isDoctor()) {
    $my_doc_id = getDoctorId($conn);
    $where = "WHERE ds.doctor_id = $my_doc_id";
} else {
    $where = $filter_doc ? "WHERE ds.doctor_id = $filter_doc" : "";
}

$schedules = mysqli_query($conn, "
    SELECT ds.*, d.name doctor_name, d.specialization
    FROM doctor_schedule ds
    JOIN doctor d ON ds.doctor_id = d.doctor_id
    $where
    ORDER BY d.name ASC,
             FIELD(ds.day_of_week,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday')
");

$doctors = isAdmin() ? mysqli_query($conn,
    "SELECT doctor_id, name, specialization FROM doctor ORDER BY name ASC") : null;

$days = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];

// Stats
$total_schedules = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) c FROM doctor_schedule" . ($filter_doc ? " WHERE doctor_id=$filter_doc" : "")))['c'];
$total_doctors_with_sched = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(DISTINCT doctor_id) c FROM doctor_schedule"))['c'];
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Doctor Schedules — IS Class 2 Healthcare</title>
<link rel="stylesheet" href="includes/theme.css">
<style>
.sched-table { width:100%; border-collapse:collapse; }
.sched-table th {
    background: var(--surface2);
    font-size: 11px; text-transform: uppercase;
    letter-spacing: .06em; color: var(--text-muted);
    padding: 10px 14px; text-align: left;
    border-bottom: 1.5px solid var(--border);
    font-weight: 700;
}
.sched-table td {
    padding: 12px 14px;
    border-bottom: 1px solid var(--border);
    font-size: 13px; color: var(--text);
    vertical-align: middle;
}
.sched-table tr:hover td { background: var(--surface2); }
.day-badge {
    display: inline-block;
    padding: 3px 10px; border-radius: 20px;
    font-size: 11px; font-weight: 700;
    background: var(--accent-bg); color: var(--accent-text);
}
.quota-badge {
    display: inline-block;
    padding: 3px 10px; border-radius: 20px;
    font-size: 11px; font-weight: 700;
    background: rgba(56,189,248,0.15); color: var(--info);
}
.time-range { font-family: monospace; font-size: 13px; color: var(--accent2); }
.modal-overlay {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,0.55); z-index: 200;
    align-items: center; justify-content: center;
}
.modal-overlay.show { display: flex; }
.modal {
    background: var(--surface); border: 1.5px solid var(--border);
    border-radius: var(--radius-lg); padding: 28px 32px;
    width: 100%; max-width: 460px;
    box-shadow: 0 8px 40px rgba(0,0,0,0.3);
}
.modal h3 { font-size: 16px; font-weight: 700; margin-bottom: 20px; }
.form-group { margin-bottom: 16px; }
.form-group label { display: block; font-size: 12px; font-weight: 700; color: var(--text-muted); margin-bottom: 6px; text-transform: uppercase; letter-spacing: .05em; }
.form-group select, .form-group input {
    width: 100%; padding: 10px 14px;
    background: var(--surface2); border: 1.5px solid var(--border);
    border-radius: var(--radius-sm); color: var(--text);
    font-size: 13px; font-family: 'Nunito', sans-serif;
    transition: border-color .15s;
}
.form-group select:focus, .form-group input:focus {
    outline: none; border-color: var(--accent);
}
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.btn { padding: 10px 20px; border: none; border-radius: var(--radius-sm); font-size: 13px; font-weight: 700; cursor: pointer; transition: all .15s; }
.btn-primary { background: var(--accent); color: #fff; }
.btn-primary:hover { background: var(--accent-h); }
.btn-ghost { background: var(--surface2); color: var(--text-muted); border: 1px solid var(--border); }
.btn-ghost:hover { color: var(--text); border-color: var(--border-h); }
.btn-danger-sm { background: rgba(244,63,94,0.12); color: var(--danger); border: 1px solid rgba(244,63,94,0.2); border-radius: 8px; padding: 5px 12px; font-size: 12px; font-weight: 700; cursor: pointer; }
.btn-danger-sm:hover { background: rgba(244,63,94,0.2); }
.btn-edit-sm { background: var(--accent-bg); color: var(--accent-text); border: 1px solid var(--border); border-radius: 8px; padding: 5px 12px; font-size: 12px; font-weight: 700; text-decoration: none; display: inline-block; }
.btn-edit-sm:hover { background: var(--accent); color: #fff; }
.stat-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-md); padding: 16px 20px; }
.stat-num { font-family: 'Playfair Display', serif; font-size: 28px; font-weight: 400; color: var(--accent); }
.stat-label { font-size: 11px; text-transform: uppercase; letter-spacing: .06em; color: var(--text-muted); margin-top: 4px; }
.alert { padding: 12px 16px; border-radius: var(--radius-sm); margin-bottom: 18px; font-size: 13px; font-weight: 600; }
.alert-success { background: rgba(16,185,129,0.12); color: var(--success); border: 1px solid rgba(16,185,129,0.25); }
.alert-danger  { background: rgba(244,63,94,0.10);  color: var(--danger);  border: 1px solid rgba(244,63,94,0.2); }
.section-title { font-size: 11px; text-transform: uppercase; letter-spacing: .07em; color: var(--text-muted); margin-bottom: 12px; font-weight: 700; }

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
<main style="margin-left:220px; padding:32px; flex:1;">

<div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:24px;">
    <div>
        <h2 style="font-family:'Playfair Display',serif; font-size:22px; font-weight:400;">Doctor Schedules</h2>
        <p style="font-size:12px; color:var(--text-muted); margin-top:4px;">Manage practice days, hours, and patient quotas</p>
    </div>
    <?php if (isAdmin() || isDoctor()): ?>
    <button class="btn btn-primary" onclick="openAddModal()">+ Add Schedule</button>
    <?php endif; ?>
</div>

<?php if ($success): ?>
<div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- Stats -->
<div style="display:grid; grid-template-columns:repeat(3,1fr); gap:14px; margin-bottom:24px;">
    <div class="stat-card">
        <div class="stat-num"><?= $total_schedules ?></div>
        <div class="stat-label">Total schedule slots</div>
    </div>
    <div class="stat-card">
        <div class="stat-num"><?= $total_doctors_with_sched ?></div>
        <div class="stat-label">Doctors with schedules</div>
    </div>
    <div class="stat-card">
        <div class="stat-num"><?= mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(quota),0) c FROM doctor_schedule"))['c'] ?></div>
        <div class="stat-label">Total weekly quota</div>
    </div>
</div>

<!-- Filter (admin only) -->
<?php if (isAdmin() && $doctors): ?>
<div style="background:var(--surface); border:1px solid var(--border); border-radius:var(--radius-md); padding:16px 20px; margin-bottom:18px; display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
    <span class="section-title" style="margin:0;">Filter by doctor:</span>
    <a href="doctor_schedule.php" class="btn-edit-sm <?= !$filter_doc ? 'active' : '' ?>" style="<?= !$filter_doc ? 'background:var(--accent);color:#fff;' : '' ?>">All</a>
    <?php mysqli_data_seek($doctors, 0); while ($d = mysqli_fetch_assoc($doctors)): ?>
    <a href="doctor_schedule.php?doctor_id=<?= $d['doctor_id'] ?>" class="btn-edit-sm"
       style="<?= $filter_doc == $d['doctor_id'] ? 'background:var(--accent);color:#fff;' : '' ?>">
        <?= htmlspecialchars($d['name']) ?>
    </a>
    <?php endwhile; ?>
</div>
<?php endif; ?>

<!-- Table -->
<div style="background:var(--surface); border:1px solid var(--border); border-radius:var(--radius-md); overflow:hidden;">
<table class="sched-table">
    <thead>
        <tr>
            <th>Doctor</th>
            <th>Specialization</th>
            <th>Day</th>
            <th>Hours</th>
            <th>Quota</th>
            <?php if (isAdmin()): ?><th>Actions</th><?php endif; ?>
        </tr>
    </thead>
    <tbody>
    <?php
    $rows = mysqli_fetch_all($schedules, MYSQLI_ASSOC);
    if (empty($rows)):
    ?>
    <tr><td colspan="6" style="text-align:center; padding:32px; color:var(--text-muted);">No schedules found.</td></tr>
    <?php else: foreach ($rows as $row): ?>
    <tr>
        <td><strong><?= htmlspecialchars($row['doctor_name']) ?></strong></td>
        <td><span style="color:var(--text-muted); font-size:12px;"><?= htmlspecialchars($row['specialization']) ?></span></td>
        <td><span class="day-badge"><?= $row['day_of_week'] ?></span></td>
        <td><span class="time-range"><?= substr($row['start_time'],0,5) ?> – <?= substr($row['end_time'],0,5) ?></span></td>
        <td><span class="quota-badge"><?= $row['quota'] ?> patients</span></td>
        <?php if (isAdmin()): ?>
        <td style="display:flex; gap:8px; align-items:center;">
            <a href="doctor_schedule.php?action=edit&id=<?= $row['schedule_id'] ?>" class="btn-edit-sm">Edit</a>
            <button class="btn-danger-sm" onclick="confirmDelete(<?= $row['schedule_id'] ?>)">Delete</button>
        </td>
        <?php endif; ?>
    </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>
</div>

<!-- ADD MODAL -->
<div class="modal-overlay" id="addModal">
<div class="modal">
    <h3>Add New Schedule</h3>
    <form method="POST" action="doctor_schedule.php?action=add">
        <?php if (isAdmin()): ?>
        <div class="form-group">
            <label>Doctor</label>
            <select name="doctor_id" required>
                <option value="">— Select doctor —</option>
                <?php
                $doc_list = mysqli_query($conn, "SELECT doctor_id, name, specialization FROM doctor ORDER BY name ASC");
                while ($d = mysqli_fetch_assoc($doc_list)):
                ?>
                <option value="<?= $d['doctor_id'] ?>"><?= htmlspecialchars($d['name']) ?> (<?= $d['specialization'] ?>)</option>
                <?php endwhile; ?>
            </select>
        </div>
        <?php endif; ?>
        <div class="form-group">
            <label>Day of Week</label>
            <select name="day_of_week" required>
                <option value="">— Select day —</option>
                <?php foreach ($days as $d): ?>
                <option value="<?= $d ?>"><?= $d ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Start Time</label>
                <input type="time" name="start_time" required>
            </div>
            <div class="form-group">
                <label>End Time</label>
                <input type="time" name="end_time" required>
            </div>
        </div>
        <div class="form-group">
            <label>Patient Quota</label>
            <input type="number" name="quota" min="1" max="100" value="20" required>
        </div>
        <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:20px;">
            <button type="button" class="btn btn-ghost" onclick="closeAddModal()">Cancel</button>
            <button type="submit" class="btn btn-primary">Save Schedule</button>
        </div>
    </form>
</div>
</div>

<!-- EDIT MODAL (shown if action=edit) -->
<?php if ($action === 'edit' && $schedule): ?>
<div class="modal-overlay show" id="editModal">
<div class="modal">
    <h3>Edit Schedule</h3>
    <form method="POST" action="doctor_schedule.php?action=edit&id=<?= $schedule['schedule_id'] ?>">
        <?php if (isAdmin()): ?>
        <div class="form-group">
            <label>Doctor</label>
            <select name="doctor_id" required>
                <?php
                $doc_list2 = mysqli_query($conn, "SELECT doctor_id, name FROM doctor ORDER BY name ASC");
                while ($d = mysqli_fetch_assoc($doc_list2)):
                ?>
                <option value="<?= $d['doctor_id'] ?>" <?= $d['doctor_id'] == $schedule['doctor_id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($d['name']) ?>
                </option>
                <?php endwhile; ?>
            </select>
        </div>
        <?php endif; ?>
        <div class="form-group">
            <label>Day of Week</label>
            <select name="day_of_week" required>
                <?php foreach ($days as $d): ?>
                <option value="<?= $d ?>" <?= $d === $schedule['day_of_week'] ? 'selected' : '' ?>><?= $d ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Start Time</label>
                <input type="time" name="start_time" value="<?= htmlspecialchars($schedule['start_time']) ?>" required>
            </div>
            <div class="form-group">
                <label>End Time</label>
                <input type="time" name="end_time" value="<?= htmlspecialchars($schedule['end_time']) ?>" required>
            </div>
        </div>
        <div class="form-group">
            <label>Patient Quota</label>
            <input type="number" name="quota" min="1" max="100" value="<?= $schedule['quota'] ?>" required>
        </div>
        <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:20px;">
            <a href="doctor_schedule.php" class="btn btn-ghost" style="text-decoration:none;">Cancel</a>
            <button type="submit" class="btn btn-primary">Update Schedule</button>
        </div>
    </form>
</div>
</div>
<?php endif; ?>

<form id="deleteForm" method="POST" action="" style="display:none;">
    <input type="hidden" name="_method" value="DELETE">
</form>

</main>
</div>

<script src="includes/interactions.js"></script>
<script>
function openAddModal()  { document.getElementById('addModal').classList.add('show'); }
function closeAddModal() { document.getElementById('addModal').classList.remove('show'); }
document.getElementById('addModal')?.addEventListener('click', function(e) {
    if (e.target === this) closeAddModal();
});

function confirmDelete(id) {
    if (confirm('Delete this schedule slot?')) {
        window.location = 'doctor_schedule.php?action=delete&id=' + id;
    }
}
</script>
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
