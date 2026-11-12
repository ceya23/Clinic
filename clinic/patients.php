<?php
require_once 'includes/config.php';
requireRole('admin');

$error   = '';
$success = '';
$action  = $_GET['action'] ?? 'list'; // list | add | edit | view
$id      = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// ── DELETE ────────────────────────────────────────────────────────────────────
if ($action === 'delete' && $id) {
    // Also delete linked user account
    $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT user_id FROM patient WHERE patient_id = $id"));
    $stmt = mysqli_prepare($conn, "DELETE FROM patient WHERE patient_id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    if ($row && $row['user_id']) {
        $s2 = mysqli_prepare($conn, "DELETE FROM user WHERE user_id = ?");
        mysqli_stmt_bind_param($s2, 'i', $row['user_id']);
        mysqli_stmt_execute($s2);
    }
    header("Location: patients.php?success=deleted");
    exit();
}

// ── ADD / EDIT SAVE ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name    = trim($_POST['name']   ?? '');
    $gender  = $_POST['gender']      ?? '';
    $dob     = $_POST['dob']         ?? '';
    $phone   = trim($_POST['phone']  ?? '');
    $address = trim($_POST['address'] ?? '');

    $min_dob = '1900-01-01';
    $max_dob = date('Y-m-d');

    if (empty($name)) {
        $error = 'Patient name is required.';
    } elseif (!empty($dob) && ($dob < $min_dob || $dob > $max_dob)) {
        $error = 'Date of birth must be between 1900 and at least 1 year ago.';
    } else {
        $dob_val = !empty($dob) ? $dob : null;

        if ($action === 'add') {
            $stmt = mysqli_prepare($conn, "INSERT INTO patient (name, gender, date_of_birth, phone, address) VALUES (?, ?, ?, ?, ?)");
            mysqli_stmt_bind_param($stmt, 'sssss', $name, $gender, $dob_val, $phone, $address);
            mysqli_stmt_execute($stmt);
            header("Location: patients.php?success=added");
            exit();
        } elseif ($action === 'edit' && $id) {
            $stmt = mysqli_prepare($conn, "UPDATE patient SET name=?, gender=?, date_of_birth=?, phone=?, address=? WHERE patient_id=?");
            mysqli_stmt_bind_param($stmt, 'sssssi', $name, $gender, $dob_val, $phone, $address, $id);
            mysqli_stmt_execute($stmt);
            header("Location: patients.php?success=updated");
            exit();
        }
    }
}

// ── FETCH SINGLE PATIENT (edit / view) ───────────────────────────────────────
$patient = null;
if (($action === 'edit' || $action === 'view') && $id) {
    $stmt = mysqli_prepare($conn, "SELECT p.*, u.username, u.is_active FROM patient p LEFT JOIN user u ON p.user_id = u.user_id WHERE p.patient_id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $patient = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    if (!$patient) { header("Location: patients.php"); exit(); }
}

// ── LIST + SEARCH ─────────────────────────────────────────────────────────────
$search = trim($_GET['q'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 10;
$offset = ($page - 1) * $limit;

if ($search !== '') {
    $like = "%$search%";
    $count_stmt = mysqli_prepare($conn, "SELECT COUNT(*) FROM patient WHERE name LIKE ? OR phone LIKE ? OR address LIKE ?");
    mysqli_stmt_bind_param($count_stmt, 'sss', $like, $like, $like);
    mysqli_stmt_execute($count_stmt);
    $total = mysqli_fetch_row(mysqli_stmt_get_result($count_stmt))[0];

    $list_stmt = mysqli_prepare($conn, "SELECT p.*, u.username FROM patient p LEFT JOIN user u ON p.user_id = u.user_id WHERE p.name LIKE ? OR p.phone LIKE ? OR p.address LIKE ? ORDER BY p.created_at DESC LIMIT ? OFFSET ?");
    mysqli_stmt_bind_param($list_stmt, 'sssii', $like, $like, $like, $limit, $offset);
} else {
    $count_stmt = mysqli_prepare($conn, "SELECT COUNT(*) FROM patient");
    mysqli_stmt_execute($count_stmt);
    $total = mysqli_fetch_row(mysqli_stmt_get_result($count_stmt))[0];

    $list_stmt = mysqli_prepare($conn, "SELECT p.*, u.username FROM patient p LEFT JOIN user u ON p.user_id = u.user_id ORDER BY p.created_at DESC LIMIT ? OFFSET ?");
    mysqli_stmt_bind_param($list_stmt, 'ii', $limit, $offset);
}
mysqli_stmt_execute($list_stmt);
$patients = mysqli_stmt_get_result($list_stmt);
$total_pages = ceil($total / $limit);

// Flash messages
if (isset($_GET['success'])) {
    $msgs = ['added' => 'Patient added successfully.', 'updated' => 'Patient updated successfully.', 'deleted' => 'Patient deleted.'];
    $success = $msgs[$_GET['success']] ?? '';
}

// Visit count for view page
$visits = 0;
if ($action === 'view' && $id) {
    $vs = mysqli_prepare($conn, "SELECT COUNT(*) FROM examination_history WHERE patient_id = ?");
    mysqli_stmt_bind_param($vs, 'i', $id);
    mysqli_stmt_execute($vs);
    $visits = mysqli_fetch_row(mysqli_stmt_get_result($vs))[0];

    $exam_stmt = mysqli_prepare($conn, "SELECT e.exam_date, e.diagnosis, e.symptoms, d.name doctor_name FROM examination_history e JOIN doctor d ON e.doctor_id = d.doctor_id WHERE e.patient_id = ? ORDER BY e.exam_date DESC LIMIT 5");
    mysqli_stmt_bind_param($exam_stmt, 'i', $id);
    mysqli_stmt_execute($exam_stmt);
    $exams = mysqli_stmt_get_result($exam_stmt);

    $appt_stmt = mysqli_prepare($conn, "SELECT a.appointment_date, a.status, a.queue_number, d.name doctor_name FROM appointment a JOIN doctor d ON a.doctor_id = d.doctor_id WHERE a.patient_id = ? ORDER BY a.appointment_date DESC LIMIT 5");
    mysqli_stmt_bind_param($appt_stmt, 'i', $id);
    mysqli_stmt_execute($appt_stmt);
    $appts = mysqli_stmt_get_result($appt_stmt);
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Patients — IS Class 2 Healthcare</title>
<link rel="stylesheet" href="includes/theme.css">
<style>
.search-bar { display:flex; gap:10px; margin-bottom:20px; align-items:center; }
.search-bar input { flex:1; max-width:320px; }
.pagination { display:flex; gap:6px; margin-top:20px; align-items:center; justify-content:flex-end; padding:0 20px 16px; }
.pagination a, .pagination span { padding:5px 12px; border-radius:var(--radius-sm); font-size:12px; border:1px solid var(--border); color:var(--text-muted); text-decoration:none; }
.pagination a:hover { background:var(--surface2); color:var(--text); }
.pagination .current { background:var(--accent-bg); color:var(--accent-text); border-color:transparent; }

.avatar { width:38px; height:38px; border-radius:50%; background:linear-gradient(135deg,var(--accent),var(--accent-h)); display:flex; align-items:center; justify-content:center; font-size:14px; font-weight:700; color:#1c1200; flex-shrink:0; }
.patient-cell { display:flex; align-items:center; gap:12px; }
.age-chip { display:inline-block; font-size:11px; padding:2px 8px; border-radius:20px; background:var(--accent-bg); color:var(--accent-text); margin-left:6px; font-weight:600; vertical-align:middle; white-space:nowrap; }
.patient-id { font-size:11px; color:var(--text-faint, #6b5a7a); margin-top:1px; }

/* More breathing room in table rows */
.data-table td { padding:14px 18px !important; color:var(--text); vertical-align:middle; }
.data-table th { padding:11px 18px !important; }

/* Detail page */
.detail-grid { display:grid; grid-template-columns:1fr 1fr; gap:22px 32px; margin-bottom:8px; }
.detail-item { padding:4px 0; }
.detail-item label { font-size:10px; text-transform:uppercase; letter-spacing:.07em; color:var(--text-muted); display:block; margin-bottom:6px; font-weight:700; }
.detail-item span { font-size:14px; color:var(--text); font-weight:500; }

.btn-ghost { background:transparent; border:1.5px solid var(--border); color:var(--text); padding:9px 18px; border-radius:var(--radius-sm); font-family:'DM Sans',sans-serif; font-size:13px; font-weight:500; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; gap:6px; transition:all 0.15s; }
.btn-ghost:hover { background:var(--surface2); border-color:var(--border-h); }
.btn-ghost.btn-sm { padding:5px 12px; font-size:11px; }

.form-input { width:100%; padding:11px 14px; background:var(--surface2); border:1.5px solid var(--border); border-radius:var(--radius-sm); font-family:'DM Sans',sans-serif; font-size:13px; color:var(--text); transition:border-color 0.2s,box-shadow 0.2s; outline:none; box-sizing:border-box; }
.form-input:focus { border-color:var(--accent); box-shadow:0 0 0 3px rgba(245,158,11,0.10); }
.form-input option { background:var(--surface); color:var(--text); }

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

    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php /* ═══════════════ LIST ═══════════════ */ ?>
    <?php if ($action === 'list'): ?>

    <div class="page-header">
        <div>
            <h2><?= icon('patients') ?> Patients <span style="font-size:13px;font-weight:500;color:var(--text-muted);margin-left:8px;"><?= $total ?> patient<?= $total != 1 ? 's' : '' ?></span></h2>
        </div>
        <a href="patients.php?action=add" class="btn btn-primary">+ Add Patient</a>
    </div>

    <div class="search-bar">
        <form method="GET" style="display:flex;gap:10px;flex:1;">
            <input type="hidden" name="action" value="list">
            <input type="text" name="q" class="form-input" placeholder="Search by name, phone, or address…" value="<?= htmlspecialchars($search) ?>">
            <button type="submit" class="btn btn-outline">Search</button>
            <?php if ($search): ?><a href="patients.php" class="btn btn-ghost">Clear</a><?php endif; ?>
        </form>
    </div>

    <div class="panel">
        <?php if (mysqli_num_rows($patients) > 0): ?>
        <table class="data-table">
            <colgroup>
                <col style="width:200px;">
                <col style="width:80px;">
                <col style="width:160px;">
                <col style="width:140px;">
                <col style="width:130px;">
                <col style="width:120px;">
                <col style="width:180px;">
            </colgroup>
            <thead><tr>
                <th>Patient</th>
                <th>Gender</th>
                <th>Date of Birth</th>
                <th>Phone</th>
                <th>Address</th>
                <th>Account</th>
                <th>Actions</th>
            </tr></thead>
            <tbody>
            <?php while ($row = mysqli_fetch_assoc($patients)): ?>
            <tr>
                <td>
                    <div class="patient-cell">
                        <div class="avatar"><?= strtoupper(substr($row['name'], 0, 1)) ?></div>
                        <div>
                            <div><?= htmlspecialchars($row['name']) ?></div>
                            <div style="font-size:11px;color:var(--text-faint,#6b5a7a);margin-top:1px;">#<?= $row['patient_id'] ?></div>
                        </div>
                    </div>
                </td>
                <td><?= htmlspecialchars($row['gender'] ?? '—') ?></td>
                <td style="white-space:nowrap;">
                    <?php if ($row['date_of_birth']): ?>
                        <?= date('d M Y', strtotime($row['date_of_birth'])) ?><span class="age-chip"><?= floor((time() - strtotime($row['date_of_birth'])) / 31557600) ?> yo</span>
                    <?php else: ?>—<?php endif; ?>
                </td>
                <td><?= htmlspecialchars($row['phone'] ?? '—') ?></td>
                <td><?= htmlspecialchars($row['address'] ?? '—') ?></td>
                <td>
                    <?php if ($row['username']): ?>
                        <span class="badge badge-scheduled"><?= htmlspecialchars($row['username']) ?></span>
                    <?php else: ?>
                        <span style="color:var(--text-muted);font-size:12px;">No account</span>
                    <?php endif; ?>
                </td>
                <td>
                    <div style="display:flex;gap:6px;">
                        <a href="patients.php?action=view&id=<?= $row['patient_id'] ?>" class="btn btn-ghost btn-sm">View</a>
                        <a href="patients.php?action=edit&id=<?= $row['patient_id'] ?>" class="btn btn-outline btn-sm">Edit</a>
                        <a href="patients.php?action=delete&id=<?= $row['patient_id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete <?= htmlspecialchars(addslashes($row['name'])) ?>? This cannot be undone.')">Delete</a>
                    </div>
                </td>
            </tr>
            <?php endwhile; ?>
            </tbody>
        </table>

        <?php if ($total_pages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
                <a href="?action=list&q=<?= urlencode($search) ?>&page=<?= $page-1 ?>">‹ Prev</a>
            <?php endif; ?>
            <?php for ($p = 1; $p <= $total_pages; $p++): ?>
                <?php if ($p === $page): ?>
                    <span class="current"><?= $p ?></span>
                <?php else: ?>
                    <a href="?action=list&q=<?= urlencode($search) ?>&page=<?= $p ?>"><?= $p ?></a>
                <?php endif; ?>
            <?php endfor; ?>
            <?php if ($page < $total_pages): ?>
                <a href="?action=list&q=<?= urlencode($search) ?>&page=<?= $page+1 ?>">Next ›</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php else: ?>
            <div class="empty-state">
                <?= $search ? "No patients found for \"".htmlspecialchars($search)."\"" : "No patients registered yet." ?>
            </div>
        <?php endif; ?>
    </div>

    <?php /* ═══════════════ ADD / EDIT FORM ═══════════════ */ ?>
    <?php elseif ($action === 'add' || $action === 'edit'): ?>

    <div class="page-header">
        <div>
            <h2><?= icon('users', 14) ?> <?= $action === 'add' ? 'Add Patient' : 'Edit Patient' ?></h2>
            <p class="page-sub"><?= $action === 'add' ? 'Register a new patient' : 'Update patient information' ?></p>
        </div>
        <a href="patients.php" class="btn btn-ghost">← Back</a>
    </div>

    <div class="panel" style="max-width:600px;">
        <div style="padding:24px;">
        <form method="POST">
            <div class="form-group">
                <label class="form-label">Full Name <span style="color:#f87171;">*</span></label>
                <input type="text" name="name" class="form-input" placeholder="e.g. John Doe" value="<?= htmlspecialchars($patient['name'] ?? '') ?>" required>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                <div class="form-group">
                    <label class="form-label">Gender</label>
                    <select name="gender" class="form-input">
                        <option value="">— Select —</option>
                        <?php foreach (['Male','Female','Other'] as $g): ?>
                            <option value="<?= $g ?>" <?= ($patient['gender'] ?? '') === $g ? 'selected' : '' ?>><?= $g ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Date of Birth</label>
                    <input type="date" name="dob" class="form-input" value="<?= htmlspecialchars($patient['date_of_birth'] ?? '') ?>" min="1900-01-01" max="<?= date('Y-m-d') ?>">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Phone</label>
                <input type="text" name="phone" class="form-input" placeholder="e.g. 0812345678" value="<?= htmlspecialchars($patient['phone'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label class="form-label">Address</label>
                <textarea name="address" class="form-input" rows="3" placeholder="Full address…"><?= htmlspecialchars($patient['address'] ?? '') ?></textarea>
            </div>

            <div style="display:flex;gap:10px;margin-top:8px;">
                <button type="submit" class="btn btn-primary"><?= $action === 'add' ? 'Add Patient' : 'Save Changes' ?></button>
                <a href="patients.php" class="btn btn-ghost">Cancel</a>
            </div>
        </form>
        </div><!-- /padding -->
    </div>

    <?php /* ═══════════════ VIEW / DETAIL ═══════════════ */ ?>
    <?php elseif ($action === 'view' && $patient): ?>

    <div class="page-header">
        <div>
            <h2><?= icon('patients') ?> Patient Detail</h2>
            <p class="page-sub"><?= htmlspecialchars($patient['name']) ?></p>
        </div>
        <div style="display:flex;gap:8px;">
            <a href="patients.php?action=edit&id=<?= $id ?>" class="btn btn-outline">Edit</a>
            <a href="patients.php" class="btn btn-ghost">← Back</a>
        </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">

        <!-- Info Card -->
        <div class="panel">
            <div style="display:flex;align-items:center;gap:16px;padding:20px 20px 0;">
                <div class="avatar" style="width:52px;height:52px;font-size:20px;"><?= strtoupper(substr($patient['name'],0,1)) ?></div>
                <div>
                    <div style="font-size:16px;font-weight:700;"><?= htmlspecialchars($patient['name']) ?></div>
                    <div style="font-size:12px;color:var(--text-muted);margin-top:2px;">Patient #<?= $patient['patient_id'] ?></div>
                </div>
            </div>
            <div style="padding:20px;">
                <div class="detail-grid">
                <div class="detail-item">
                    <label>Gender</label>
                    <span><?= htmlspecialchars($patient['gender'] ?? '—') ?></span>
                </div>
                <div class="detail-item">
                    <label>Date of Birth</label>
                    <span>
                        <?php if ($patient['date_of_birth']): ?>
                            <?= date('d M Y', strtotime($patient['date_of_birth'])) ?>
                            <span class="age-chip"><?= floor((time() - strtotime($patient['date_of_birth'])) / 31557600) ?> yo</span>
                        <?php else: ?>—<?php endif; ?>
                    </span>
                </div>
                <div class="detail-item">
                    <label>Phone</label>
                    <span><?= htmlspecialchars($patient['phone'] ?? '—') ?></span>
                </div>
                <div class="detail-item">
                    <label>Registered</label>
                    <span><?= date('d M Y', strtotime($patient['created_at'])) ?></span>
                </div>
                <div class="detail-item" style="grid-column:1/-1;">
                    <label>Address</label>
                    <span><?= htmlspecialchars($patient['address'] ?? '—') ?></span>
                </div>
                <div class="detail-item">
                    <label>Account</label>
                    <span><?= $patient['username'] ? htmlspecialchars($patient['username']) : '<em style="color:var(--text-muted)">No account</em>' ?></span>
                </div>
                <div class="detail-item">
                    <label>Total Visits</label>
                    <span><?= $visits ?></span>
                </div>
                </div><!-- /detail-grid -->
            </div><!-- /padding -->
        </div>

        <!-- Recent Appointments -->
        <div class="panel">
            <div class="panel-header">
                <div class="panel-title">Recent Appointments</div>
                <a href="appointments.php?patient_id=<?= $id ?>" class="panel-link">View all →</a>
            </div>
            <?php if (mysqli_num_rows($appts) > 0): ?>
            <table class="data-table">
                <thead><tr><th>Doctor</th><th>Date</th><th>Status</th></tr></thead>
                <tbody>
                <?php while ($a = mysqli_fetch_assoc($appts)): ?>
                <tr>
                    <td><?= htmlspecialchars($a['doctor_name']) ?></td>
                    <td><?= date('d M Y', strtotime($a['appointment_date'])) ?></td>
                    <td><span class="badge badge-<?= strtolower($a['status']) ?>"><?= $a['status'] ?></span></td>
                </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
            <?php else: ?>
                <div class="empty-state">No appointments yet</div>
            <?php endif; ?>
        </div>

        <!-- Recent Examinations -->
        <div class="panel" style="grid-column:1/-1;">
            <div class="panel-header">
                <div class="panel-title">Recent Examinations</div>
                <a href="examinations.php?patient_id=<?= $id ?>" class="panel-link">View all →</a>
            </div>
            <?php if (mysqli_num_rows($exams) > 0): ?>
            <table class="data-table">
                <thead><tr><th>Date</th><th>Doctor</th><th>Symptoms</th><th>Diagnosis</th></tr></thead>
                <tbody>
                <?php while ($e = mysqli_fetch_assoc($exams)): ?>
                <tr>
                    <td><?= date('d M Y', strtotime($e['exam_date'])) ?></td>
                    <td><?= htmlspecialchars($e['doctor_name']) ?></td>
                    <td><?= htmlspecialchars($e['symptoms'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($e['diagnosis'] ?? '—') ?></td>
                </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
            <?php else: ?>
                <div class="empty-state">No examination records</div>
            <?php endif; ?>
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
