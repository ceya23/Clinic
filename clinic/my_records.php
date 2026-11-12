<?php
require_once 'includes/config.php';
requireLogin();
if (!isPatient()) { header("Location: dashboard.php"); exit(); }

$patient_id = getPatientId($conn);
$view_id    = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// ── FETCH ALL RECORDS ─────────────────────────────────────────────────────────
$records = mysqli_query($conn, "
    SELECT e.*, d.name doctor_name, d.specialization
    FROM examination_history e
    JOIN doctor d ON e.doctor_id = d.doctor_id
    WHERE e.patient_id = $patient_id
    ORDER BY e.exam_date DESC
");

// ── FETCH SINGLE RECORD ───────────────────────────────────────────────────────
$record = null;
$prescriptions = [];
if ($view_id) {
    $record = mysqli_fetch_assoc(mysqli_query($conn, "
        SELECT e.*, d.name doctor_name, d.specialization, d.phone doctor_phone
        FROM examination_history e
        JOIN doctor d ON e.doctor_id = d.doctor_id
        WHERE e.exam_id = $view_id AND e.patient_id = $patient_id
    "));
    if ($record) {
        $presc_result = mysqli_query($conn, "
            SELECT pr.*, m.name medication_name, m.type, m.price
            FROM prescription pr
            JOIN medication m ON pr.medication_id = m.medication_id
            WHERE pr.exam_id = $view_id
        ");
        $prescriptions = mysqli_fetch_all($presc_result, MYSQLI_ASSOC);
    }
}

// ── PATIENT INFO ──────────────────────────────────────────────────────────────
$patient = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM patient WHERE patient_id = $patient_id"));
$total_visits = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM examination_history WHERE patient_id = $patient_id"))['c'];
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Medical Records — IS Class 2 Healthcare</title>
<link rel="stylesheet" href="includes/theme.css">
<style>
.record-card {
    background: var(--surface); border: 1.5px solid var(--border);
    border-radius: var(--radius-md); padding: 18px 20px;
    display: flex; align-items: center; gap: 16px;
    margin-bottom: 10px; transition: all .2s;
    text-decoration: none; cursor: pointer;
}
.record-card:hover { border-color: var(--accent); transform: translateX(3px); }
.record-card.active { border-color: var(--accent); background: var(--accent-bg); }
.date-box {
    width: 54px; height: 54px; border-radius: var(--radius-sm);
    background: var(--accent-bg); display: flex; flex-direction: column;
    align-items: center; justify-content: center; flex-shrink: 0;
    border: 1.5px solid var(--accent);
}
.date-box .day { font-size: 20px; font-weight: 700; color: var(--accent); line-height: 1; }
.date-box .mon { font-size: 10px; color: var(--accent-text); text-transform: uppercase; font-weight: 600; }
.record-info { flex: 1; }
.record-diagnosis { font-size: 15px; font-weight: 700; color: var(--text); }
.record-doctor { font-size: 12px; color: var(--text-muted); margin-top: 3px; }
.record-symptoms {
    font-size: 11px; color: var(--text-muted);
    background: var(--surface2); padding: 2px 8px;
    border-radius: 20px; display: inline-block; margin-top: 5px;
}

.detail-panel {
    background: var(--surface); border: 1.5px solid var(--border);
    border-radius: var(--radius-md); overflow: hidden; position: sticky; top: 20px;
}
.detail-header {
    background: linear-gradient(135deg, var(--accent-bg), var(--surface3));
    border-bottom: 1.5px solid var(--border);
    padding: 20px 24px;
}
.detail-body { padding: 20px 24px; }
.info-row {
    display: flex; flex-direction: column;
    padding: 10px 0; border-bottom: 1px solid var(--border);
}
.info-row:last-child { border: none; }
.info-label { font-size: 10px; text-transform: uppercase; letter-spacing:.06em; color: var(--text-muted); font-weight: 700; margin-bottom: 4px; }
.info-value { font-size: 13px; color: var(--text); font-weight: 500; line-height: 1.5; }

.presc-item {
    display: flex; align-items: center; gap: 10px;
    padding: 10px 0; border-bottom: 1px solid var(--border); font-size: 13px;
}
.presc-item:last-child { border: none; }

.empty-detail {
    padding: 40px 24px; text-align: center; color: var(--text-muted);
}
.empty-detail .empty-icon { font-size: 40px; margin-bottom: 12px; }

.two-col-records { display: grid; grid-template-columns: 1fr 380px; gap: 20px; align-items: start; }
@media(max-width:900px) { .two-col-records { grid-template-columns: 1fr; } }

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

        <div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
            <div>
                <h2>🩺 My Medical Records</h2>
                <p class="page-sub"><?= $total_visits ?> total visit<?= $total_visits != 1 ? 's' : '' ?></p>
            </div>
            <a href="booking.php" class="btn btn-primary">📅 Book Appointment</a>
        </div>

        <?php if (mysqli_num_rows($records) === 0): ?>
            <div class="panel">
                <div class="empty-state">
                    <div style="font-size:40px;margin-bottom:12px;">🩺</div>
                    No medical records found. <a href="booking.php" style="color:var(--accent);">Book your first appointment →</a>
                </div>
            </div>
        <?php else: ?>
        <div class="two-col-records">
            <!-- Left: list -->
            <div>
                <?php while ($row = mysqli_fetch_assoc($records)): ?>
                <a href="my_records.php?id=<?= $row['exam_id'] ?>" class="record-card <?= $view_id == $row['exam_id'] ? 'active' : '' ?>">
                    <div class="date-box">
                        <div class="day"><?= date('d', strtotime($row['exam_date'])) ?></div>
                        <div class="mon"><?= date('M Y', strtotime($row['exam_date'])) ?></div>
                    </div>
                    <div class="record-info">
                        <div class="record-diagnosis">🔬 <?= htmlspecialchars($row['diagnosis'] ?? 'No diagnosis') ?></div>
                        <div class="record-doctor">👨‍⚕️ <?= htmlspecialchars($row['doctor_name']) ?> · <?= htmlspecialchars($row['specialization']) ?></div>
                        <?php if ($row['symptoms']): ?>
                        <span class="record-symptoms">💬 <?= htmlspecialchars(substr($row['symptoms'], 0, 50)) ?><?= strlen($row['symptoms']) > 50 ? '...' : '' ?></span>
                        <?php endif; ?>
                    </div>
                    <div style="color:var(--text-faint);font-size:18px;">›</div>
                </a>
                <?php endwhile; ?>
            </div>

            <!-- Right: detail -->
            <div class="detail-panel">
                <?php if ($record): ?>
                    <div class="detail-header">
                        <div style="font-size:13px;font-weight:700;color:var(--text);margin-bottom:2px;">
                            🔬 <?= htmlspecialchars($record['diagnosis'] ?? 'No diagnosis') ?>
                        </div>
                        <div style="font-size:11px;color:var(--text-muted);">
                            <?= date('d F Y', strtotime($record['exam_date'])) ?>
                        </div>
                    </div>
                    <div class="detail-body">
                        <div class="info-row">
                            <span class="info-label">Doctor</span>
                            <span class="info-value">👨‍⚕️ <?= htmlspecialchars($record['doctor_name']) ?>
                                <span style="color:var(--text-muted);font-size:11px;">(<?= htmlspecialchars($record['specialization']) ?>)</span>
                            </span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Symptoms</span>
                            <span class="info-value"><?= htmlspecialchars($record['symptoms'] ?? '—') ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Diagnosis</span>
                            <span class="info-value" style="color:var(--accent);font-weight:700;">
                                <?= htmlspecialchars($record['diagnosis'] ?? '—') ?>
                            </span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Doctor's Notes</span>
                            <span class="info-value"><?= htmlspecialchars($record['notes'] ?? '—') ?></span>
                        </div>

                        <?php if (count($prescriptions) > 0): ?>
                        <div style="margin-top:16px;">
                            <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin-bottom:10px;">
                                💊 Prescriptions
                            </div>
                            <?php foreach ($prescriptions as $pr): ?>
                            <div class="presc-item">
                                <span style="font-size:18px;">💊</span>
                                <div style="flex:1;">
                                    <div style="font-weight:600;color:var(--text);"><?= htmlspecialchars($pr['medication_name']) ?></div>
                                    <div style="font-size:11px;color:var(--text-muted);"><?= $pr['dosage'] ?> · <?= $pr['quantity'] ?> unit(s)</div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-detail">
                        <div class="empty-icon">👈</div>
                        <div style="font-size:13px;font-weight:600;color:var(--text);">Select a record</div>
                        <div style="font-size:12px;margin-top:4px;">Click any record on the left to view details</div>
                    </div>
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
