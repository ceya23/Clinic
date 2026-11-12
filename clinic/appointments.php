<?php
require_once 'includes/config.php';
requireLogin();

$error   = '';
$success = '';
$action  = $_GET['action'] ?? 'list';
$id      = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// ── DELETE ────────────────────────────────────────────────────────────────────
if ($action === 'delete' && $id && isAdmin()) {
    $stmt = mysqli_prepare($conn, "DELETE FROM appointment WHERE appointment_id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    header("Location: appointments.php?success=deleted"); exit();
}

// ── CANCEL ────────────────────────────────────────────────────────────────────
// FIX #1: Verify appointment exists and belongs to this doctor before cancelling
if ($action === 'cancel' && $id) {
    if (isDoctor()) {
        $stmt = mysqli_prepare($conn,
            "UPDATE appointment SET status='Cancelled'
             WHERE appointment_id = ? AND doctor_id = ? AND status = 'Scheduled'");
        mysqli_stmt_bind_param($stmt, 'ii', $id, getDoctorId($conn));
    } else {
        $stmt = mysqli_prepare($conn,
            "UPDATE appointment SET status='Cancelled' WHERE appointment_id = ? AND status = 'Scheduled'");
        mysqli_stmt_bind_param($stmt, 'i', $id);
    }
    mysqli_stmt_execute($stmt);
    header("Location: appointments.php?success=cancelled"); exit();
}

// ── COMPLETE ──────────────────────────────────────────────────────────────────
// FIX #1: Verify appointment belongs to doctor before marking complete
if ($action === 'complete' && $id) {
    if (isDoctor()) {
        $stmt = mysqli_prepare($conn,
            "UPDATE appointment SET status='Completed'
             WHERE appointment_id = ? AND doctor_id = ? AND status = 'Scheduled'");
        mysqli_stmt_bind_param($stmt, 'ii', $id, getDoctorId($conn));
    } else {
        $stmt = mysqli_prepare($conn,
            "UPDATE appointment SET status='Completed' WHERE appointment_id = ? AND status = 'Scheduled'");
        mysqli_stmt_bind_param($stmt, 'i', $id);
    }
    mysqli_stmt_execute($stmt);
    header("Location: appointments.php?success=completed"); exit();
}

// ── ADD / EDIT SAVE ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isAdmin()) {
    $patient_id = (int)($_POST['patient_id'] ?? 0);
    $doctor_id  = (int)($_POST['doctor_id']  ?? 0);
    $date       = trim($_POST['appointment_date'] ?? '');
    $status     = $_POST['status'] ?? 'Scheduled';
    $notes      = trim($_POST['notes'] ?? '');

    if (!$patient_id || !$doctor_id || empty($date)) {
        $error = 'Please fill in all required fields.';
    } else {
        if ($action === 'add') {
            // queue_number is auto-set by trigger auto_queue_number
            $stmt = mysqli_prepare($conn, "INSERT INTO appointment (patient_id, doctor_id, appointment_date, status, notes) VALUES (?, ?, ?, ?, ?)");
            mysqli_stmt_bind_param($stmt, 'iisss', $patient_id, $doctor_id, $date, $status, $notes);
            mysqli_stmt_execute($stmt);
            header("Location: appointments.php?success=added"); exit();
        } elseif ($action === 'edit' && $id) {
            $stmt = mysqli_prepare($conn, "UPDATE appointment SET patient_id=?, doctor_id=?, appointment_date=?, status=?, notes=? WHERE appointment_id=?");
            mysqli_stmt_bind_param($stmt, 'iisssi', $patient_id, $doctor_id, $date, $status, $notes, $id);
            mysqli_stmt_execute($stmt);
            header("Location: appointments.php?success=updated"); exit();
        }
    }
}

// ── FETCH SINGLE ─────────────────────────────────────────────────────────────
// FIX #1: Use prepared statement — no raw $id in query
$appointment = null;
if (in_array($action, ['edit']) && $id) {
    $stmt = mysqli_prepare($conn, "SELECT * FROM appointment WHERE appointment_id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $appointment = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    if (!$appointment) { header("Location: appointments.php"); exit(); }
}

// ── FETCH LIST ────────────────────────────────────────────────────────────────
$filter_status = $_GET['status'] ?? '';
$filter_date   = $_GET['date']   ?? '';
$where = [];
if (isDoctor()) $where[] = "a.doctor_id = " . getDoctorId($conn);
if ($filter_status) $where[] = "a.status = '" . mysqli_real_escape_string($conn, $filter_status) . "'";
if ($filter_date)   $where[] = "DATE(a.appointment_date) = '" . mysqli_real_escape_string($conn, $filter_date) . "'";
$where_sql = $where ? "WHERE " . implode(" AND ", $where) : "";

$appointments = mysqli_query($conn, "
    SELECT a.*, p.name patient_name, d.name doctor_name, d.specialization
    FROM appointment a
    JOIN patient p ON a.patient_id = p.patient_id
    JOIN doctor  d ON a.doctor_id  = d.doctor_id
    $where_sql
    ORDER BY a.appointment_date DESC
");

$patients = mysqli_query($conn, "SELECT patient_id, name FROM patient ORDER BY name");
$doctors  = mysqli_query($conn, "SELECT doctor_id, name, specialization FROM doctor ORDER BY name");
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Appointments — IS Class 2 Healthcare</title>
<link rel="stylesheet" href="includes/theme.css">
<style>
.filter-bar {
    display: flex; gap: 10px; align-items: flex-end;
    background: var(--surface); border: 1px solid var(--border);
    border-radius: var(--radius-md); padding: 16px 20px;
    margin-bottom: 16px; flex-wrap: wrap;
}
.filter-bar .form-group { margin: 0; min-width: 160px; }
.status-tabs { display: flex; gap: 6px; margin-bottom: 16px; flex-wrap: wrap; }
.status-tab {
    padding: 6px 14px; border-radius: 20px; font-size: 12px;
    border: 1px solid var(--border); color: var(--text-muted);
    text-decoration: none; transition: all .15s;
    display: inline-flex; align-items: center; gap: 5px;
}
.status-tab:hover { border-color: var(--border-h); color: var(--text); }
.status-tab.active { background: var(--accent-bg); color: var(--accent-text); border-color: var(--accent); }
.queue-num {
    width: 28px; height: 28px; border-radius: 50%;
    background: var(--accent-bg); color: var(--accent-text);
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 11px; font-weight: 600; cursor: default;
}
/* Tooltip on queue */
.queue-wrap { position: relative; display: inline-block; }
.queue-wrap .queue-tip {
    display: none; position: absolute; bottom: calc(100% + 6px); left: 50%;
    transform: translateX(-50%); background: var(--surface);
    border: 1px solid var(--border); border-radius: var(--radius-sm);
    padding: 5px 10px; font-size: 11px; color: var(--text-muted);
    white-space: nowrap; z-index: 99; box-shadow: 0 4px 12px rgba(0,0,0,.3);
}
.queue-wrap:hover .queue-tip { display: block; }

/* Dropdown */
.dropdown { position: relative; display: inline-block; }
.dropdown-toggle {
    padding: 5px 12px; border-radius: var(--radius-sm);
    font-size: 12px; font-weight: 500; cursor: pointer;
    text-decoration: none; border: 1.5px solid var(--border);
    background: transparent; color: var(--text-muted);
    font-family: inherit;
    transition: all .15s; display: inline-flex; align-items: center; gap: 5px;
}
.dropdown-toggle:hover { border-color: var(--border-h); color: var(--text); }
.dropdown-menu {
    display: none; position: absolute; right: 0; top: calc(100% + 4px);
    background: var(--surface); border: 1px solid var(--border);
    border-radius: var(--radius-sm); min-width: 150px;
    box-shadow: 0 8px 24px rgba(0,0,0,.35); z-index: 200; overflow: hidden;
}
.dropdown-menu a {
    display: flex; align-items: center; gap: 8px;
    padding: 9px 14px; font-size: 12px;
    color: var(--text-muted); text-decoration: none;
    transition: background .1s, color .1s;
}
.dropdown-menu a:hover { background: var(--surface-h); color: var(--text); }
.dropdown-menu a.item-danger { color: var(--danger); }
.dropdown-menu a.item-danger:hover { background: rgba(244,63,94,0.08); }
.dropdown-menu .divider { height: 1px; background: var(--border); margin: 3px 0; }
.dropdown.open .dropdown-menu { display: block; }

/* Allow dropdowns to overflow the panel */
.panel { overflow: visible !important; }
.panel .data-table { overflow: visible; }

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
            <?php $msgs = ['added'=>'Appointment created!','updated'=>'Appointment updated!','deleted'=>'Appointment deleted!','cancelled'=>'Appointment cancelled.','completed'=>'Appointment marked as completed!']; echo $msgs[$_GET['success']] ?? 'Done!'; ?>
        </div>
    <?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <?php if ($action === 'list'): ?>

        <div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
            <div>
                <h2><?= icon('appointments') ?> <?= isDoctor() ? 'My Schedule' : 'Appointments' ?></h2>
                <p class="page-sub"><?= isDoctor() ? 'Your patient appointments' : 'All clinic appointments' ?></p>
            </div>
            <?php if (isAdmin()): ?>
            <a href="appointments.php?action=add" class="btn btn-primary"><?= icon('appointments', 14) ?> New Appointment</a>
            <?php endif; ?>
        </div>

        <!-- Status tabs -->
        <div class="status-tabs">
            <a href="appointments.php" class="status-tab <?= !$filter_status ? 'active' : '' ?>">All</a>
            <a href="appointments.php?status=Scheduled" class="status-tab <?= $filter_status==='Scheduled' ? 'active' : '' ?>"><?= icon('appointments', 13) ?> Scheduled</a>
            <a href="appointments.php?status=Completed" class="status-tab <?= $filter_status==='Completed' ? 'active' : '' ?>"><?= icon('examinations', 13) ?> Completed</a>
            <a href="appointments.php?status=Cancelled" class="status-tab <?= $filter_status==='Cancelled' ? 'active' : '' ?>"><?= icon('logout', 13) ?> Cancelled</a>
        </div>

        <!-- Filter bar -->
        <div class="filter-bar">
            <div class="form-group">
                <label class="form-label">Filter by Date</label>
                <input type="date" class="form-control" id="filter-date" value="<?= htmlspecialchars($filter_date) ?>"
                    onchange="window.location='appointments.php?status=<?= $filter_status ?>&date='+this.value">
            </div>
            <?php if ($filter_date || $filter_status): ?>
            <a href="appointments.php" class="btn btn-outline" style="margin-bottom:0;"><?= icon('logout', 13) ?> Clear Filters</a>
            <?php endif; ?>
        </div>

        <div class="panel">
            <?php if (mysqli_num_rows($appointments) === 0): ?>
                <div class="empty-state">No appointments found.</div>
            <?php else: ?>
            <table class="data-table" style="overflow:visible;">
                <thead><tr>
                    <th>Patient</th>
                    <?php if (!isDoctor()): ?><th>Doctor</th><?php endif; ?>
                    <th>Date & Time</th>
                    <th>
                        <span style="display:inline-flex;align-items:center;gap:4px;">
                            Queue
                            <span style="position:relative;display:inline-block;cursor:default;" class="queue-wrap">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--text-muted);vertical-align:middle;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                                <span class="queue-tip">Patient's turn number for that day</span>
                            </span>
                        </span>
                    </th>
                    <th>Status</th>
                    <th style="min-width:110px;">Actions</th>
                </tr></thead>
                <tbody>
                <?php while ($row = mysqli_fetch_assoc($appointments)): ?>
                <tr>
                    <td>
                        <div style="font-weight:500;"><?= htmlspecialchars($row['patient_name']) ?></div>
                    </td>
                    <?php if (!isDoctor()): ?>
                    <td>
                        <div><?= htmlspecialchars($row['doctor_name']) ?></div>
                        <div style="font-size:11px;color:var(--text-muted);"><?= htmlspecialchars($row['specialization']) ?></div>
                    </td>
                    <?php endif; ?>
                    <td>
                        <div><?= date('d M Y', strtotime($row['appointment_date'])) ?></div>
                        <div style="font-size:11px;color:var(--text-muted);"><?= date('H:i', strtotime($row['appointment_date'])) ?></div>
                    </td>
                    <td>
                        <span class="queue-wrap">
                            <span class="queue-num"><?= $row['queue_number'] ?></span>
                            <span class="queue-tip">Queue #<?= $row['queue_number'] ?> — patient's turn for that day</span>
                        </span>
                    </td>
                    <td><span class="badge badge-<?= strtolower($row['status']) ?>"><?= $row['status'] ?></span></td>
                    <td>
                        <div class="dropdown">
                            <button type="button" class="dropdown-toggle" onclick="toggleDropdown(this)">
                                Actions <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
                            </button>
                            <div class="dropdown-menu">
                                <?php if ($row['status'] === 'Scheduled'): ?>
                                    <a href="examinations.php?action=add&appointment_id=<?= $row['appointment_id'] ?>"><?= icon('examinations', 13) ?> Examine</a>
                                    <a href="appointments.php?action=cancel&id=<?= $row['appointment_id'] ?>"
                                       class="item-danger"
                                       onclick="return confirm('Cancel this appointment?')"><?= icon('logout', 13) ?> Cancel</a>
                                    <?php if (isAdmin()): ?><div class="divider"></div><?php endif; ?>
                                <?php endif; ?>
                                <?php if (isAdmin()): ?>
                                    <a href="appointments.php?action=edit&id=<?= $row['appointment_id'] ?>"><?= icon('records', 13) ?> Edit</a>
                                    <a href="appointments.php?action=delete&id=<?= $row['appointment_id'] ?>"
                                       class="item-danger"
                                       onclick="return confirm('Delete this appointment?')"><?= icon('logout', 13) ?> Delete</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>
                </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

    <?php elseif (($action === 'add' || $action === 'edit') && isAdmin()): ?>

        <div class="page-header">
            <h2><?= icon('appointments', 20) ?> <?= $action === 'add' ? 'New Appointment' : 'Edit Appointment' ?></h2>
            <p class="page-sub"><?= $action === 'add' ? 'Create a new appointment' : 'Update appointment details' ?></p>
        </div>

        <div class="panel" style="max-width:560px;">
            <div class="panel-header">
                <div class="panel-title"><?= icon('appointments', 14) ?> Appointment Details</div>
            </div>
            <div style="padding:24px;">
                <form method="POST">
                    <div class="form-group">
                        <label class="form-label">Patient <span style="color:var(--danger)">*</span></label>
                        <select name="patient_id" class="form-control" required>
                            <option value="">Select patient</option>
                            <?php
                            $pats = mysqli_fetch_all($patients, MYSQLI_ASSOC);
                            foreach ($pats as $p): ?>
                            <option value="<?= $p['patient_id'] ?>"
                                <?= (($appointment['patient_id'] ?? 0) == $p['patient_id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($p['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Doctor <span style="color:var(--danger)">*</span></label>
                        <select name="doctor_id" class="form-control" required>
                            <option value="">Select doctor</option>
                            <?php
                            $docs = mysqli_fetch_all($doctors, MYSQLI_ASSOC);
                            foreach ($docs as $d): ?>
                            <option value="<?= $d['doctor_id'] ?>"
                                <?= (($appointment['doctor_id'] ?? 0) == $d['doctor_id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($d['name']) ?> — <?= htmlspecialchars($d['specialization']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Date & Time <span style="color:var(--danger)">*</span></label>
                        <input type="datetime-local" name="appointment_date" class="form-control"
                            value="<?= isset($appointment['appointment_date']) ? date('Y-m-d\TH:i', strtotime($appointment['appointment_date'])) : '' ?>"
                            required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-control">
                            <?php foreach (['Scheduled','Completed','Cancelled'] as $s): ?>
                            <option value="<?= $s ?>" <?= (($appointment['status'] ?? 'Scheduled') === $s) ? 'selected' : '' ?>><?= $s ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="3" placeholder="Optional notes..."><?= htmlspecialchars($appointment['notes'] ?? '') ?></textarea>
                    </div>
                    <div style="display:flex;gap:10px;margin-top:8px;">
                        <button type="submit" class="btn btn-primary">
                            <?= $action === 'add' ? icon('appointments', 14) . ' Create Appointment' : 'Save Changes' ?>
                        </button>
                        <a href="appointments.php" class="btn btn-outline">Cancel</a>
                    </div>
                </form>
            </div>
        </div>

    <?php endif; ?>
    </main>
</div>
<script>
function toggleDropdown(el) {
    event.stopPropagation();
    const dd = el.closest('.dropdown');
    const isOpen = dd.classList.contains('open');
    document.querySelectorAll('.dropdown.open').forEach(d => d.classList.remove('open'));
    if (!isOpen) dd.classList.add('open');
}
document.addEventListener('click', function(e) {
    if (!e.target.closest('.dropdown')) {
        document.querySelectorAll('.dropdown.open').forEach(d => d.classList.remove('open'));
    }
});
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