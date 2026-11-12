<?php
require_once 'includes/config.php';
requireLogin();

// ---- ADMIN STATS ----
if (isAdmin()) {
    $total_patients     = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM patient"))['c'];
    $total_doctors      = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM doctor"))['c'];
    $today_appointments = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM appointment WHERE DATE(appointment_date) = CURDATE()"))['c'];
    $today_revenue      = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(amount),0) c FROM payment WHERE payment_date = CURDATE() AND status='Paid'"))['c'];

    $recent_appointments = mysqli_query($conn, "
        SELECT a.appointment_id, p.name patient_name, d.name doctor_name,
               a.appointment_date, a.status, a.queue_number
        FROM appointment a
        JOIN patient p ON a.patient_id = p.patient_id
        JOIN doctor  d ON a.doctor_id  = d.doctor_id
        ORDER BY a.appointment_date DESC LIMIT 7
    ");
    $low_stock = mysqli_query($conn, "SELECT name, stock, type FROM medication WHERE stock < 20 ORDER BY stock ASC LIMIT 6");
}

// ---- DOCTOR STATS ----
if (isDoctor()) {
    $doc_id = getDoctorId($conn);

    $today_patients = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM appointment WHERE doctor_id=$doc_id AND DATE(appointment_date)=CURDATE() AND status='Scheduled'"))['c'];
    $total_handled  = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM examination_history WHERE doctor_id=$doc_id"))['c'];
    $pending_exams  = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM appointment WHERE doctor_id=$doc_id AND status='Scheduled'"))['c'];

    $my_schedule = mysqli_query($conn, "
        SELECT a.appointment_id, p.name patient_name, a.appointment_date, a.queue_number, a.status
        FROM appointment a
        JOIN patient p ON a.patient_id = p.patient_id
        WHERE a.doctor_id = $doc_id AND DATE(a.appointment_date) = CURDATE()
        ORDER BY a.queue_number ASC LIMIT 8
    ");
    $doc_info = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM doctor WHERE doctor_id=$doc_id"));
}

// ---- PATIENT STATS ----
if (isPatient()) {
    $pat_id = getPatientId($conn);

    $total_visits   = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM examination_history WHERE patient_id=$pat_id"))['c'];
    $pending_appt   = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM appointment WHERE patient_id=$pat_id AND status='Scheduled'"))['c'];
    $total_bills    = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(amount),0) c FROM payment p JOIN examination_history e ON p.exam_id=e.exam_id WHERE e.patient_id=$pat_id"))['c'];

    $my_appointments = mysqli_query($conn, "
        SELECT a.appointment_id, d.name doctor_name, d.specialization,
               a.appointment_date, a.queue_number, a.status
        FROM appointment a
        JOIN doctor d ON a.doctor_id = d.doctor_id
        WHERE a.patient_id = $pat_id
        ORDER BY a.appointment_date DESC LIMIT 5
    ");
    $my_records = mysqli_query($conn, "
        SELECT e.exam_date, d.name doctor_name, e.diagnosis, e.symptoms
        FROM examination_history e
        JOIN doctor d ON e.doctor_id = d.doctor_id
        WHERE e.patient_id = $pat_id
        ORDER BY e.exam_date DESC LIMIT 4
    ");
    $pat_info = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM patient WHERE patient_id=$pat_id"));
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard — IS Class 2 Healthcare</title>
<link rel="stylesheet" href="includes/theme.css">
<style>
.welcome-banner {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    padding: 20px 24px;
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.welcome-text h3 {
    font-family: 'Playfair Display', serif;
    font-size: 18px;
    font-weight: 400;
    color: var(--text);
    margin-bottom: 4px;
}
.welcome-text p { font-size: 12px; color: var(--text-muted); }
.welcome-badge {
    font-size: 11px;
    padding: 5px 14px;
    border-radius: 20px;
    background: var(--accent-bg);
    color: var(--accent-text);
    font-weight: 500;
    text-transform: capitalize;
}
.section-title {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .07em;
    color: var(--text-muted);
    margin-bottom: 12px;
    font-weight: 500;
}
.quick-actions {
    display: flex;
    gap: 10px;
    margin-bottom: 24px;
    flex-wrap: wrap;
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
        <!-- Welcome Banner -->
        <div class="welcome-banner">
            <div class="welcome-text">
                <h3>Hello, <?= htmlspecialchars($_SESSION['username']) ?> 👋</h3>
                <p><?= date('l, d F Y') ?> &mdash;
                <?php
                    $hour = date('H');
                    if ($hour < 12) echo 'Good morning!';
                    elseif ($hour < 15) echo 'Good afternoon!';
                    elseif ($hour < 18) echo 'Good evening!';
                    else echo 'Good night!';
                ?>
                </p>
            </div>
            <span class="welcome-badge">
                <?php
                    if (isAdmin()) echo '👑 Admin';
                    elseif (isDoctor()) echo 'Doctor';
                    else echo 'Patient';
                ?>
            </span>
        </div>

        <?php /* ==================== ADMIN VIEW ==================== */ ?>
        <?php if (isAdmin()): ?>

        <div class="page-header">
            <h2>📊 Dashboard Admin</h2>
            <p class="page-sub">Today's clinic overview</p>
        </div>

        <div class="quick-actions">
            <a href="patients.php?action=add"     class="btn btn-primary">Add Patient</a>
            <a href="appointments.php?action=add" class="btn btn-outline">New Appointment</a>
            <a href="payments.php"                class="btn btn-outline">Manage Payments</a>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Total Patients</div>
                <div class="stat-val"><?= $total_patients ?></div>
                <div class="stat-sub">Registered</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Total Doctors</div>
                <div class="stat-val"><?= $total_doctors ?></div>
                <div class="stat-sub">Active</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Today's Appointments</div>
                <div class="stat-val"><?= $today_appointments ?></div>
                <div class="stat-sub"><?= date('d M Y') ?></div>
            </div>
            <div class="stat-card gold">
                <div class="stat-label">Today's Revenue</div>
                <div class="stat-val">Rp <?= number_format($today_revenue, 0, ',', '.') ?></div>
                <div class="stat-sub">Paid today</div>
            </div>
        </div>

        <div class="two-col">
            <div class="panel">
                <div class="panel-header">
                    <div class="panel-title"><?= icon('appointments', 14) ?> Recent Appointments</div>
                    <a href="appointments.php" class="panel-link">View all →</a>
                </div>
                <?php if (mysqli_num_rows($recent_appointments) > 0): ?>
                <table class="data-table">
                    <thead><tr>
                        <th>Patient</th><th>Doctor</th><th>Date</th><th>Status</th>
                    </tr></thead>
                    <tbody>
                    <?php while ($row = mysqli_fetch_assoc($recent_appointments)): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['patient_name']) ?></td>
                        <td><?= htmlspecialchars($row['doctor_name']) ?></td>
                        <td><?= date('d M, H:i', strtotime($row['appointment_date'])) ?></td>
                        <td><span class="badge badge-<?= strtolower($row['status']) ?>"><?= $row['status'] ?></span></td>
                    </tr>
                    <?php endwhile; ?>
                    </tbody>
                </table>
                <?php else: ?>
                    <div class="empty-state">No appointments yet</div>
                <?php endif; ?>
            </div>

            <div class="panel">
                <div class="panel-header">
                    <div class="panel-title"><?= icon('medications', 14) ?> Low Stock</div>
                    <a href="medications.php" class="panel-link">View →</a>
                </div>
                <?php
                $low_rows = mysqli_fetch_all($low_stock, MYSQLI_ASSOC);
                if (count($low_rows) > 0):
                    foreach ($low_rows as $med):
                ?>
                <div class="stock-item">
                    <div>
                        <div class="stock-name"><?= htmlspecialchars($med['name']) ?></div>
                        <div class="stock-type"><?= htmlspecialchars($med['type']) ?></div>
                    </div>
                    <span class="stock-count"><?= $med['stock'] ?> unit</span>
                </div>
                <?php endforeach; else: ?>
                    <div class="empty-state">All stock levels are fine</div>
                <?php endif; ?>
            </div>
        </div>

        <?php /* ==================== DOCTOR VIEW ==================== */ ?>
        <?php elseif (isDoctor()): ?>

        <div class="page-header">
            <h2>📊 Doctor Dashboard</h2>
            <p class="page-sub"><?= htmlspecialchars($doc_info['specialization'] ?? '') ?></p>
        </div>

        <div class="stats-grid" style="grid-template-columns:repeat(3,1fr);">
            <div class="stat-card gold">
                <div class="stat-label">Today's Patients</div>
                <div class="stat-val"><?= $today_patients ?></div>
                <div class="stat-sub">Waiting</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Total Handled</div>
                <div class="stat-val"><?= $total_handled ?></div>
                <div class="stat-sub">All time</div>
            </div>
            <div class="stat-card">
                <div class="stat-label"><?= icon('appointments', 13) ?> Active Queue</div>
                <div class="stat-val"><?= $pending_exams ?></div>
                <div class="stat-sub">Pending</div>
            </div>
        </div>

        <div class="panel">
            <div class="panel-header">
                <div class="panel-title">Today's Schedule</div>
                <a href="appointments.php" class="panel-link">View all →</a>
            </div>
            <?php if (mysqli_num_rows($my_schedule) > 0): ?>
            <table class="data-table">
                <thead><tr>
                    <th>Queue No.</th><th>Patient</th><th>Time</th><th>Status</th><th>Action</th>
                </tr></thead>
                <tbody>
                <?php while ($row = mysqli_fetch_assoc($my_schedule)): ?>
                <tr>
                    <td><strong>#<?= $row['queue_number'] ?></strong></td>
                    <td><?= htmlspecialchars($row['patient_name']) ?></td>
                    <td><?= date('H:i', strtotime($row['appointment_date'])) ?></td>
                    <td><span class="badge badge-<?= strtolower($row['status']) ?>"><?= $row['status'] ?></span></td>
                    <td>
                        <a href="examinations.php?appointment_id=<?= $row['appointment_id'] ?>" class="btn btn-primary btn-sm">Examine</a>
                    </td>
                </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
            <?php else: ?>
                <div class="empty-state">No appointments scheduled today</div>
            <?php endif; ?>
        </div>

        <?php /* ==================== PATIENT VIEW ==================== */ ?>
        <?php elseif (isPatient()): ?>

        <div class="page-header">
            <h2>🏠 Patient Dashboard</h2>
            <p class="page-sub">Welcome, <?= htmlspecialchars($pat_info['name'] ?? '') ?></p>
        </div>

        <div class="quick-actions">
            <a href="booking.php" class="btn btn-outline">Book Appointment</a>
            <a href="my_records.php" class="btn btn-outline">My Records</a>
            <a href="my_payments.php" class="btn btn-outline">My Bills</a>
        </div>

        <div class="stats-grid" style="grid-template-columns:repeat(3,1fr);">
            <div class="stat-card">
                <div class="stat-label">Total Visits</div>
                <div class="stat-val"><?= $total_visits ?></div>
                <div class="stat-sub">All time</div>
            </div>
            <div class="stat-card gold">
                <div class="stat-label">Active Appointments</div>
                <div class="stat-val"><?= $pending_appt ?></div>
                <div class="stat-sub">Scheduled</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Total Bills</div>
                <div class="stat-val" style="font-size:20px;">Rp <?= number_format($total_bills, 0, ',', '.') ?></div>
                <div class="stat-sub">All time</div>
            </div>
        </div>

        <div class="two-col">
            <div class="panel">
                <div class="panel-header">
                    <div class="panel-title">My Appointments</div>
                    <a href="booking.php" class="panel-link">+ Book →</a>
                </div>
                <?php if (mysqli_num_rows($my_appointments) > 0): ?>
                <table class="data-table">
                    <thead><tr>
                        <th>Doctor</th><th>Specialist</th><th>Date</th><th>Status</th>
                    </tr></thead>
                    <tbody>
                    <?php while ($row = mysqli_fetch_assoc($my_appointments)): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['doctor_name']) ?></td>
                        <td><?= htmlspecialchars($row['specialization']) ?></td>
                        <td><?= date('d M Y, H:i', strtotime($row['appointment_date'])) ?></td>
                        <td><span class="badge badge-<?= strtolower($row['status']) ?>"><?= $row['status'] ?></span></td>
                    </tr>
                    <?php endwhile; ?>
                    </tbody>
                </table>
                <?php else: ?>
                    <div class="empty-state">No appointments yet. <a href="booking.php" style="color:var(--accent-h)">Book now →</a></div>
                <?php endif; ?>
            </div>

            <div class="panel">
                <div class="panel-header">
                    <div class="panel-title">Recent Medical Records</div>
                    <a href="my_records.php" class="panel-link">View All →</a>
                </div>
                <?php if (mysqli_num_rows($my_records) > 0): ?>
                <?php while ($row = mysqli_fetch_assoc($my_records)): ?>
                <div class="stock-item">
                    <div>
                        <div class="stock-name"><?= htmlspecialchars($row['diagnosis']) ?></div>
                        <div class="stock-type"><?= htmlspecialchars($row['doctor_name']) ?> &mdash; <?= date('d M Y', strtotime($row['exam_date'])) ?></div>
                    </div>
                </div>
                <?php endwhile; ?>
                <?php else: ?>
                    <div class="empty-state">No medical records yet</div>
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
