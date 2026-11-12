<?php
require_once 'includes/config.php';
requireRole(['admin']);
function array_max($arr) { return empty($arr) ? 0 : max($arr); }

// ── DATE RANGE FILTER ─────────────────────────────────────────────────────────
$range      = $_GET['range'] ?? 'month';   // month | week | year | custom
$date_from  = $_GET['from']  ?? '';
$date_to    = $_GET['to']    ?? '';

switch ($range) {
    case 'week':
        $from = date('Y-m-d', strtotime('monday this week'));
        $to   = date('Y-m-d');
        break;
    case 'year':
        $from = date('Y-01-01');
        $to   = date('Y-m-d');
        break;
    case 'custom':
        $from = $date_from ?: date('Y-m-01');
        $to   = $date_to   ?: date('Y-m-d');
        break;
    default: // month
        $from = date('Y-m-01');
        $to   = date('Y-m-d');
        break;
}

// FIX #1: Validate date format before using in queries
$from = preg_match("/^\\d{4}-\\d{2}-\\d{2}$/", $from) ? $from : date("Y-m-01");
$to   = preg_match("/^\\d{4}-\\d{2}-\\d{2}$/", $to)   ? $to   : date("Y-m-d");
$from_sql = $from;
$to_sql   = $to;

// ── SUMMARY STATS ─────────────────────────────────────────────────────────────
$total_revenue = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COALESCE(SUM(amount),0) c FROM payment WHERE status='Paid' AND payment_date BETWEEN '$from_sql' AND '$to_sql'"))['c'];

$total_appointments = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) c FROM appointment WHERE DATE(appointment_date) BETWEEN '$from_sql' AND '$to_sql'"))['c'];

$total_examinations = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) c FROM examination_history WHERE DATE(exam_date) BETWEEN '$from_sql' AND '$to_sql'"))['c'];

$total_new_patients = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) c FROM patient WHERE DATE(created_at) BETWEEN '$from_sql' AND '$to_sql'"))['c'];

$pending_payments = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) c FROM payment WHERE status='Pending'"))['c'];

$low_stock_count = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) c FROM medication WHERE stock < 20"))['c'];

// ── REVENUE BY MONTH (last 6 months) ─────────────────────────────────────────
$revenue_by_month = mysqli_query($conn, "
    SELECT DATE_FORMAT(payment_date,'%b %Y') month_label,
           DATE_FORMAT(payment_date,'%Y-%m') month_key,
           COALESCE(SUM(amount),0) total
    FROM payment
    WHERE status='Paid'
      AND payment_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY month_key, month_label
    ORDER BY month_key ASC
");
$rev_months = [];
$rev_totals = [];
while ($r = mysqli_fetch_assoc($revenue_by_month)) {
    $rev_months[] = $r['month_label'];
    $rev_totals[] = (float)$r['total'];
}

// ── TOP DOCTORS (by exams) ────────────────────────────────────────────────────
$top_doctors = mysqli_query($conn, "
    SELECT d.name, d.specialization,
           COUNT(e.exam_id) total_exams,
           COUNT(DISTINCT a.appointment_id) total_appointments
    FROM doctor d
    LEFT JOIN examination_history e ON d.doctor_id = e.doctor_id
         AND DATE(e.exam_date) BETWEEN '$from_sql' AND '$to_sql'
    LEFT JOIN appointment a ON d.doctor_id = a.doctor_id
         AND DATE(a.appointment_date) BETWEEN '$from_sql' AND '$to_sql'
    GROUP BY d.doctor_id
    ORDER BY total_exams DESC
    LIMIT 8
");

// ── TOP MEDICATIONS (prescribed) ─────────────────────────────────────────────
$top_meds = mysqli_query($conn, "
    SELECT m.name, m.type, SUM(pr.quantity) total_qty, m.stock
    FROM prescription pr
    JOIN medication m ON pr.medication_id = m.medication_id
    JOIN examination_history e ON pr.exam_id = e.exam_id
    WHERE DATE(e.exam_date) BETWEEN '$from_sql' AND '$to_sql'
    GROUP BY m.medication_id
    ORDER BY total_qty DESC
    LIMIT 8
");

// ── LOW STOCK MEDICATIONS ─────────────────────────────────────────────────────
$low_stock = mysqli_query($conn, "
    SELECT name, type, stock, price
    FROM medication
    WHERE stock < 20
    ORDER BY stock ASC
");

// ── APPOINTMENTS BY STATUS ────────────────────────────────────────────────────
$appt_status = mysqli_query($conn, "
    SELECT status, COUNT(*) c
    FROM appointment
    WHERE DATE(appointment_date) BETWEEN '$from_sql' AND '$to_sql'
    GROUP BY status
");
$appt_data = ['Scheduled' => 0, 'Completed' => 0, 'Cancelled' => 0];
while ($r = mysqli_fetch_assoc($appt_status)) $appt_data[$r['status']] = (int)$r['c'];

// ── DAILY REVENUE (for range) ─────────────────────────────────────────────────
$daily_rev = mysqli_query($conn, "
    SELECT DATE_FORMAT(payment_date,'%d %b') day_label, SUM(amount) total
    FROM payment
    WHERE status='Paid' AND payment_date BETWEEN '$from_sql' AND '$to_sql'
    GROUP BY payment_date ORDER BY payment_date ASC
    LIMIT 30
");
$daily_labels = $daily_totals = [];
while ($r = mysqli_fetch_assoc($daily_rev)) {
    $daily_labels[] = $r['day_label'];
    $daily_totals[] = (float)$r['total'];
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reports — IS Class 2 Healthcare</title>
<link rel="stylesheet" href="includes/theme.css">

<style>
.stat-card {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: var(--radius-md); padding: 18px 22px;
    display: flex; align-items: center; gap: 16px;
}
.stat-icon {
    width: 44px; height: 44px; border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; flex-shrink: 0;
}
.stat-num {
    font-family: 'Playfair Display', serif;
    font-size: 24px; font-weight: 400; color: var(--text);
    line-height: 1;
}
.stat-label { font-size: 11px; text-transform: uppercase; letter-spacing:.06em; color: var(--text-muted); margin-top: 4px; }
.stat-sub   { font-size: 11px; color: var(--success); margin-top: 3px; }
.section-card {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: var(--radius-md); padding: 22px 24px; margin-bottom: 20px;
}
.section-card h3 {
    font-size: 13px; font-weight: 700; text-transform: uppercase;
    letter-spacing:.06em; color: var(--text-muted); margin-bottom: 16px;
    padding-bottom: 10px; border-bottom: 1px solid var(--border);
}
.report-table { width: 100%; border-collapse: collapse; }
.report-table th {
    font-size: 11px; text-transform: uppercase; letter-spacing:.05em;
    color: var(--text-muted); padding: 8px 12px; text-align: left;
    border-bottom: 1px solid var(--border); font-weight: 700;
}
.report-table td {
    padding: 10px 12px; border-bottom: 1px solid var(--border);
    font-size: 13px; color: var(--text);
}
.report-table tr:last-child td { border-bottom: none; }
.report-table tr:hover td { background: var(--surface2); }
.badge {
    display: inline-block; padding: 2px 9px; border-radius: 12px;
    font-size: 11px; font-weight: 700;
}
.badge-pink  { background: var(--accent-bg); color: var(--accent-text); }
.badge-blue  { background: rgba(56,189,248,0.15); color: var(--info); }
.badge-red   { background: rgba(244,63,94,0.12); color: var(--danger); }
.badge-green { background: rgba(16,185,129,0.12); color: var(--success); }
.filter-bar {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: var(--radius-md); padding: 14px 20px;
    display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
    margin-bottom: 24px;
}
.range-btn {
    padding: 6px 16px; border-radius: 20px; border: 1px solid var(--border);
    background: var(--surface2); color: var(--text-muted);
    font-size: 12px; font-weight: 700; cursor: pointer;
    text-decoration: none; transition: all .15s;
}
.range-btn.active, .range-btn:hover { background: var(--accent); color: #fff; border-color: var(--accent); }
.canvas-wrap { position: relative; height: 200px; }
.progress-bar-wrap { margin-bottom: 10px; }
.progress-bar-bg { background: var(--surface2); border-radius: 6px; height: 8px; overflow: hidden; }
.progress-bar-fill { height: 8px; border-radius: 6px; background: linear-gradient(90deg, var(--accent), var(--accent2)); transition: width .5s; }

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
        <h2 style="font-family:'Playfair Display',serif; font-size:22px; font-weight:400;">Reports</h2>
        <p style="font-size:12px; color:var(--text-muted); margin-top:4px;">
            <?= date('d M Y', strtotime($from)) ?> — <?= date('d M Y', strtotime($to)) ?>
        </p>
    </div>
    <button onclick="window.print()" style="background:var(--surface2); border:1px solid var(--border); color:var(--text-muted); padding:9px 18px; border-radius:var(--radius-sm); cursor:pointer; font-size:13px; font-weight:700;">
        🖨 Print
    </button>
</div>

<!-- Date Filter -->
<div class="filter-bar">
    <span style="font-size:11px; font-weight:700; color:var(--text-muted); text-transform:uppercase;">Period:</span>
    <?php foreach (['week'=>'This Week','month'=>'This Month','year'=>'This Year'] as $k=>$label): ?>
    <a href="?range=<?= $k ?>" class="range-btn <?= $range===$k?'active':'' ?>"><?= $label ?></a>
    <?php endforeach; ?>
    <form method="GET" style="display:flex; gap:8px; align-items:center; margin-left:auto;">
        <input type="hidden" name="range" value="custom">
        <input type="date" name="from" value="<?= htmlspecialchars($from) ?>"
            style="padding:6px 10px; background:var(--surface2); border:1px solid var(--border); border-radius:8px; color:var(--text); font-size:12px;">
        <span style="color:var(--text-muted); font-size:12px;">to</span>
        <input type="date" name="to" value="<?= htmlspecialchars($to) ?>"
            style="padding:6px 10px; background:var(--surface2); border:1px solid var(--border); border-radius:8px; color:var(--text); font-size:12px;">
        <button type="submit" style="padding:6px 14px; background:var(--accent); color:#fff; border:none; border-radius:8px; cursor:pointer; font-size:12px; font-weight:700;">Apply</button>
    </form>
</div>

<!-- SUMMARY STATS -->
<div style="display:grid; grid-template-columns:repeat(3,1fr); gap:14px; margin-bottom:24px;">
    <div class="stat-card">
        <div class="stat-icon" style="background:rgba(232,121,160,0.15);">💰</div>
        <div>
            <div class="stat-num">Rp <?= number_format($total_revenue,0,',','.') ?></div>
            <div class="stat-label">Revenue (paid)</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:rgba(56,189,248,0.15);">📅</div>
        <div>
            <div class="stat-num"><?= $total_appointments ?></div>
            <div class="stat-label">Appointments</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:rgba(16,185,129,0.15);">🩺</div>
        <div>
            <div class="stat-num"><?= $total_examinations ?></div>
            <div class="stat-label">Examinations</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:rgba(232,121,160,0.15);">👤</div>
        <div>
            <div class="stat-num"><?= $total_new_patients ?></div>
            <div class="stat-label">New patients</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:rgba(251,191,36,0.15);">⏳</div>
        <div>
            <div class="stat-num"><?= $pending_payments ?></div>
            <div class="stat-label">Pending payments</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:rgba(244,63,94,0.15);">💊</div>
        <div>
            <div class="stat-num"><?= $low_stock_count ?></div>
            <div class="stat-label">Low stock medications</div>
        </div>
    </div>
</div>

<!-- CHARTS ROW -->
<div style="display:grid; grid-template-columns:2fr 1fr; gap:18px; margin-bottom:20px;">
    <!-- Revenue by Month -->
    <div class="section-card" style="margin-bottom:0;">
        <h3>Revenue — Last 6 Months</h3>
        <div class="canvas-wrap"><canvas id="revenueChart"></canvas></div>
    </div>
    <!-- Appointments by Status -->
    <div class="section-card" style="margin-bottom:0;">
        <h3>Appointment Status</h3>
        <div class="canvas-wrap"><canvas id="statusChart"></canvas></div>
        <div style="margin-top:12px;">
            <?php foreach ($appt_data as $status => $count): ?>
            <div style="display:flex; justify-content:space-between; font-size:12px; margin-bottom:4px;">
                <span style="color:var(--text-muted);"><?= $status ?></span>
                <strong><?= $count ?></strong>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Daily Revenue in period -->
<?php if (!empty($daily_labels)): ?>
<div class="section-card">
    <h3>Daily Revenue in Period</h3>
    <div style="position:relative; height:160px;"><canvas id="dailyChart"></canvas></div>
</div>
<?php endif; ?>

<!-- TOP DOCTORS & TOP MEDS -->
<div style="display:grid; grid-template-columns:1fr 1fr; gap:18px; margin-bottom:20px;">
    <!-- Top Doctors -->
    <div class="section-card" style="margin-bottom:0;">
        <h3>Doctors — Exams Handled</h3>
        <?php
        $top_doc_rows = mysqli_fetch_all($top_doctors, MYSQLI_ASSOC);
        $max_exams = max(1, array_max(array_column($top_doc_rows, 'total_exams')));
        foreach ($top_doc_rows as $dr):
            $pct = $max_exams > 0 ? round($dr['total_exams'] / $max_exams * 100) : 0;
        ?>
        <div class="progress-bar-wrap">
            <div style="display:flex; justify-content:space-between; font-size:12px; margin-bottom:4px;">
                <span><?= htmlspecialchars($dr['name']) ?> <span style="color:var(--text-muted); font-size:11px;">(<?= $dr['specialization'] ?>)</span></span>
                <strong><?= $dr['total_exams'] ?></strong>
            </div>
            <div class="progress-bar-bg"><div class="progress-bar-fill" style="width:<?= $pct ?>%"></div></div>
        </div>
        <?php endforeach; ?>
        <?php if (empty($top_doc_rows)): ?>
        <p style="color:var(--text-muted); font-size:13px;">No data in this period.</p>
        <?php endif; ?>
    </div>

    <!-- Top Medications -->
    <div class="section-card" style="margin-bottom:0;">
        <h3>Top Prescribed Medications</h3>
        <table class="report-table">
            <thead><tr><th>Medication</th><th>Type</th><th>Qty Used</th><th>Stock</th></tr></thead>
            <tbody>
            <?php
            $med_rows = mysqli_fetch_all($top_meds, MYSQLI_ASSOC);
            if (empty($med_rows)):
            ?>
            <tr><td colspan="4" style="color:var(--text-muted); text-align:center;">No data.</td></tr>
            <?php else: foreach ($med_rows as $m): ?>
            <tr>
                <td><?= htmlspecialchars($m['name']) ?></td>
                <td><span class="badge badge-blue"><?= htmlspecialchars($m['type']) ?></span></td>
                <td><strong><?= $m['total_qty'] ?></strong></td>
                <td>
                    <span class="badge <?= $m['stock'] < 20 ? 'badge-red' : 'badge-green' ?>">
                        <?= $m['stock'] ?>
                    </span>
                </td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- LOW STOCK -->
<?php if (mysqli_num_rows($low_stock) > 0): ?>
<div class="section-card">
    <h3>⚠ Low Stock Alert (under 20 units)</h3>
    <table class="report-table">
        <thead><tr><th>Medication</th><th>Type</th><th>Stock</th><th>Price</th></tr></thead>
        <tbody>
        <?php while ($m = mysqli_fetch_assoc($low_stock)): ?>
        <tr>
            <td><?= htmlspecialchars($m['name']) ?></td>
            <td><span class="badge badge-blue"><?= htmlspecialchars($m['type']) ?></span></td>
            <td><span class="badge badge-red"><?= $m['stock'] ?> units</span></td>
            <td>Rp <?= number_format($m['price'],0,',','.') ?></td>
        </tr>
        <?php endwhile; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

</main>
</div>

<script src="includes/interactions.js"></script>
<!-- Chart.js: try CDN, fallback to unpkg -->
<script>
(function() {
    function loadScript(src, cb) {
        var s = document.createElement('script');
        s.src = src;
        s.onload = cb;
        s.onerror = function() {
            // CDN failed, try fallback
            loadScript('https://unpkg.com/chart.js@4.4.1/dist/chart.umd.min.js', cb);
        };
        document.head.appendChild(s);
    }
    loadScript('https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js', initCharts);

    function initCharts() {
        if (typeof Chart === 'undefined') {
            document.querySelectorAll('.canvas-wrap').forEach(function(el) {
                el.innerHTML = '<p style="color:var(--text-muted);font-size:12px;text-align:center;padding-top:80px;">Chart tidak dapat dimuat. Periksa koneksi internet.</p>';
            });
            return;
        }

const isDark = document.documentElement.getAttribute('data-theme') !== 'light';
const gridColor = isDark ? 'rgba(255,255,255,0.06)' : 'rgba(0,0,0,0.06)';
const textColor = isDark ? '#d8a8c8' : '#9d6b8a';

Chart.defaults.font.family = "'Nunito', sans-serif";
Chart.defaults.color = textColor;

// Revenue by Month
const revCtx = document.getElementById('revenueChart').getContext('2d');
new Chart(revCtx, {
    type: 'bar',
    data: {
        labels: <?= json_encode($rev_months) ?>,
        datasets: [{
            label: 'Revenue (Rp)',
            data: <?= json_encode($rev_totals) ?>,
            backgroundColor: 'rgba(232,121,160,0.5)',
            borderColor: '#e879a0',
            borderWidth: 1.5,
            borderRadius: 6,
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            x: { grid: { color: gridColor }, ticks: { font: { size: 11 } } },
            y: { grid: { color: gridColor }, ticks: {
                font: { size: 11 },
                callback: v => 'Rp ' + (v/1000).toFixed(0) + 'k'
            }}
        }
    }
});

// Appointment Status Doughnut
const statusCtx = document.getElementById('statusChart').getContext('2d');
new Chart(statusCtx, {
    type: 'doughnut',
    data: {
        labels: ['Scheduled','Completed','Cancelled'],
        datasets: [{
            data: [
                <?= $appt_data['Scheduled'] ?>,
                <?= $appt_data['Completed'] ?>,
                <?= $appt_data['Cancelled'] ?>
            ],
            backgroundColor: ['rgba(56,189,248,0.7)','rgba(16,185,129,0.7)','rgba(244,63,94,0.7)'],
            borderWidth: 0,
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        cutout: '70%',
        plugins: { legend: { display: false } }
    }
});

<?php if (!empty($daily_labels)): ?>
// Daily Revenue
const dailyCtx = document.getElementById('dailyChart').getContext('2d');
new Chart(dailyCtx, {
    type: 'line',
    data: {
        labels: <?= json_encode($daily_labels) ?>,
        datasets: [{
            label: 'Daily Revenue',
            data: <?= json_encode($daily_totals) ?>,
            borderColor: '#f472b6',
            backgroundColor: 'rgba(244,114,182,0.1)',
            fill: true,
            tension: 0.4,
            borderWidth: 2,
            pointRadius: 3,
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            x: { grid: { display: false }, ticks: { font: { size: 10 } } },
            y: { grid: { color: gridColor }, ticks: {
                font: { size: 10 },
                callback: v => 'Rp ' + (v/1000).toFixed(0) + 'k'
            }}
        }
    }
});
<?php endif; ?>

    } // end initCharts
})();
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
