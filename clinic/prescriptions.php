<?php
require_once 'includes/config.php';
requireLogin();
if (isPatient()) { header("Location: dashboard.php"); exit(); }

$error   = '';
$success = '';
$action  = $_GET['action'] ?? 'list';
$id      = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// ── DELETE PRESCRIPTION ───────────────────────────────────────────────────────
if ($action === 'delete' && $id && isAdmin()) {
    // Stock is restored automatically by trg_restore_stock trigger (AFTER DELETE)
    $stmt = mysqli_prepare($conn, "DELETE FROM prescription WHERE prescription_id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    header("Location: prescriptions.php?success=deleted"); exit();
}

// ── ADD PRESCRIPTION ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_prescription'])) {
    $exam_id       = (int)($_POST['exam_id']       ?? 0);
    $medication_id = (int)($_POST['medication_id'] ?? 0);
    $dosage        = trim($_POST['dosage']          ?? '');
    $quantity      = (int)($_POST['quantity']       ?? 1);

    if (!$exam_id || !$medication_id || !$dosage || $quantity < 1) {
        $error = 'All fields are required and quantity must be at least 1.';
    } else {
        $med = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT name, stock FROM medication WHERE medication_id = $medication_id"
        ));
        if (!$med) {
            $error = 'Medication not found.';
        } elseif ($med['stock'] < $quantity) {
            $error = "Insufficient stock for {$med['name']}. Available: {$med['stock']} units.";
        } else {
            // Trigger trg_reduce_stock fires automatically on INSERT
            $stmt = mysqli_prepare($conn,
                "INSERT INTO prescription (exam_id, medication_id, dosage, quantity) VALUES (?, ?, ?, ?)"
            );
            mysqli_stmt_bind_param($stmt, 'iisi', $exam_id, $medication_id, $dosage, $quantity);
            mysqli_stmt_execute($stmt);
            header("Location: prescriptions.php?success=added"); exit();
        }
    }
}

// ── FETCH SINGLE ──────────────────────────────────────────────────────────────
$presc = null;
if ($action === 'view' && $id) {
    $presc = mysqli_fetch_assoc(mysqli_query($conn, "
        SELECT pr.*,
               m.name  medication_name, m.type  medication_type,
               m.price medication_price, m.stock medication_stock,
               e.exam_date, e.diagnosis, e.symptoms, e.notes exam_notes,
               p.name  patient_name,
               d.name  doctor_name, d.specialization
        FROM prescription pr
        JOIN medication m         ON pr.medication_id = m.medication_id
        JOIN examination_history e ON pr.exam_id       = e.exam_id
        JOIN patient p            ON e.patient_id      = p.patient_id
        JOIN doctor  d            ON e.doctor_id       = d.doctor_id
        WHERE pr.prescription_id = $id
    "));
    if (!$presc) { header("Location: prescriptions.php"); exit(); }
}

// ── FILTERS ───────────────────────────────────────────────────────────────────
$filter_med    = $_GET['medication_id'] ?? '';
$filter_dosage = $_GET['dosage']        ?? '';
$where_parts   = [];
if ($filter_med)    $where_parts[] = "pr.medication_id = " . (int)$filter_med;
if ($filter_dosage) $where_parts[] = "pr.dosage = '" . mysqli_real_escape_string($conn, $filter_dosage) . "'";

// Doctor sees only their own patients
if (isDoctor()) {
    $where_parts[] = "e.doctor_id = " . getDoctorId($conn);
}

$where_sql = $where_parts ? 'WHERE ' . implode(' AND ', $where_parts) : '';

// ── FETCH LIST ────────────────────────────────────────────────────────────────
$prescriptions = mysqli_query($conn, "
    SELECT pr.*,
           m.name  medication_name, m.type  medication_type, m.price medication_price,
           p.name  patient_name,
           d.name  doctor_name,
           e.exam_date, e.diagnosis
    FROM prescription pr
    JOIN medication m          ON pr.medication_id = m.medication_id
    JOIN examination_history e ON pr.exam_id        = e.exam_id
    JOIN patient p             ON e.patient_id       = p.patient_id
    JOIN doctor  d             ON e.doctor_id        = d.doctor_id
    $where_sql
    ORDER BY pr.created_at DESC
");

// ── STATS ─────────────────────────────────────────────────────────────────────
$doc_where = isDoctor() ? "JOIN examination_history e2 ON pr2.exam_id = e2.exam_id WHERE e2.doctor_id = " . getDoctorId($conn) : '';
$stats = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT
        COUNT(*)            AS total,
        SUM(pr.quantity)    AS total_units,
        COUNT(DISTINCT pr.medication_id) AS unique_meds,
        COUNT(DISTINCT e.patient_id)     AS unique_patients
    FROM prescription pr
    JOIN examination_history e ON pr.exam_id = e.exam_id
    " . (isDoctor() ? "WHERE e.doctor_id = " . getDoctorId($conn) : "")
));

// ── DROPDOWN DATA ─────────────────────────────────────────────────────────────
$all_meds   = mysqli_query($conn, "SELECT medication_id, name, type, stock, price FROM medication ORDER BY name");
$all_exams  = mysqli_query($conn, "
    SELECT e.exam_id, e.exam_date, e.diagnosis, p.name patient_name
    FROM examination_history e
    JOIN patient p ON e.patient_id = p.patient_id
    " . (isDoctor() ? "WHERE e.doctor_id = " . getDoctorId($conn) : "") . "
    ORDER BY e.exam_date DESC
");
$filter_meds_list = mysqli_query($conn, "
    SELECT DISTINCT m.medication_id, m.name
    FROM prescription pr JOIN medication m ON pr.medication_id = m.medication_id
    ORDER BY m.name
");
$dosages = ['Once daily','Twice daily','Three times daily','Four times daily','Every 8 hours','Every 6 hours','As needed'];
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Prescriptions — IS Class 2 Healthcare</title>
<link rel="stylesheet" href="includes/theme.css">
<style>
/* ── Prescription cards ── */
.presc-card {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: var(--radius-md); padding: 14px 18px;
    display: flex; align-items: center; gap: 14px;
    transition: border-color .2s; margin-bottom: 8px;
}
.presc-card:hover { border-color: var(--border-h); }
.med-pill-icon {
    width: 44px; height: 44px; border-radius: var(--radius-sm);
    background: var(--accent-bg);
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; flex-shrink: 0;
}
.presc-info   { flex: 1; min-width: 0; }
.presc-med    { font-size: 14px; font-weight: 500; color: var(--text); }
.presc-meta   { font-size: 11px; color: var(--text-muted); margin-top: 3px; }
.presc-dosage {
    font-size: 11px; background: var(--surface3); color: var(--info);
    padding: 2px 8px; border-radius: 20px; display: inline-block; margin-top: 4px;
}
.presc-subtotal {
    font-family: 'Playfair Display', serif;
    font-size: 15px; color: var(--text);
    white-space: nowrap; text-align: right; min-width: 100px;
}

/* ── Stats ── */
.stats-row {
    display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px;
    margin-bottom: 24px;
}
.stat-card {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: var(--radius-md); padding: 14px 18px;
}
.stat-label { font-size: 10px; text-transform: uppercase; letter-spacing: .08em; color: var(--text-muted); }
.stat-value { font-size: 22px; font-family: 'Playfair Display', serif; color: var(--text); margin-top: 4px; }
.stat-sub   { font-size: 11px; color: var(--text-muted); margin-top: 2px; }

/* ── View layout ── */
.view-grid { display: grid; grid-template-columns: 1fr 300px; gap: 20px; }
.info-row { display: flex; flex-direction: column; padding: 11px 0; border-bottom: 1px solid var(--border); }
.info-row:last-child { border: none; }
.info-label { font-size: 10px; text-transform: uppercase; letter-spacing: .06em; color: var(--text-muted); margin-bottom: 3px; }
.info-value { font-size: 13px; color: var(--text); line-height: 1.5; }

/* ── Med type badge ── */
.type-badge {
    display: inline-block; padding: 2px 8px;
    border-radius: 20px; font-size: 10px; font-weight: 600;
    text-transform: uppercase; letter-spacing: .05em;
    background: var(--surface2); color: var(--text-muted);
}

/* ── Filter bar ── */
.filter-bar {
    display: flex; gap: 8px; align-items: center; flex-wrap: wrap;
    margin-bottom: 16px;
}
.filter-bar select {
    background: var(--surface); border: 1px solid var(--border);
    color: var(--text); border-radius: var(--radius-sm);
    padding: 6px 10px; font-size: 12px; font-family: 'Nunito', sans-serif;
}

/* ── Add form panel ── */
.add-panel {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: var(--radius-md); overflow: hidden; margin-bottom: 24px;
}
.add-panel-header {
    padding: 14px 20px; border-bottom: 1px solid var(--border);
    font-size: 13px; font-weight: 500; display: flex; align-items: center; gap: 8px;
}

/* ── Stock indicator ── */
.stock-ok   { color: var(--success); }
.stock-low  { color: #f59e0b; }
.stock-none { color: var(--danger); }

@media (max-width: 768px) {
    .stats-row { grid-template-columns: repeat(2, 1fr); }
    .view-grid  { grid-template-columns: 1fr; }
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
        <?php
        $msgs = [
            'added'   => 'Prescription added successfully! Medication stock has been updated.',
            'deleted' => 'Prescription removed. Stock has been restored.',
        ];
        echo $msgs[$_GET['success']] ?? 'Done!';
        ?>
    </div>
    <?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>


    <?php /* ═══════════════════════════════════════════════════
             LIST
           ═══════════════════════════════════════════════════ */
    if ($action === 'list'): ?>

    <div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px;">
        <div>
            <h2><?= icon('medications') ?> Prescriptions</h2>
            <p class="page-sub">All medication prescriptions across examinations</p>
        </div>
        <a href="prescriptions.php?action=add" class="btn btn-primary"><?= icon('medications', 14) ?> New Prescription</a>
    </div>

    <!-- Stats -->
    <div class="stats-row">
        <div class="stat-card">
            <div class="stat-label">Total Prescriptions</div>
            <div class="stat-value"><?= $stats['total'] ?></div>
            <div class="stat-sub">all time</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Units Dispensed</div>
            <div class="stat-value"><?= number_format($stats['total_units']) ?></div>
            <div class="stat-sub">medication units total</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Medications Used</div>
            <div class="stat-value"><?= $stats['unique_meds'] ?></div>
            <div class="stat-sub">unique medications</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Patients Served</div>
            <div class="stat-value"><?= $stats['unique_patients'] ?></div>
            <div class="stat-sub">unique patients</div>
        </div>
    </div>

    <!-- Filters -->
    <form method="GET" class="filter-bar">
        <select name="medication_id" onchange="this.form.submit()">
            <option value="">All medications</option>
            <?php while ($fm = mysqli_fetch_assoc($filter_meds_list)): ?>
            <option value="<?= $fm['medication_id'] ?>" <?= $filter_med == $fm['medication_id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($fm['name']) ?>
            </option>
            <?php endwhile; ?>
        </select>
        <select name="dosage" onchange="this.form.submit()">
            <option value="">All dosages</option>
            <?php foreach ($dosages as $d): ?>
            <option value="<?= $d ?>" <?= $filter_dosage === $d ? 'selected' : '' ?>><?= $d ?></option>
            <?php endforeach; ?>
        </select>
        <?php if ($filter_med || $filter_dosage): ?>
        <a href="prescriptions.php" class="btn btn-outline btn-sm">✕ Clear</a>
        <?php endif; ?>
        <span style="font-size:12px;color:var(--text-muted);margin-left:4px;">
            <?= mysqli_num_rows($prescriptions) ?> result(s)
        </span>
    </form>

    <!-- List -->
    <?php
    $med_icons = [
        'Tablet'   => '💊',
        'Capsule'  => '💉',
        'Syrup'    => '🧪',
        'Liquid'   => '💧',
    ];
    if (mysqli_num_rows($prescriptions) === 0): ?>
    <div class="panel"><div class="empty-state">No prescriptions found.</div></div>
    <?php else: while ($row = mysqli_fetch_assoc($prescriptions)):
        $icon_char = $med_icons[$row['medication_type']] ?? '💊';
        $subtotal  = $row['medication_price'] * $row['quantity'];
    ?>
    <div class="presc-card">
        <div class="med-pill-icon"><?= $icon_char ?></div>
        <div class="presc-info">
            <div class="presc-med">
                <?= htmlspecialchars($row['medication_name']) ?>
                <span class="type-badge"><?= htmlspecialchars($row['medication_type']) ?></span>
            </div>
            <div class="presc-meta">
                <?= icon('patients', 12) ?> <?= htmlspecialchars($row['patient_name']) ?>
                &bull; <?= htmlspecialchars($row['doctor_name']) ?>
                &bull; <?= date('d M Y', strtotime($row['exam_date'])) ?>
            </div>
            <span class="presc-dosage">⏱ <?= htmlspecialchars($row['dosage']) ?></span>
        </div>
        <div style="text-align:center;min-width:60px;">
            <div style="font-size:18px;font-weight:600;color:var(--text);"><?= $row['quantity'] ?></div>
            <div style="font-size:10px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;">units</div>
        </div>
        <div class="presc-subtotal">
            Rp <?= number_format($subtotal, 0, ',', '.') ?>
        </div>
        <div style="display:flex;gap:6px;">
            <a href="prescriptions.php?action=view&id=<?= $row['prescription_id'] ?>" class="btn btn-outline btn-sm">
                <?= icon('records', 13) ?> View
            </a>
            <a href="examinations.php?action=view&id=<?= $row['exam_id'] ?>" class="btn btn-outline btn-sm">
                <?= icon('examinations', 13) ?>
            </a>
            <?php if (isAdmin()): ?>
            <a href="prescriptions.php?action=delete&id=<?= $row['prescription_id'] ?>"
               class="btn btn-danger btn-sm"
               onclick="return confirm('Delete this prescription? Stock will be restored.')">🗑</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endwhile; endif; ?>


    <?php /* ═══════════════════════════════════════════════════
             ADD
           ═══════════════════════════════════════════════════ */
    elseif ($action === 'add'): ?>

    <div class="page-header">
        <h2><?= icon('medications') ?> New Prescription</h2>
        <p class="page-sub">Add a medication prescription to an examination</p>
    </div>

    <div class="panel" style="max-width:580px;">
        <div class="panel-header"><div class="panel-title"><?= icon('medications', 14) ?> Prescription Details</div></div>
        <div style="padding:24px;">
            <form method="POST" id="presc-form">

                <div class="form-group">
                    <label class="form-label">Examination <span style="color:var(--danger)">*</span></label>
                    <select name="exam_id" class="form-control" required>
                        <option value="">Select examination</option>
                        <?php while ($ex = mysqli_fetch_assoc($all_exams)): ?>
                        <option value="<?= $ex['exam_id'] ?>"
                            <?= (isset($_GET['exam_id']) && $_GET['exam_id'] == $ex['exam_id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($ex['patient_name']) ?>
                            — <?= htmlspecialchars($ex['diagnosis'] ?? 'Exam #' . $ex['exam_id']) ?>
                            (<?= date('d M Y', strtotime($ex['exam_date'])) ?>)
                        </option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Medication <span style="color:var(--danger)">*</span></label>
                    <select name="medication_id" class="form-control" required onchange="updateMedInfo(this)">
                        <option value="">Select medication</option>
                        <?php
                        $meds_arr = mysqli_fetch_all($all_meds, MYSQLI_ASSOC);
                        foreach ($meds_arr as $m):
                            $stock_class = $m['stock'] > 20 ? 'stock-ok' : ($m['stock'] > 0 ? 'stock-low' : 'stock-none');
                        ?>
                        <option value="<?= $m['medication_id'] ?>"
                                data-stock="<?= $m['stock'] ?>"
                                data-price="<?= $m['price'] ?>"
                                data-type="<?= htmlspecialchars($m['type']) ?>"
                                <?= $m['stock'] == 0 ? 'disabled' : '' ?>>
                            <?= htmlspecialchars($m['name']) ?> (<?= $m['type'] ?>)
                            — Stock: <?= $m['stock'] ?>
                            <?= $m['stock'] == 0 ? '[OUT OF STOCK]' : '' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <!-- Med info preview -->
                    <div id="med-info" style="display:none;margin-top:8px;padding:10px 14px;background:var(--surface2);border-radius:var(--radius-sm);font-size:12px;">
                        <span id="med-type" class="type-badge" style="margin-right:8px;"></span>
                        Stock: <strong id="med-stock" class="stock-ok"></strong> units
                        &bull; Price: <strong id="med-price"></strong>/unit
                        &bull; Subtotal: <strong id="med-subtotal" style="color:var(--accent-h);"></strong>
                    </div>
                </div>

                <div style="display:grid;grid-template-columns:1fr 90px;gap:14px;">
                    <div class="form-group">
                        <label class="form-label">Dosage <span style="color:var(--danger)">*</span></label>
                        <select name="dosage" class="form-control" required>
                            <?php foreach ($dosages as $d): ?>
                            <option value="<?= $d ?>"><?= $d ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Quantity <span style="color:var(--danger)">*</span></label>
                        <input type="number" name="quantity" id="qty-input" class="form-control"
                               value="1" min="1" required oninput="updateSubtotal()">
                    </div>
                </div>

                <div style="display:flex;gap:10px;margin-top:8px;">
                    <button type="submit" name="save_prescription" class="btn btn-primary">
                        <?= icon('medications', 14) ?> Add Prescription
                    </button>
                    <a href="prescriptions.php" class="btn btn-outline">Cancel</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Low / out-of-stock warning panel -->
    <?php
    $low_stock = array_filter($meds_arr, fn($m) => $m['stock'] <= 10);
    if (count($low_stock) > 0):
    ?>
    <div class="panel" style="max-width:580px;border-color:rgba(251,191,36,.4);">
        <div class="panel-header">
            <div class="panel-title" style="color:#f59e0b;">⚠️ Low Stock Alert</div>
        </div>
        <div style="padding:12px 20px;">
            <?php foreach ($low_stock as $m): ?>
            <div style="display:flex;justify-content:space-between;align-items:center;padding:6px 0;border-bottom:1px solid var(--border);font-size:13px;">
                <span><?= htmlspecialchars($m['name']) ?> <span class="type-badge"><?= $m['type'] ?></span></span>
                <span class="<?= $m['stock'] == 0 ? 'stock-none' : 'stock-low' ?>" style="font-weight:500;">
                    <?= $m['stock'] == 0 ? 'Out of stock' : $m['stock'] . ' units left' ?>
                </span>
            </div>
            <?php endforeach; ?>
            <div style="margin-top:10px;">
                <a href="medications.php" class="btn btn-outline btn-sm"><?= icon('medications', 13) ?> Manage Stock</a>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script>
    function updateMedInfo(sel) {
        const opt = sel.options[sel.selectedIndex];
        const box = document.getElementById('med-info');
        if (!opt.value) { box.style.display = 'none'; return; }
        const stock = parseInt(opt.dataset.stock);
        const price = parseFloat(opt.dataset.price);
        document.getElementById('med-type').textContent    = opt.dataset.type;
        document.getElementById('med-stock').textContent   = stock;
        document.getElementById('med-stock').className     = stock > 20 ? 'stock-ok' : (stock > 0 ? 'stock-low' : 'stock-none');
        document.getElementById('med-price').textContent   = 'Rp ' + price.toLocaleString('id-ID');
        document.getElementById('qty-input').max           = stock;
        updateSubtotal();
        box.style.display = 'block';
    }
    function updateSubtotal() {
        const sel   = document.querySelector('[name="medication_id"]');
        const opt   = sel.options[sel.selectedIndex];
        const qty   = parseInt(document.getElementById('qty-input').value) || 1;
        const price = parseFloat(opt.dataset?.price) || 0;
        document.getElementById('med-subtotal').textContent = 'Rp ' + (price * qty).toLocaleString('id-ID');
    }
    </script>


    <?php /* ═══════════════════════════════════════════════════
             VIEW
           ═══════════════════════════════════════════════════ */
    elseif ($action === 'view' && $presc): ?>

    <?php
    $med_icons = ['Tablet'=>'💊','Capsule'=>'💉','Syrup'=>'🧪','Liquid'=>'💧'];
    $icon_char = $med_icons[$presc['medication_type']] ?? '💊';
    $subtotal  = $presc['medication_price'] * $presc['quantity'];
    ?>

    <div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
        <div>
            <h2><?= icon('medications') ?> Prescription #<?= $id ?></h2>
            <p class="page-sub"><?= htmlspecialchars($presc['patient_name']) ?> — <?= htmlspecialchars($presc['medication_name']) ?></p>
        </div>
        <div style="display:flex;gap:8px;">
            <a href="examinations.php?action=view&id=<?= $presc['exam_id'] ?>" class="btn btn-outline">
                <?= icon('examinations', 13) ?> View Examination
            </a>
            <?php if (isAdmin()): ?>
            <a href="prescriptions.php?action=delete&id=<?= $id ?>"
               class="btn btn-danger"
               onclick="return confirm('Delete this prescription? Medication stock will be restored.')">🗑 Delete</a>
            <?php endif; ?>
            <a href="prescriptions.php" class="btn btn-outline">← Back</a>
        </div>
    </div>

    <div class="view-grid">

        <!-- Left: medication + examination info -->
        <div>
            <!-- Medication card -->
            <div class="panel" style="margin-bottom:16px;">
                <div class="panel-header">
                    <div class="panel-title"><?= icon('medications', 14) ?> Medication</div>
                </div>
                <div style="padding:16px 20px;display:flex;align-items:center;gap:16px;">
                    <div style="font-size:36px;"><?= $icon_char ?></div>
                    <div style="flex:1;">
                        <div style="font-size:17px;font-weight:500;"><?= htmlspecialchars($presc['medication_name']) ?></div>
                        <div style="margin-top:4px;display:flex;gap:8px;align-items:center;">
                            <span class="type-badge"><?= htmlspecialchars($presc['medication_type']) ?></span>
                            <span style="font-size:12px;color:var(--text-muted);">
                                Stock remaining:
                                <strong class="<?= $presc['medication_stock'] > 20 ? 'stock-ok' : ($presc['medication_stock'] > 0 ? 'stock-low' : 'stock-none') ?>">
                                    <?= $presc['medication_stock'] ?> units
                                </strong>
                            </span>
                        </div>
                    </div>
                    <div style="text-align:right;">
                        <div style="font-size:11px;color:var(--text-muted);">Unit price</div>
                        <div style="font-size:14px;font-weight:500;">Rp <?= number_format($presc['medication_price'], 0, ',', '.') ?></div>
                    </div>
                </div>
                <div style="padding:4px 20px 16px;">
                    <div class="info-row">
                        <span class="info-label">Dosage</span>
                        <span class="info-value">⏱ <?= htmlspecialchars($presc['dosage']) ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Quantity</span>
                        <span class="info-value"><?= $presc['quantity'] ?> units</span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Subtotal</span>
                        <span class="info-value" style="font-family:'Playfair Display',serif;font-size:18px;color:var(--accent-h);">
                            Rp <?= number_format($subtotal, 0, ',', '.') ?>
                        </span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Prescribed on</span>
                        <span class="info-value"><?= date('d F Y, H:i', strtotime($presc['created_at'])) ?></span>
                    </div>
                </div>
            </div>

            <!-- Examination info -->
            <div class="panel">
                <div class="panel-header"><div class="panel-title"><?= icon('examinations', 14) ?> Examination Record</div></div>
                <div style="padding:4px 20px 12px;">
                    <div class="info-row">
                        <span class="info-label">Patient</span>
                        <span class="info-value"><?= icon('patients', 13) ?> <?= htmlspecialchars($presc['patient_name']) ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Doctor</span>
                        <span class="info-value">
                            <?= htmlspecialchars($presc['doctor_name']) ?>
                            <span style="color:var(--text-muted);font-size:11px;">(<?= htmlspecialchars($presc['specialization']) ?>)</span>
                        </span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Exam Date</span>
                        <span class="info-value"><?= date('d F Y', strtotime($presc['exam_date'])) ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Symptoms</span>
                        <span class="info-value"><?= htmlspecialchars($presc['symptoms'] ?? '—') ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Diagnosis</span>
                        <span class="info-value" style="color:var(--accent-h);">🔬 <?= htmlspecialchars($presc['diagnosis'] ?? '—') ?></span>
                    </div>
                    <?php if ($presc['exam_notes']): ?>
                    <div class="info-row">
                        <span class="info-label">Doctor's Notes</span>
                        <span class="info-value"><?= htmlspecialchars($presc['exam_notes']) ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Right: other prescriptions from same exam -->
        <?php
        $other_prescs = mysqli_query($conn, "
            SELECT pr.*, m.name medication_name, m.type medication_type, m.price medication_price
            FROM prescription pr
            JOIN medication m ON pr.medication_id = m.medication_id
            WHERE pr.exam_id = {$presc['exam_id']}
            ORDER BY pr.prescription_id
        ");
        $other_rows = mysqli_fetch_all($other_prescs, MYSQLI_ASSOC);
        $exam_total = array_sum(array_map(fn($r) => $r['medication_price'] * $r['quantity'], $other_rows));
        ?>
        <div>
            <div class="panel" style="height:fit-content;">
                <div class="panel-header">
                    <div class="panel-title"><?= icon('records', 14) ?> All Prescriptions This Exam</div>
                </div>
                <?php foreach ($other_rows as $r):
                    $is_current = $r['prescription_id'] == $id;
                    $r_sub = $r['medication_price'] * $r['quantity'];
                ?>
                <div style="display:flex;align-items:center;gap:10px;padding:10px 16px;border-bottom:1px solid var(--border);
                            <?= $is_current ? 'background:var(--accent-bg);' : '' ?>">
                    <span style="font-size:16px;"><?= $med_icons[$r['medication_type']] ?? '💊' ?></span>
                    <div style="flex:1;">
                        <div style="font-size:13px;font-weight:<?= $is_current ? '600' : '400' ?>;color:var(--text);">
                            <?= htmlspecialchars($r['medication_name']) ?>
                            <?= $is_current ? '<span style="font-size:10px;color:var(--accent-h);"> ← current</span>' : '' ?>
                        </div>
                        <div style="font-size:11px;color:var(--text-muted);"><?= $r['dosage'] ?> · <?= $r['quantity'] ?> units</div>
                    </div>
                    <div style="font-size:12px;color:var(--text-muted);text-align:right;">
                        Rp <?= number_format($r_sub, 0, ',', '.') ?>
                    </div>
                    <?php if (!$is_current): ?>
                    <a href="prescriptions.php?action=view&id=<?= $r['prescription_id'] ?>" class="btn btn-outline btn-sm" style="padding:3px 8px;font-size:11px;">View</a>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
                <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 16px;background:var(--surface2);font-size:13px;font-weight:500;">
                    <span>Medication total</span>
                    <span style="font-family:'Playfair Display',serif;">Rp <?= number_format($exam_total, 0, ',', '.') ?></span>
                </div>
            </div>

            <!-- Quick links -->
            <div style="margin-top:10px;display:flex;flex-direction:column;gap:8px;">
                <a href="examinations.php?action=view&id=<?= $presc['exam_id'] ?>" class="btn btn-outline" style="width:100%;justify-content:center;">
                    <?= icon('examinations', 13) ?> Full Examination Record
                </a>
                <a href="payments.php?action=add&exam_id=<?= $presc['exam_id'] ?>" class="btn btn-outline" style="width:100%;justify-content:center;">
                    <?= icon('payments', 13) ?> Create Payment for This Exam
                </a>
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
