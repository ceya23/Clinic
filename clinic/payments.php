<?php
require_once 'includes/config.php';
requireRole(['admin']);

$error   = '';
$success = '';
$action  = $_GET['action'] ?? 'list';
$id      = isset($_GET['id'])      ? (int)$_GET['id']      : 0;
$exam_id = isset($_GET['exam_id']) ? (int)$_GET['exam_id'] : 0;

// ── DELETE PAYMENT ────────────────────────────────────────────────────────────
if ($action === 'delete' && $id) {
    $stmt = mysqli_prepare($conn, "DELETE FROM payment WHERE payment_id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    header("Location: payments.php?success=deleted"); exit();
}

// ── DELETE DETAIL LINE ────────────────────────────────────────────────────────
if ($action === 'delete_detail' && $id) {
    $pay_id = (int)($_GET['payment_id'] ?? 0);
    $stmt = mysqli_prepare($conn, "DELETE FROM payment_detail WHERE detail_id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    // Recalculate payment total
    if ($pay_id) {
        mysqli_query($conn, "UPDATE payment SET amount = (SELECT COALESCE(SUM(amount),0) FROM payment_detail WHERE payment_id = $pay_id) WHERE payment_id = $pay_id");
    }
    header("Location: payments.php?action=view&id=$pay_id&success=detail_deleted"); exit();
}

// ── MARK AS PAID / UPDATE STATUS ─────────────────────────────────────────────
if ($action === 'mark_paid' && $id) {
    // FIX #1: Use prepared statement
    $stmt_mp = mysqli_prepare($conn, "UPDATE payment SET status='Paid', payment_date=CURDATE() WHERE payment_id=?");
    mysqli_stmt_bind_param($stmt_mp, 'i', $id);
    mysqli_stmt_execute($stmt_mp);
    header("Location: payments.php?action=view&id=$id&success=marked_paid"); exit();
}

// ── ADD PAYMENT (from exam page) ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_payment'])) {
    $p_exam_id = (int)($_POST['exam_id'] ?? 0);
    $method    = $_POST['method']       ?? 'Cash';
    $status    = $_POST['status']       ?? 'Pending';
    $pay_date  = $_POST['payment_date'] ?? date('Y-m-d');

    if (!$p_exam_id) {
        $error = 'Examination is required.';
    } else {
        // Check if payment already exists
        $exists = mysqli_fetch_assoc(mysqli_query($conn, "SELECT payment_id FROM payment WHERE exam_id = $p_exam_id"));
        if ($exists) {
            $error = 'Payment for this examination already exists.';
            $action = 'add';
        } else {
            // Calculate total from prescriptions
            $total_res = mysqli_fetch_assoc(mysqli_query($conn,
                "SELECT COALESCE(SUM(m.price * pr.quantity), 0) AS med_total
                 FROM prescription pr
                 JOIN medication m ON pr.medication_id = m.medication_id
                 WHERE pr.exam_id = $p_exam_id"
            ));
            $med_total   = (float)($total_res['med_total'] ?? 0);
            $consult_fee = (float)($_POST['consult_fee'] ?? 150000);
            $total       = $consult_fee + $med_total;

            $stmt = mysqli_prepare($conn,
                "INSERT INTO payment (exam_id, amount, payment_date, method, status) VALUES (?, ?, ?, ?, ?)"
            );
            mysqli_stmt_bind_param($stmt, 'idsss', $p_exam_id, $total, $pay_date, $method, $status);
            mysqli_stmt_execute($stmt);
            $new_pay_id = mysqli_insert_id($conn);

            // Auto-generate detail lines
            if ($consult_fee > 0) {
                $desc = 'Consultation fee';
                $stmt2 = mysqli_prepare($conn, "INSERT INTO payment_detail (payment_id, description, amount) VALUES (?, ?, ?)");
                mysqli_stmt_bind_param($stmt2, 'isd', $new_pay_id, $desc, $consult_fee);
                mysqli_stmt_execute($stmt2);
            }
            // One line per prescription item
            $prescriptions = mysqli_query($conn,
                "SELECT m.name, m.price, pr.quantity
                 FROM prescription pr JOIN medication m ON pr.medication_id = m.medication_id
                 WHERE pr.exam_id = $p_exam_id"
            );
            while ($pr = mysqli_fetch_assoc($prescriptions)) {
                $desc    = $pr['name'] . ' x' . $pr['quantity'];
                $sub     = $pr['price'] * $pr['quantity'];
                $stmt3   = mysqli_prepare($conn, "INSERT INTO payment_detail (payment_id, description, amount) VALUES (?, ?, ?)");
                mysqli_stmt_bind_param($stmt3, 'isd', $new_pay_id, $desc, $sub);
                mysqli_stmt_execute($stmt3);
            }

            header("Location: payments.php?action=view&id=$new_pay_id&success=added"); exit();
        }
    }
}

// ── ADD DETAIL LINE ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_detail'])) {
    $pay_id = (int)($_POST['payment_id'] ?? 0);
    $desc   = trim($_POST['description'] ?? '');
    $amt    = (float)($_POST['amount']   ?? 0);

    if ($pay_id && $desc && $amt > 0) {
        $stmt = mysqli_prepare($conn, "INSERT INTO payment_detail (payment_id, description, amount) VALUES (?, ?, ?)");
        mysqli_stmt_bind_param($stmt, 'isd', $pay_id, $desc, $amt);
        mysqli_stmt_execute($stmt);
        // Update total
        mysqli_query($conn, "UPDATE payment SET amount = (SELECT COALESCE(SUM(amount),0) FROM payment_detail WHERE payment_id = $pay_id) WHERE payment_id = $pay_id");
        header("Location: payments.php?action=view&id=$pay_id&success=detail_added"); exit();
    } else {
        $error = 'Description and amount are required.';
        $action = 'view';
        $id     = $pay_id;
    }
}

// ── UPDATE STATUS / METHOD ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_payment'])) {
    $pay_id   = (int)($_POST['payment_id'] ?? 0);
    $method   = $_POST['method']   ?? 'Cash';
    $status   = $_POST['status']   ?? 'Pending';
    $pay_date = $_POST['payment_date'] ?? date('Y-m-d');
    if ($pay_id) {
        $stmt = mysqli_prepare($conn, "UPDATE payment SET method=?, status=?, payment_date=? WHERE payment_id=?");
        mysqli_stmt_bind_param($stmt, 'sssi', $method, $status, $pay_date, $pay_id);
        mysqli_stmt_execute($stmt);
        header("Location: payments.php?action=view&id=$pay_id&success=updated"); exit();
    }
}

// ── FETCH SINGLE ──────────────────────────────────────────────────────────────
$payment = null;
if (in_array($action, ['view','edit']) && $id) {
    $payment = mysqli_fetch_assoc(mysqli_query($conn, "
        SELECT py.*, e.exam_date, e.diagnosis, e.symptoms,
               p.name patient_name, d.name doctor_name
        FROM payment py
        JOIN examination_history e ON py.exam_id = e.exam_id
        JOIN patient p ON e.patient_id = p.patient_id
        JOIN doctor  d ON e.doctor_id  = d.doctor_id
        WHERE py.payment_id = $id
    "));
    if (!$payment) { header("Location: payments.php"); exit(); }
}

// Fetch detail lines for view
$details = null;
if ($action === 'view' && $id) {
    $details = mysqli_query($conn, "SELECT * FROM payment_detail WHERE payment_id = $id ORDER BY detail_id");
}

// Fetch exams without payment yet (for add form)
$unpaid_exams = mysqli_query($conn, "
    SELECT e.exam_id, e.exam_date, e.diagnosis,
           p.name patient_name, d.name doctor_name
    FROM examination_history e
    JOIN patient p ON e.patient_id = p.patient_id
    JOIN doctor  d ON e.doctor_id  = d.doctor_id
    WHERE e.exam_id NOT IN (SELECT exam_id FROM payment)
    ORDER BY e.exam_date DESC
");

// ── FETCH LIST ────────────────────────────────────────────────────────────────
$filter_status = $_GET['status'] ?? '';
$filter_method = $_GET['method'] ?? '';
$where_parts   = [];
if ($filter_status) $where_parts[] = "py.status = '" . mysqli_real_escape_string($conn, $filter_status) . "'";
if ($filter_method) $where_parts[] = "py.method = '" . mysqli_real_escape_string($conn, $filter_method) . "'";
$where_sql = $where_parts ? 'WHERE ' . implode(' AND ', $where_parts) : '';

$payments = mysqli_query($conn, "
    SELECT py.*, p.name patient_name, d.name doctor_name, e.diagnosis
    FROM payment py
    JOIN examination_history e ON py.exam_id = e.exam_id
    JOIN patient p ON e.patient_id = p.patient_id
    JOIN doctor  d ON e.doctor_id  = d.doctor_id
    $where_sql
    ORDER BY py.created_at DESC
");

// Stats
$stats = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT
        COUNT(*) total,
        SUM(status='Paid')    paid_count,
        SUM(status='Pending') pending_count,
        SUM(CASE WHEN status='Paid' THEN amount ELSE 0 END) total_paid,
        SUM(CASE WHEN status='Pending' THEN amount ELSE 0 END) total_pending
    FROM payment
"));

// Pre-fill exam_id from query string (coming from examinations.php)
$prefill_exam = null;
if ($action === 'add' && $exam_id) {
    $prefill_exam = mysqli_fetch_assoc(mysqli_query($conn, "
        SELECT e.exam_id, e.exam_date, e.diagnosis,
               p.name patient_name, d.name doctor_name,
               COALESCE(SUM(m.price * pr.quantity), 0) AS med_total
        FROM examination_history e
        JOIN patient p ON e.patient_id = p.patient_id
        JOIN doctor  d ON e.doctor_id  = d.doctor_id
        LEFT JOIN prescription pr ON pr.exam_id = e.exam_id
        LEFT JOIN medication m   ON m.medication_id = pr.medication_id
        WHERE e.exam_id = $exam_id
        GROUP BY e.exam_id
    "));
}

$methods  = ['Cash','Transfer','E-Wallet','Insurance'];
$statuses = ['Pending','Paid','Refunded'];
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Payments — IS Class 2 Healthcare</title>
<link rel="stylesheet" href="includes/theme.css">
<style>
/* ── Payment cards ── */
.pay-card {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: var(--radius-md); padding: 16px 20px;
    display: flex; align-items: center; gap: 16px;
    transition: border-color .2s; margin-bottom: 8px;
}
.pay-card:hover { border-color: var(--border-h); }
.pay-amount-box {
    min-width: 110px; text-align: right;
}
.pay-amount {
    font-family: 'Playfair Display', serif;
    font-size: 17px; font-weight: 500; color: var(--text);
    white-space: nowrap;
}
.pay-method-icon {
    width: 42px; height: 42px; border-radius: var(--radius-sm);
    background: var(--accent-bg);
    display: flex; align-items: center; justify-content: center;
    font-size: 18px; flex-shrink: 0;
}
.pay-info { flex: 1; min-width: 0; }
.pay-patient { font-size: 14px; font-weight: 500; color: var(--text); }
.pay-meta    { font-size: 11px; color: var(--text-muted); margin-top: 3px; }

/* ── Badges ── */
.badge {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 3px 10px; border-radius: 20px;
    font-size: 11px; font-weight: 600; letter-spacing: .04em;
    text-transform: uppercase;
}
.badge-paid     { background: rgba(16,185,129,.15); color: var(--success); }
.badge-pending  { background: rgba(251,191,36,.15);  color: #f59e0b; }
.badge-refunded { background: rgba(148,163,184,.15); color: var(--text-muted); }

/* ── Stats row ── */
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
.view-grid { display: grid; grid-template-columns: 1fr 320px; gap: 20px; }
.info-row { display: flex; flex-direction: column; padding: 11px 0; border-bottom: 1px solid var(--border); }
.info-row:last-child { border: none; }
.info-label { font-size: 10px; text-transform: uppercase; letter-spacing: .06em; color: var(--text-muted); margin-bottom: 3px; }
.info-value { font-size: 13px; color: var(--text); }

/* ── Detail lines ── */
.detail-row {
    display: flex; align-items: center; gap: 10px;
    padding: 10px 16px; border-bottom: 1px solid var(--border); font-size: 13px;
}
.detail-row:last-child { border: none; }
.detail-total {
    display: flex; justify-content: space-between; align-items: center;
    padding: 12px 16px;
    background: var(--accent-bg);
    border-top: 1.5px solid var(--border-h);
    border-radius: 0 0 var(--radius-md) var(--radius-md);
    font-weight: 600; font-size: 14px;
}

/* ── Add detail form ── */
.add-detail-form {
    padding: 16px 20px; background: var(--surface2);
    border-top: 1px solid var(--border);
    border-radius: 0 0 var(--radius-md) var(--radius-md);
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

/* ── Exam-link banner ── */
.exam-banner {
    background: var(--accent-bg); border: 1px solid var(--accent);
    border-radius: var(--radius-sm); padding: 12px 16px;
    font-size: 13px; margin-bottom: 20px; display: flex; gap: 10px; align-items: center;
}
.exam-banner strong { color: var(--accent-h); }

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
            'added'          => 'Payment recorded successfully!',
            'updated'        => 'Payment updated.',
            'deleted'        => 'Payment deleted.',
            'detail_added'   => 'Item added to bill.',
            'detail_deleted' => 'Item removed from bill.',
            'marked_paid'    => 'Payment marked as Paid!',
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
            <h2><?= icon('payments') ?> Payments</h2>
            <p class="page-sub">Billing &amp; payment records</p>
        </div>
        <a href="payments.php?action=add" class="btn btn-primary"><?= icon('payments', 14) ?> New Payment</a>
    </div>

    <!-- Stats -->
    <div class="stats-row">
        <div class="stat-card">
            <div class="stat-label">Total Transactions</div>
            <div class="stat-value"><?= $stats['total'] ?></div>
            <div class="stat-sub"><?= $stats['paid_count'] ?> paid · <?= $stats['pending_count'] ?> pending</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Revenue Collected</div>
            <div class="stat-value" style="color:var(--success);">Rp <?= number_format($stats['total_paid'], 0, ',', '.') ?></div>
            <div class="stat-sub"><?= $stats['paid_count'] ?> payments paid</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Outstanding</div>
            <div class="stat-value" style="color:#f59e0b;">Rp <?= number_format($stats['total_pending'], 0, ',', '.') ?></div>
            <div class="stat-sub"><?= $stats['pending_count'] ?> pending payments</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Exams Unbilled</div>
            <div class="stat-value"><?= mysqli_num_rows($unpaid_exams) ?></div>
            <div class="stat-sub">no payment yet</div>
        </div>
    </div>

    <!-- Filters -->
    <form method="GET" class="filter-bar">
        <input type="hidden" name="action" value="list">
        <select name="status" onchange="this.form.submit()">
            <option value="">All statuses</option>
            <?php foreach ($statuses as $s): ?>
            <option value="<?= $s ?>" <?= $filter_status === $s ? 'selected' : '' ?>><?= $s ?></option>
            <?php endforeach; ?>
        </select>
        <select name="method" onchange="this.form.submit()">
            <option value="">All methods</option>
            <?php foreach ($methods as $m): ?>
            <option value="<?= $m ?>" <?= $filter_method === $m ? 'selected' : '' ?>><?= $m ?></option>
            <?php endforeach; ?>
        </select>
        <?php if ($filter_status || $filter_method): ?>
        <a href="payments.php" class="btn btn-outline btn-sm">✕ Clear</a>
        <?php endif; ?>
    </form>

    <!-- Payment list -->
    <?php if (mysqli_num_rows($payments) === 0): ?>
    <div class="panel"><div class="empty-state">No payments found.</div></div>
    <?php else: ?>
    <?php $method_icons = ['Cash'=>'💵','Transfer'=>'🏦','E-Wallet'=>'📱','Insurance'=>'🛡️']; ?>
    <div class="panel">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Patient</th>
                    <th>Diagnosis</th>
                    <th>Doctor</th>
                    <th>Method</th>
                    <th>Amount</th>
                    <th>Date</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php while ($row = mysqli_fetch_assoc($payments)): ?>
            <tr>
                <td>
                    <div style="display:flex;align-items:center;gap:6px;">
                        <?= icon('patients', 13) ?>
                        <span style="font-size:13px;font-weight:500;"><?= htmlspecialchars($row['patient_name']) ?></span>
                    </div>
                </td>
                <td style="font-size:12px;color:var(--text-muted);max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                    <div style="display:flex;align-items:center;gap:5px;">
                        <?= icon('examinations', 12) ?>
                        <?= htmlspecialchars($row['diagnosis'] ?? '—') ?>
                    </div>
                </td>
                <td style="font-size:13px;white-space:nowrap;"><?= htmlspecialchars($row['doctor_name']) ?></td>
                <td style="font-size:12px;white-space:nowrap;"><?= htmlspecialchars($row['method']) ?></td>
                <td style="font-weight:600;white-space:nowrap;">Rp <?= number_format($row['amount'], 0, ',', '.') ?></td>
                <td style="font-size:12px;color:var(--text-muted);white-space:nowrap;">
                    <?= $row['payment_date'] ? date('d M Y', strtotime($row['payment_date'])) : '—' ?>
                </td>
                <td>
                    <?php if ($row['status'] === 'Pending'): ?>
                    <a href="payments.php?action=mark_paid&id=<?= $row['payment_id'] ?>"
                       class="badge badge-pending"
                       style="cursor:pointer;text-decoration:none;white-space:nowrap;"
                       onclick="return confirm('Mark this payment as Paid?')">
                       Pending →
                    </a>
                    <?php else: ?>
                    <span class="badge badge-<?= strtolower($row['status']) ?>"><?= $row['status'] ?></span>
                    <?php endif; ?>
                </td>
                <td>
                    <div style="display:flex;gap:6px;">
                        <a href="payments.php?action=view&id=<?= $row['payment_id'] ?>" class="btn btn-outline btn-sm"><?= icon('records', 13) ?> View</a>
                        <a href="payments.php?action=delete&id=<?= $row['payment_id'] ?>"
                           class="btn btn-danger btn-sm"
                           onclick="return confirm('Delete this payment record?')">🗑</a>
                    </div>
                </td>
            </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>


    <?php /* ═══════════════════════════════════════════════════
             ADD
           ═══════════════════════════════════════════════════ */
    elseif ($action === 'add'): ?>

    <div class="page-header">
        <h2><?= icon('payments') ?> New Payment</h2>
        <p class="page-sub">Create a payment record for an examination</p>
    </div>

    <?php if ($prefill_exam): ?>
    <div class="exam-banner">
        <?= icon('examinations', 14) ?>
        <div>
            Billing for: <strong><?= htmlspecialchars($prefill_exam['patient_name']) ?></strong>
            &mdash; <?= htmlspecialchars($prefill_exam['diagnosis'] ?? 'Exam #' . $prefill_exam['exam_id']) ?>
            &mdash; <strong><?= htmlspecialchars($prefill_exam['doctor_name']) ?></strong>
            <span style="color:var(--text-muted);font-size:11px;margin-left:6px;"><?= date('d M Y', strtotime($prefill_exam['exam_date'])) ?></span>
        </div>
    </div>
    <?php endif; ?>

    <div class="panel" style="max-width:560px;">
        <div class="panel-header"><div class="panel-title"><?= icon('payments', 14) ?> Payment Details</div></div>
        <div style="padding:24px;">
            <form method="POST">
                <div class="form-group">
                    <label class="form-label">Examination <span style="color:var(--danger)">*</span></label>
                    <?php if ($prefill_exam): ?>
                    <input type="hidden" name="exam_id" value="<?= $prefill_exam['exam_id'] ?>">
                    <input type="text" class="form-control" readonly
                        value="<?= htmlspecialchars($prefill_exam['patient_name']) ?> — <?= htmlspecialchars($prefill_exam['diagnosis'] ?? 'Exam #'.$prefill_exam['exam_id']) ?>"
                        style="color:var(--text-muted);cursor:not-allowed;">
                    <?php else: ?>
                    <select name="exam_id" class="form-control" required>
                        <option value="">Select examination</option>
                        <?php mysqli_data_seek($unpaid_exams, 0); while ($ex = mysqli_fetch_assoc($unpaid_exams)): ?>
                        <option value="<?= $ex['exam_id'] ?>">
                            <?= htmlspecialchars($ex['patient_name']) ?> — <?= htmlspecialchars($ex['diagnosis'] ?? 'Exam #'.$ex['exam_id']) ?>
                            (<?= date('d M Y', strtotime($ex['exam_date'])) ?>)
                        </option>
                        <?php endwhile; ?>
                    </select>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label class="form-label">Consultation Fee (Rp)</label>
                    <input type="number" name="consult_fee" class="form-control"
                           value="150000" min="0" step="1000">
                    <span style="font-size:11px;color:var(--text-muted);margin-top:4px;display:block;">
                        Prescription costs will be added automatically from recorded prescriptions.
                    </span>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                    <div class="form-group">
                        <label class="form-label">Payment Method</label>
                        <select name="method" class="form-control">
                            <?php foreach ($methods as $m): ?>
                            <option value="<?= $m ?>"><?= $m ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-control">
                            <?php foreach ($statuses as $s): ?>
                            <option value="<?= $s ?>"><?= $s ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Payment Date</label>
                    <input type="date" name="payment_date" class="form-control" value="<?= date('Y-m-d') ?>">
                </div>

                <div style="display:flex;gap:10px;margin-top:8px;">
                    <button type="submit" name="save_payment" class="btn btn-primary">
                        <?= icon('payments', 14) ?> Create Payment
                    </button>
                    <a href="payments.php" class="btn btn-outline">Cancel</a>
                </div>
            </form>
        </div>
    </div>


    <?php /* ═══════════════════════════════════════════════════
             VIEW
           ═══════════════════════════════════════════════════ */
    elseif ($action === 'view' && $payment): ?>

    <div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
        <div>
            <h2><?= icon('payments') ?> Payment #<?= $id ?></h2>
            <p class="page-sub"><?= htmlspecialchars($payment['patient_name']) ?></p>
        </div>
        <div style="display:flex;gap:8px;align-items:center;">
            <span class="badge badge-<?= strtolower($payment['status']) ?>" style="font-size:12px;padding:5px 14px;"><?= $payment['status'] ?></span>
            <?php if ($payment['status'] === 'Pending'): ?>
            <a href="payments.php?action=mark_paid&id=<?= $id ?>"
               class="btn btn-primary"
               onclick="return confirm('Mark as Paid?')">✓ Mark as Paid</a>
            <?php endif; ?>
            <a href="payments.php" class="btn btn-outline">← Back</a>
        </div>
    </div>

    <div class="view-grid">

        <!-- Left: payment info + update form -->
        <div>
            <div class="panel" style="margin-bottom:16px;">
                <div class="panel-header"><div class="panel-title"><?= icon('records', 14) ?> Payment Info</div></div>
                <div style="padding:4px 20px 8px;">
                    <div class="info-row">
                        <span class="info-label">Patient</span>
                        <span class="info-value"><?= icon('patients', 13) ?> <?= htmlspecialchars($payment['patient_name']) ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Doctor</span>
                        <span class="info-value"><?= htmlspecialchars($payment['doctor_name']) ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Examination Date</span>
                        <span class="info-value"><?= date('d F Y', strtotime($payment['exam_date'])) ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Diagnosis</span>
                        <span class="info-value" style="color:var(--accent-h);">🔬 <?= htmlspecialchars($payment['diagnosis'] ?? '—') ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Total Amount</span>
                        <span class="info-value" style="font-family:'Playfair Display',serif;font-size:20px;">
                            Rp <?= number_format($payment['amount'], 0, ',', '.') ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Update method/status -->
            <div class="panel">
                <div class="panel-header"><div class="panel-title"><?= icon('records', 14) ?> Update Payment</div></div>
                <div style="padding:20px;">
                    <form method="POST">
                        <input type="hidden" name="payment_id" value="<?= $id ?>">
                        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;">
                            <div class="form-group">
                                <label class="form-label">Method</label>
                                <select name="method" class="form-control">
                                    <?php foreach ($methods as $m): ?>
                                    <option value="<?= $m ?>" <?= $payment['method'] === $m ? 'selected' : '' ?>><?= $m ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Status</label>
                                <select name="status" class="form-control">
                                    <?php foreach ($statuses as $s): ?>
                                    <option value="<?= $s ?>" <?= $payment['status'] === $s ? 'selected' : '' ?>><?= $s ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Date</label>
                                <input type="date" name="payment_date" class="form-control"
                                       value="<?= $payment['payment_date'] ?? date('Y-m-d') ?>">
                            </div>
                        </div>
                        <button type="submit" name="update_payment" class="btn btn-outline btn-sm">
                            <?= icon('records', 13) ?> Save Changes
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Right: bill details -->
        <div>
            <div class="panel" style="height:fit-content;">
                <div class="panel-header"><div class="panel-title"><?= icon('bills', 14) ?> Bill Items</div></div>

                <?php
                $detail_rows = mysqli_fetch_all($details, MYSQLI_ASSOC);
                $total = 0;
                ?>
                <?php if (count($detail_rows) > 0): ?>
                <?php foreach ($detail_rows as $d): $total += $d['amount']; ?>
                <div class="detail-row">
                    <div style="flex:1;">
                        <div style="font-size:13px;font-weight:500;"><?= htmlspecialchars($d['description']) ?></div>
                    </div>
                    <div style="font-size:13px;white-space:nowrap;margin-right:8px;">
                        Rp <?= number_format($d['amount'], 0, ',', '.') ?>
                    </div>
                    <a href="payments.php?action=delete_detail&id=<?= $d['detail_id'] ?>&payment_id=<?= $id ?>"
                       class="btn btn-danger btn-sm"
                       onclick="return confirm('Remove this item?')">✕</a>
                </div>
                <?php endforeach; ?>
                <div class="detail-total">
                    <span>Total</span>
                    <span style="font-family:'Playfair Display',serif;font-size:16px;">Rp <?= number_format($total, 0, ',', '.') ?></span>
                </div>
                <?php else: ?>
                <div class="empty-state" style="padding:20px;">No bill items yet.</div>
                <?php endif; ?>

                <!-- Add item form -->
                <div class="add-detail-form">
                    <div style="font-size:12px;font-weight:500;color:var(--text);margin-bottom:10px;">
                        <?= icon('appointments', 13) ?> Add Item
                    </div>
                    <form method="POST">
                        <input type="hidden" name="payment_id" value="<?= $id ?>">
                        <div class="form-group">
                            <label class="form-label">Description</label>
                            <input type="text" name="description" class="form-control"
                                   placeholder="e.g. Consultation fee, Paracetamol x5" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Amount (Rp)</label>
                            <input type="number" name="amount" class="form-control"
                                   placeholder="0" min="1" step="500" required>
                        </div>
                        <button type="submit" name="save_detail" class="btn btn-primary" style="width:100%;">
                            <?= icon('appointments', 13) ?> Add Item
                        </button>
                    </form>
                </div>
            </div>

            <!-- Link back to examination -->
            <div style="margin-top:10px;text-align:center;">
                <a href="examinations.php?action=view&id=<?= $payment['exam_id'] ?>"
                   class="btn btn-outline btn-sm" style="width:100%;">
                    <?= icon('examinations', 13) ?> View Examination Record
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
