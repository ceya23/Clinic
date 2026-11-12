<?php
require_once 'includes/config.php';
requireLogin();
if (isPatient()) { header("Location: dashboard.php"); exit(); }

$error   = '';
$success = '';
$action  = $_GET['action'] ?? 'list';
$id      = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$appt_id = isset($_GET['appointment_id']) ? (int)$_GET['appointment_id'] : 0;

// ── DELETE EXAM ───────────────────────────────────────────────────────────────
if ($action === 'delete' && $id && isAdmin()) {
    $stmt = mysqli_prepare($conn, "DELETE FROM examination_history WHERE exam_id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    header("Location: examinations.php?success=deleted"); exit();
}

// ── DELETE PRESCRIPTION ───────────────────────────────────────────────────────
if ($action === 'delete_prescription' && $id) {
    $exam_id = (int)($_GET['exam_id'] ?? 0);
    // Stock is restored automatically by trg_restore_stock trigger (AFTER DELETE)
    $stmt = mysqli_prepare($conn, "DELETE FROM prescription WHERE prescription_id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    header("Location: examinations.php?action=view&id=$exam_id&success=prescription_deleted"); exit();
}

// ── SAVE EXAM ─────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_exam'])) {
    $patient_id    = (int)($_POST['patient_id']    ?? 0);
    $doctor_id     = (int)($_POST['doctor_id']     ?? 0);
    $appointment_id = !empty($_POST['appointment_id']) ? (int)$_POST['appointment_id'] : null;
    $exam_date     = $_POST['exam_date']  ?? date('Y-m-d');
    $symptoms      = trim($_POST['symptoms']   ?? '');
    $diagnosis     = trim($_POST['diagnosis']  ?? '');
    $notes         = trim($_POST['notes']      ?? '');

    if (isDoctor()) $doctor_id = getDoctorId($conn);

    if (!$patient_id || !$doctor_id) {
        $error = 'Patient and doctor are required.';
    } else {
        if ($action === 'add') {
            $stmt = mysqli_prepare($conn, "INSERT INTO examination_history (appointment_id, patient_id, doctor_id, exam_date, symptoms, diagnosis, notes) VALUES (?, ?, ?, ?, ?, ?, ?)");
            mysqli_stmt_bind_param($stmt, 'iiissss', $appointment_id, $patient_id, $doctor_id, $exam_date, $symptoms, $diagnosis, $notes);
            mysqli_stmt_execute($stmt);
            $new_exam_id = mysqli_insert_id($conn);
            // Mark appointment as completed
            if ($appointment_id) {
                mysqli_query($conn, "UPDATE appointment SET status='Completed' WHERE appointment_id=$appointment_id");
            }
            header("Location: examinations.php?action=view&id=$new_exam_id&success=added"); exit();
        } elseif ($action === 'edit' && $id) {
            $stmt = mysqli_prepare($conn, "UPDATE examination_history SET patient_id=?, doctor_id=?, exam_date=?, symptoms=?, diagnosis=?, notes=? WHERE exam_id=?");
            mysqli_stmt_bind_param($stmt, 'iissssi', $patient_id, $doctor_id, $exam_date, $symptoms, $diagnosis, $notes, $id);
            mysqli_stmt_execute($stmt);
            header("Location: examinations.php?action=view&id=$id&success=updated"); exit();
        }
    }
}

// ── SAVE PRESCRIPTION ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_prescription'])) {
    $exam_id      = (int)($_POST['exam_id']      ?? 0);
    $medication_id = (int)($_POST['medication_id'] ?? 0);
    $dosage       = trim($_POST['dosage']        ?? '');
    $quantity     = (int)($_POST['quantity']     ?? 1);

    // Check stock
    $med = mysqli_fetch_assoc(mysqli_query($conn, "SELECT stock, name FROM medication WHERE medication_id = $medication_id"));
    if (!$med) {
        $error = 'Medication not found.';
    } elseif ($med['stock'] < $quantity) {
        $error = "Insufficient stock for {$med['name']}. Available: {$med['stock']} units.";
    } else {
        $stmt = mysqli_prepare($conn, "INSERT INTO prescription (exam_id, medication_id, dosage, quantity) VALUES (?, ?, ?, ?)");
        mysqli_stmt_bind_param($stmt, 'iisi', $exam_id, $medication_id, $dosage, $quantity);
        mysqli_stmt_execute($stmt);
        header("Location: examinations.php?action=view&id=$exam_id&success=prescription_added"); exit();
    }
    $action = 'view'; $id = $exam_id;
}

// ── FETCH SINGLE ──────────────────────────────────────────────────────────────
$exam = null;
if (in_array($action, ['view','edit']) && $id) {
    $exam = mysqli_fetch_assoc(mysqli_query($conn, "
        SELECT e.*, p.name patient_name, d.name doctor_name, d.specialization
        FROM examination_history e
        JOIN patient p ON e.patient_id = p.patient_id
        JOIN doctor  d ON e.doctor_id  = d.doctor_id
        WHERE e.exam_id = $id
    "));
    if (!$exam) { header("Location: examinations.php"); exit(); }
}

// Pre-fill from appointment
$appointment = null;
if ($action === 'add' && $appt_id) {
    $appointment = mysqli_fetch_assoc(mysqli_query($conn, "
        SELECT a.*, p.name patient_name, d.name doctor_name
        FROM appointment a
        JOIN patient p ON a.patient_id = p.patient_id
        JOIN doctor  d ON a.doctor_id  = d.doctor_id
        WHERE a.appointment_id = $appt_id
    "));
}

// ── FETCH LIST ────────────────────────────────────────────────────────────────
$where = isDoctor() ? "WHERE e.doctor_id = " . getDoctorId($conn) : "";
$exams = mysqli_query($conn, "
    SELECT e.*, p.name patient_name, d.name doctor_name
    FROM examination_history e
    JOIN patient p ON e.patient_id = p.patient_id
    JOIN doctor  d ON e.doctor_id  = d.doctor_id
    $where
    ORDER BY e.exam_date DESC, e.exam_id DESC
");

// Fetch prescriptions for view
$prescriptions = null;
if ($action === 'view' && $id) {
    $prescriptions = mysqli_query($conn, "
        SELECT pr.*, m.name medication_name, m.type, m.price
        FROM prescription pr
        JOIN medication m ON pr.medication_id = m.medication_id
        WHERE pr.exam_id = $id
    ");
}

// Payment for view
$payment = null;
if ($action === 'view' && $id) {
    $payment = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM payment WHERE exam_id = $id"));
}

$patients  = mysqli_query($conn, "SELECT patient_id, name FROM patient ORDER BY name");
$doctors   = mysqli_query($conn, "SELECT doctor_id, name, specialization FROM doctor ORDER BY name");
$meds      = mysqli_query($conn, "SELECT medication_id, name, type, stock, price FROM medication WHERE stock > 0 ORDER BY name");
$dosages   = ['Once daily','Twice daily','Three times daily','Four times daily','Every 8 hours','Every 6 hours','As needed'];
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Examinations — IS Class 2 Healthcare</title>
<link rel="stylesheet" href="includes/theme.css">
<style>
.exam-card {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: var(--radius-md); padding: 18px 20px;
    display: flex; align-items: center; gap: 16px;
    transition: border-color .2s; margin-bottom: 8px;
}
.exam-card:hover { border-color: var(--border-h); }
.exam-date-box {
    width: 54px; height: 54px; border-radius: var(--radius-sm);
    background: var(--accent-bg); display: flex; flex-direction: column;
    align-items: center; justify-content: center; flex-shrink: 0;
}
.exam-date-box .day { font-size: 20px; font-weight: 600; color: var(--accent-h); line-height: 1; }
.exam-date-box .mon { font-size: 10px; color: var(--accent-text); text-transform: uppercase; }
.exam-info { flex: 1; min-width: 0; }
.exam-patient { font-size: 14px; font-weight: 500; color: var(--text); }
.exam-diagnosis {
    font-size: 12px; color: var(--accent-h); background: var(--accent-bg);
    padding: 2px 8px; border-radius: 20px; display: inline-block; margin-top: 4px;
}
.exam-doctor { font-size: 11px; color: var(--text-muted); margin-top: 4px; }

.view-grid { display: grid; grid-template-columns: 1fr 340px; gap: 20px; }
.info-row { display: flex; flex-direction: column; padding: 12px 0; border-bottom: 1px solid var(--border); }
.info-row:last-child { border: none; }
.info-label { font-size: 10px; text-transform: uppercase; letter-spacing: .06em; color: var(--text-muted); margin-bottom: 4px; }
.info-value { font-size: 13px; color: var(--text); line-height: 1.5; }
.presc-row {
    display: flex; align-items: center; gap: 10px;
    padding: 10px 16px; border-bottom: 1px solid var(--border); font-size: 13px;
}
.presc-row:last-child { border: none; }
.med-icon { font-size: 16px; }
.add-presc-form { padding: 16px 20px; background: var(--surface2); border-top: 1px solid var(--border); }
.appt-info-box {
    background: var(--accent-bg); border: 1px solid var(--accent);
    border-radius: var(--radius-sm); padding: 12px 16px;
    font-size: 13px; margin-bottom: 20px;
}
.appt-info-box strong { color: var(--accent-h); }

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
            <?php $msgs = ['added'=>'Examination recorded!','updated'=>'Examination updated!','deleted'=>'Examination deleted!','prescription_added'=>'Prescription added!','prescription_deleted'=>'Prescription removed.']; echo $msgs[$_GET['success']] ?? 'Done!'; ?>
        </div>
    <?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <?php /* ── LIST ── */ if ($action === 'list'): ?>

        <div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
            <div>
                <h2><?= icon('examinations') ?> Examinations</h2>
                <p class="page-sub">Medical records & examination history</p>
            </div>
            <a href="examinations.php?action=add" class="btn btn-primary"><?= icon('examinations', 14) ?> New Examination</a>
        </div>

        <?php if (mysqli_num_rows($exams) === 0): ?>
            <div class="panel"><div class="empty-state">No examinations found.</div></div>
        <?php else: while ($row = mysqli_fetch_assoc($exams)): ?>
        <div class="exam-card">
            <div class="exam-date-box">
                <div class="day"><?= date('d', strtotime($row['exam_date'])) ?></div>
                <div class="mon"><?= date('M', strtotime($row['exam_date'])) ?></div>
            </div>
            <div class="exam-info">
                <div class="exam-patient"><?= icon('patients', 14) ?> <?= htmlspecialchars($row['patient_name']) ?></div>
                <span class="exam-diagnosis">🔬 <?= htmlspecialchars($row['diagnosis'] ?? 'No diagnosis') ?></span>
                <div class="exam-doctor"><?= htmlspecialchars($row['doctor_name']) ?></div>
            </div>
            <div style="font-size:11px;color:var(--text-muted);text-align:right;margin-right:8px;">
                <?= htmlspecialchars(substr($row['symptoms'] ?? '', 0, 40)) ?><?= strlen($row['symptoms'] ?? '') > 40 ? '...' : '' ?>
            </div>
            <div style="display:flex;gap:6px;">
                <a href="examinations.php?action=view&id=<?= $row['exam_id'] ?>" class="btn btn-outline btn-sm"><?= icon('records', 13) ?> View</a>
                <?php if (isAdmin()): ?>
                <a href="examinations.php?action=edit&id=<?= $row['exam_id'] ?>" class="btn btn-outline btn-sm"><?= icon('records', 13) ?></a>
                <a href="examinations.php?action=delete&id=<?= $row['exam_id'] ?>"
                   class="btn btn-danger btn-sm"
                   onclick="return confirm('Delete this examination record?')">🗑</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endwhile; endif; ?>

    <?php /* ── ADD / EDIT ── */ elseif ($action === 'add' || ($action === 'edit' && $exam)): ?>

        <div class="page-header">
            <h2><?= icon('examinations', 14) ?> <?= $action === 'add' ? 'New Examination' : 'Edit Examination' ?></h2>
            <p class="page-sub"><?= $action === 'add' ? 'Record a new patient examination' : 'Update examination record' ?></p>
        </div>

        <?php if ($appointment): ?>
        <div class="appt-info-box">
            <?= icon('appointments', 13) ?> Appointment: <strong><?= htmlspecialchars($appointment['patient_name']) ?></strong>
            with <strong><?= htmlspecialchars($appointment['doctor_name']) ?></strong>
            on <?= date('d M Y H:i', strtotime($appointment['appointment_date'])) ?>
        </div>
        <?php endif; ?>

        <div class="panel" style="max-width:600px;">
            <div class="panel-header"><div class="panel-title"><?= icon('examinations', 14) ?> Examination Details</div></div>
            <div style="padding:24px;">
                <form method="POST">
                    <input type="hidden" name="appointment_id" value="<?= $appt_id ?: ($exam['appointment_id'] ?? '') ?>">
                    <?php if (!isDoctor()): ?>
                    <div class="form-group">
                        <label class="form-label">Doctor <span style="color:var(--danger)">*</span></label>
                        <select name="doctor_id" class="form-control" required>
                            <option value="">Select doctor</option>
                            <?php foreach (mysqli_fetch_all($doctors, MYSQLI_ASSOC) as $d): ?>
                            <option value="<?= $d['doctor_id'] ?>"
                                <?= (($exam['doctor_id'] ?? $appointment['doctor_id'] ?? 0) == $d['doctor_id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($d['name']) ?> — <?= $d['specialization'] ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <div class="form-group">
                        <label class="form-label">Patient <span style="color:var(--danger)">*</span></label>
                        <select name="patient_id" class="form-control" required>
                            <option value="">Select patient</option>
                            <?php foreach (mysqli_fetch_all($patients, MYSQLI_ASSOC) as $p): ?>
                            <option value="<?= $p['patient_id'] ?>"
                                <?= (($exam['patient_id'] ?? $appointment['patient_id'] ?? 0) == $p['patient_id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($p['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Examination Date</label>
                        <input type="date" name="exam_date" class="form-control"
                            value="<?= $exam['exam_date'] ?? date('Y-m-d') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Symptoms</label>
                        <textarea name="symptoms" class="form-control" rows="2"
                            placeholder="Describe patient symptoms..."><?= htmlspecialchars($exam['symptoms'] ?? '') ?></textarea>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Diagnosis</label>
                        <input type="text" name="diagnosis" class="form-control"
                            placeholder="e.g. Flu, Migraine, Cavity..."
                            value="<?= htmlspecialchars($exam['diagnosis'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Doctor's Notes</label>
                        <textarea name="notes" class="form-control" rows="2"
                            placeholder="Treatment notes, recommendations..."><?= htmlspecialchars($exam['notes'] ?? '') ?></textarea>
                    </div>
                    <div style="display:flex;gap:10px;margin-top:8px;">
                        <button type="submit" name="save_exam" class="btn btn-primary">
                            <?= $action === 'add' ? 'Save Examination' : 'Save Changes' ?>
                        </button>
                        <a href="examinations.php" class="btn btn-outline">Cancel</a>
                    </div>
                </form>
            </div>
        </div>

    <?php /* ── VIEW ── */ elseif ($action === 'view' && $exam): ?>

        <div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
            <div>
                <h2><?= icon('examinations') ?> Examination Record</h2>
                <p class="page-sub"><?= htmlspecialchars($exam['patient_name']) ?> — <?= date('d M Y', strtotime($exam['exam_date'])) ?></p>
            </div>
            <div style="display:flex;gap:8px;">
                <?php if (isAdmin()): ?>
                <a href="examinations.php?action=edit&id=<?= $id ?>" class="btn btn-outline"><?= icon('records', 13) ?> Edit</a>
                <?php endif; ?>
                <a href="examinations.php" class="btn btn-outline">← Back</a>
            </div>
        </div>

        <div class="view-grid">
            <!-- Left: exam details -->
            <div>
                <div class="panel" style="margin-bottom:16px;">
                    <div class="panel-header"><div class="panel-title"><?= icon('records', 14) ?> Medical Details</div></div>
                    <div style="padding:4px 20px 8px;">
                        <div class="info-row">
                            <span class="info-label">Patient</span>
                            <span class="info-value"><?= icon('patients', 14) ?> <?= htmlspecialchars($exam['patient_name']) ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Doctor</span>
                            <span class="info-value"><?= htmlspecialchars($exam['doctor_name']) ?> <span style="color:var(--text-muted);font-size:11px;">(<?= htmlspecialchars($exam['specialization']) ?>)</span></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Date</span>
                            <span class="info-value"><?= icon('appointments', 13) ?> <?= date('d F Y', strtotime($exam['exam_date'])) ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Symptoms</span>
                            <span class="info-value"><?= htmlspecialchars($exam['symptoms'] ?? '—') ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Diagnosis</span>
                            <span class="info-value" style="color:var(--accent-h);font-weight:500;">🔬 <?= htmlspecialchars($exam['diagnosis'] ?? '—') ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Doctor's Notes</span>
                            <span class="info-value"><?= htmlspecialchars($exam['notes'] ?? '—') ?></span>
                        </div>
                    </div>
                </div>

                <!-- Payment info -->
                <?php if ($payment): ?>
                <div class="panel">
                    <div class="panel-header"><div class="panel-title"><?= icon('payments', 14) ?> Payment</div></div>
                    <div style="padding:12px 20px;display:flex;justify-content:space-between;align-items:center;">
                        <div>
                            <div style="font-size:18px;font-weight:500;font-family:'Playfair Display',serif;color:var(--text);">
                                Rp <?= number_format($payment['amount'], 0, ',', '.') ?>
                            </div>
                            <div style="font-size:11px;color:var(--text-muted);margin-top:2px;">
                                <?= $payment['method'] ?> — <?= date('d M Y', strtotime($payment['payment_date'])) ?>
                            </div>
                        </div>
                        <span class="badge badge-<?= strtolower($payment['status']) ?>"><?= $payment['status'] ?></span>
                    </div>
                </div>
                <?php else: ?>
                <div class="panel">
                    <div class="panel-header"><div class="panel-title"><?= icon('payments', 14) ?> Payment</div></div>
                    <div style="padding:14px 20px;display:flex;align-items:center;justify-content:space-between;">
                        <span style="font-size:13px;color:var(--text-muted);">No payment recorded yet.</span>
                        <?php if (isAdmin()): ?>
                        <a href="payments.php?action=add&exam_id=<?= $id ?>" class="btn btn-primary btn-sm"><?= icon('payments', 14) ?> Add Payment</a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Right: prescriptions -->
            <div class="panel" style="height:fit-content;">
                <div class="panel-header">
                    <div class="panel-title"><?= icon('medications', 14) ?> Prescriptions</div>
                </div>
                <?php
                $presc_rows = mysqli_fetch_all($prescriptions, MYSQLI_ASSOC);
                if (count($presc_rows) > 0):
                    foreach ($presc_rows as $pr):
                ?>
                <div class="presc-row">
                    <span class="med-icon"><?= icon('medications', 14) ?></span>
                    <div style="flex:1;">
                        <div style="font-weight:500;font-size:13px;"><?= htmlspecialchars($pr['medication_name']) ?></div>
                        <div style="font-size:11px;color:var(--text-muted);"><?= $pr['dosage'] ?> &bull; <?= $pr['quantity'] ?> units</div>
                    </div>
                    <div style="font-size:11px;color:var(--text-muted);text-align:right;margin-right:8px;">
                        Rp <?= number_format($pr['price'] * $pr['quantity'], 0, ',', '.') ?>
                    </div>
                    <a href="examinations.php?action=delete_prescription&id=<?= $pr['prescription_id'] ?>&exam_id=<?= $id ?>"
                       class="btn btn-danger btn-sm"
                       onclick="return confirm('Remove this prescription?')">✕</a>
                </div>
                <?php endforeach; else: ?>
                    <div class="empty-state" style="padding:20px;">No prescriptions yet.</div>
                <?php endif; ?>

                <!-- Add prescription form -->
                <div class="add-presc-form">
                    <div style="font-size:12px;font-weight:500;color:var(--text);margin-bottom:12px;"><?= icon('appointments', 13) ?> Add Prescription</div>
                    <form method="POST">
                        <input type="hidden" name="exam_id" value="<?= $id ?>">
                        <div class="form-group">
                            <label class="form-label">Medication</label>
                            <select name="medication_id" class="form-control" required>
                                <option value="">Select medication</option>
                                <?php foreach (mysqli_fetch_all($meds, MYSQLI_ASSOC) as $m): ?>
                                <option value="<?= $m['medication_id'] ?>">
                                    <?= htmlspecialchars($m['name']) ?> (<?= $m['type'] ?>) — <?= $m['stock'] ?> left
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 70px;gap:10px;">
                            <div class="form-group">
                                <label class="form-label">Dosage</label>
                                <select name="dosage" class="form-control" required>
                                    <?php foreach ($dosages as $d): ?>
                                    <option value="<?= $d ?>"><?= $d ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Qty</label>
                                <input type="number" name="quantity" class="form-control" value="1" min="1" required>
                            </div>
                        </div>
                        <button type="submit" name="save_prescription" class="btn btn-primary" style="width:100%;"><?= icon('appointments', 13) ?> Add</button>
                    </form>
                </div>
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
