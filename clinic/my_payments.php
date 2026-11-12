<?php
require_once 'includes/config.php';
requireLogin();
if (!isPatient()) { header("Location: dashboard.php"); exit(); }

$patient_id = getPatientId($conn);

// ── FETCH PAYMENTS ────────────────────────────────────────────────────────────
$payments = mysqli_query($conn, "
    SELECT p.*, e.exam_date, e.diagnosis, d.name doctor_name, d.specialization
    FROM payment p
    JOIN examination_history e ON p.exam_id = e.exam_id
    JOIN doctor d ON e.doctor_id = d.doctor_id
    WHERE e.patient_id = $patient_id
    ORDER BY p.payment_date DESC
");

$rows = mysqli_fetch_all($payments, MYSQLI_ASSOC);

// Stats
$total_paid    = array_sum(array_column(array_filter($rows, fn($r) => $r['status'] === 'Paid'), 'amount'));
$total_pending = array_sum(array_column(array_filter($rows, fn($r) => $r['status'] === 'Pending'), 'amount'));
$total_visits  = count($rows);
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Bills — IS Class 2 Healthcare</title>
<link rel="stylesheet" href="includes/theme.css">
<style>
.bill-card {
    background: var(--surface); border: 1.5px solid var(--border);
    border-radius: var(--radius-md); padding: 18px 22px;
    display: flex; align-items: center; gap: 16px;
    margin-bottom: 10px; transition: all .2s;
}
.bill-card:hover { border-color: var(--border-h); }
.bill-card.pending { border-left: 4px solid var(--danger); }
.bill-card.paid    { border-left: 4px solid var(--success); }
.bill-icon {
    width: 44px; height: 44px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; flex-shrink: 0;
}
.bill-icon.paid    { background: rgba(52,211,153,0.15); }
.bill-icon.pending { background: rgba(251,191,36,0.15); }
.bill-info { flex: 1; }
.bill-diagnosis { font-size: 14px; font-weight: 700; color: var(--text); }
.bill-doctor    { font-size: 12px; color: var(--text-muted); margin-top: 3px; }
.bill-date      { font-size: 11px; color: var(--text-faint); margin-top: 2px; }
.bill-amount {
    text-align: right; flex-shrink: 0;
}
.bill-amount .amount {
    font-family: 'Playfair Display', serif;
    font-size: 18px; font-weight: 400; color: var(--text);
}
.bill-amount .method { font-size: 11px; color: var(--text-muted); margin-top: 3px; }

.summary-banner {
    background: var(--surface); border: 1.5px solid var(--border);
    border-radius: var(--radius-md); padding: 20px 24px;
    display: grid; grid-template-columns: repeat(3,1fr);
    gap: 0; margin-bottom: 24px;
}
.summary-item { text-align: center; padding: 0 20px; }
.summary-item:not(:last-child) { border-right: 1px solid var(--border); }
.summary-val {
    font-family: 'Playfair Display', serif;
    font-size: 24px; font-weight: 400; color: var(--text);
}
.summary-label { font-size: 11px; color: var(--text-muted); font-weight: 600; margin-top: 4px; text-transform: uppercase; letter-spacing: .05em; }

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

        <div class="page-header">
            <h2>💳 My Bills</h2>
            <p class="page-sub">Your payment history</p>
        </div>

        <!-- Summary -->
        <div class="summary-banner">
            <div class="summary-item">
                <div class="summary-val"><?= $total_visits ?></div>
                <div class="summary-label">Total Visits</div>
            </div>
            <div class="summary-item">
                <div class="summary-val" style="color:var(--success);">Rp <?= number_format($total_paid, 0, ',', '.') ?></div>
                <div class="summary-label">Total Paid</div>
            </div>
            <div class="summary-item">
                <div class="summary-val" style="color:var(--danger);">Rp <?= number_format($total_pending, 0, ',', '.') ?></div>
                <div class="summary-label">Pending</div>
            </div>
        </div>

        <?php if (count($rows) === 0): ?>
            <div class="panel">
                <div class="empty-state">
                    <div style="font-size:40px;margin-bottom:12px;">💳</div>
                    No payment records found.
                </div>
            </div>
        <?php else: ?>
            <?php if ($total_pending > 0): ?>
            <div class="alert alert-danger" style="display:flex;align-items:center;gap:10px;">
                ⚠️ You have <strong>Rp <?= number_format($total_pending, 0, ',', '.') ?></strong> in pending payments. Please settle at the clinic counter.
            </div>
            <?php endif; ?>

            <?php foreach ($rows as $row): ?>
            <div class="bill-card <?= strtolower($row['status']) ?>">
                <div class="bill-icon <?= strtolower($row['status']) ?>">
                    <?= $row['status'] === 'Paid' ? '✅' : '⏳' ?>
                </div>
                <div class="bill-info">
                    <div class="bill-diagnosis">🔬 <?= htmlspecialchars($row['diagnosis'] ?? 'Consultation') ?></div>
                    <div class="bill-doctor">👨‍⚕️ <?= htmlspecialchars($row['doctor_name']) ?> · <?= htmlspecialchars($row['specialization']) ?></div>
                    <div class="bill-date">📅 <?= date('d F Y', strtotime($row['exam_date'])) ?></div>
                </div>
                <div class="bill-amount">
                    <div class="amount">Rp <?= number_format($row['amount'], 0, ',', '.') ?></div>
                    <div class="method"><?= $row['method'] ?? '—' ?></div>
                    <div style="margin-top:6px;">
                        <span class="badge badge-<?= strtolower($row['status']) ?>"><?= $row['status'] ?></span>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
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
