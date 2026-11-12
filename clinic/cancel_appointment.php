<?php
require_once 'includes/config.php';
requireLogin();
if (!isPatient()) { header("Location: dashboard.php"); exit(); }

$patient_id = getPatientId($conn);
$appt_id    = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$appt_id) { header("Location: dashboard.php"); exit(); }

// Verify this appointment belongs to this patient and is Scheduled
// FIX #1: Use prepared statement — no raw variables in query
$stmt_a = mysqli_prepare($conn,
    "SELECT a.*, d.name doctor_name, d.specialization
     FROM appointment a
     JOIN doctor d ON a.doctor_id = d.doctor_id
     WHERE a.appointment_id = ?
       AND a.patient_id = ?");
mysqli_stmt_bind_param($stmt_a, 'ii', $appt_id, $patient_id);
mysqli_stmt_execute($stmt_a);
$appt = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_a));

if (!$appt) { header("Location: dashboard.php"); exit(); }

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_cancel'])) {
    if ($appt['status'] !== 'Scheduled') {
        $error = 'Only scheduled appointments can be cancelled.';
    } else {
        $stmt = mysqli_prepare($conn,
            "UPDATE appointment SET status='Cancelled' WHERE appointment_id = ? AND patient_id = ?");
        mysqli_stmt_bind_param($stmt, 'ii', $appt_id, $patient_id);
        mysqli_stmt_execute($stmt);
        header("Location: booking.php?cancelled=1"); exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Cancel Appointment — IS Class 2 Healthcare</title>
<link rel="stylesheet" href="includes/theme.css">
<style>
.cancel-card {
    background: var(--surface); border: 1.5px solid var(--border);
    border-radius: var(--radius-lg); padding: 32px;
    max-width: 500px; margin: 0 auto;
}
.appt-info { background: var(--surface2); border-radius: var(--radius-md); padding: 18px 20px; margin-bottom: 24px; }
.info-row { display: flex; justify-content: space-between; margin-bottom: 10px; font-size: 13px; }
.info-row:last-child { margin-bottom: 0; }
.info-label { color: var(--text-muted); font-weight: 600; }
.btn { padding: 11px 24px; border: none; border-radius: var(--radius-sm);
       font-size: 13px; font-weight: 700; cursor: pointer; transition: all .15s; text-decoration: none; display: inline-block; }
.btn-danger  { background: var(--danger); color: #fff; }
.btn-danger:hover { opacity: .85; }
.btn-ghost   { background: var(--surface2); color: var(--text-muted); border: 1.5px solid var(--border); }
.btn-ghost:hover { color: var(--text); }
.alert-danger { background: rgba(244,63,94,0.10); color: var(--danger); border: 1px solid rgba(244,63,94,0.2); padding: 12px 16px; border-radius: var(--radius-sm); margin-bottom: 18px; font-size: 13px; font-weight: 600; }
</style>
</head>
<body>
<div class="app-layout">
<?php include 'includes/sidebar.php'; ?>
<main style="margin-left:220px; padding:32px; flex:1;">
    <div style="margin-bottom:20px;">
        <a href="booking.php" style="font-size:13px; color:var(--text-muted); text-decoration:none;">← Back to bookings</a>
    </div>

    <div class="cancel-card">
        <div style="text-align:center; margin-bottom:24px;">
            <div style="font-size:36px; margin-bottom:8px;">⚠️</div>
            <h2 style="font-family:'Playfair Display',serif; font-size:20px; font-weight:400;">Cancel Appointment</h2>
            <p style="font-size:13px; color:var(--text-muted); margin-top:6px;">Are you sure you want to cancel this appointment? This action cannot be undone.</p>
        </div>

        <?php if ($error): ?>
        <div class="alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <div class="appt-info">
            <div class="info-row">
                <span class="info-label">Doctor</span>
                <span><?= htmlspecialchars($appt['doctor_name']) ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Specialization</span>
                <span style="color:var(--text-muted);"><?= htmlspecialchars($appt['specialization']) ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Date & Time</span>
                <span><?= date('l, d M Y', strtotime($appt['appointment_date'])) ?> at <?= date('H:i', strtotime($appt['appointment_date'])) ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Queue Number</span>
                <span>#<?= $appt['queue_number'] ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Status</span>
                <span style="color:var(--info); font-weight:700;"><?= $appt['status'] ?></span>
            </div>
            <?php if ($appt['notes']): ?>
            <div class="info-row">
                <span class="info-label">Notes</span>
                <span><?= htmlspecialchars($appt['notes']) ?></span>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($appt['status'] === 'Scheduled'): ?>
        <form method="POST">
            <div style="display:flex; gap:12px; justify-content:center;">
                <a href="booking.php" class="btn btn-ghost">Keep Appointment</a>
                <button type="submit" name="confirm_cancel" class="btn btn-danger">Yes, Cancel It</button>
            </div>
        </form>
        <?php else: ?>
        <div style="text-align:center;">
            <p style="color:var(--text-muted); font-size:13px; margin-bottom:16px;">
                This appointment is <strong><?= $appt['status'] ?></strong> and cannot be cancelled.
            </p>
            <a href="booking.php" class="btn btn-ghost">Go Back</a>
        </div>
        <?php endif; ?>
    </div>
</main>
</div>
<script src="includes/interactions.js"></script>
</body>
</html>
