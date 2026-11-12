<?php
require_once 'includes/config.php';
requireRole('admin');

// ── FILTERS & PAGINATION ──────────────────────────────────────────────────────
$search      = trim($_GET['q']    ?? '');
$filter_date = trim($_GET['date'] ?? '');
$page        = max(1, (int)($_GET['page'] ?? 1));
$limit       = 15;
$offset      = ($page - 1) * $limit;

$where  = [];
$params = [];
$types  = '';

if ($search !== '') {
    $like       = "%$search%";
    $where[]    = "(p.name LIKE ? OR d.name LIKE ? OR e.diagnosis LIKE ?)";
    $params[]   = $like; $params[] = $like; $params[] = $like;
    $types     .= 'sss';
}
if ($filter_date !== '') {
    $where[]  = "DATE(l.action_time) = ?";
    $params[] = $filter_date;
    $types   .= 's';
}
$where_sql = $where ? "WHERE " . implode(" AND ", $where) : "";

// Count
$count_sql  = "SELECT COUNT(*)
               FROM log_examination l
               JOIN examination_history e ON l.exam_id    = e.exam_id
               JOIN patient             p ON e.patient_id = p.patient_id
               JOIN doctor              d ON e.doctor_id  = d.doctor_id
               $where_sql";
$count_stmt = mysqli_prepare($conn, $count_sql);
if ($params) mysqli_stmt_bind_param($count_stmt, $types, ...$params);
mysqli_stmt_execute($count_stmt);
$total       = mysqli_fetch_row(mysqli_stmt_get_result($count_stmt))[0];
$total_pages = max(1, ceil($total / $limit));

// Main list
$list_params = array_merge($params, [$limit, $offset]);
$list_types  = $types . 'ii';
$list_sql    = "SELECT
                    l.log_id,
                    l.exam_id,
                    l.action_time,
                    p.name        AS patient_name,
                    d.name        AS doctor_name,
                    d.specialization,
                    e.exam_date,
                    e.diagnosis
                FROM log_examination l
                JOIN examination_history e ON l.exam_id    = e.exam_id
                JOIN patient             p ON e.patient_id = p.patient_id
                JOIN doctor              d ON e.doctor_id  = d.doctor_id
                $where_sql
                ORDER BY l.action_time DESC
                LIMIT ? OFFSET ?";
$list_stmt = mysqli_prepare($conn, $list_sql);
mysqli_stmt_bind_param($list_stmt, $list_types, ...$list_params);
mysqli_stmt_execute($list_stmt);
$logs = mysqli_stmt_get_result($list_stmt);

// Stats
$total_logs   = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM log_examination"))['c'];
$today_logs   = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM log_examination WHERE DATE(action_time) = CURDATE()"))['c'];
$this_week    = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM log_examination WHERE action_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)"))['c'];
$latest_time  = mysqli_fetch_assoc(mysqli_query($conn, "SELECT MAX(action_time) t FROM log_examination"))['t'];
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Examination Log — IS Class 2 Healthcare</title>
<link rel="stylesheet" href="includes/theme.css">
<style>
.page-header { display:flex; align-items:flex-start; justify-content:space-between; margin-bottom:24px; }
.filter-bar {
    display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap;
    background:var(--surface); border:1.5px solid var(--border);
    border-radius:var(--radius-md); padding:16px 20px; margin-bottom:20px;
}
.filter-bar .form-group { margin-bottom:0; }
.filter-bar label { font-size:10px; text-transform:uppercase; letter-spacing:.06em; color:var(--text-muted); font-weight:700; display:block; margin-bottom:5px; }
.filter-bar input, .filter-bar select {
    padding:8px 12px; font-size:13px; font-family:'Nunito',sans-serif;
    border:1.5px solid var(--border); border-radius:var(--radius-sm);
    background:var(--surface2); color:var(--text); outline:none;
}
.filter-bar input:focus { border-color:var(--accent); }
.pagination { display:flex; gap:6px; align-items:center; justify-content:center; margin-top:20px; }
.pagination a, .pagination span {
    padding:6px 12px; border-radius:var(--radius-sm); font-size:12px; font-weight:700;
    border:1.5px solid var(--border); text-decoration:none; color:var(--text-muted);
}
.pagination a:hover { border-color:var(--accent); color:var(--accent); }
.pagination .current { background:linear-gradient(135deg,#f9a8d4,#93c5fd); color:#4a2040; border-color:transparent; }
.btn-ghost { background:transparent; border:none; color:var(--text-muted); padding:9px 14px; }
.btn-ghost:hover { color:var(--text); background:var(--surface2); border-radius:var(--radius-sm); }
.log-id-badge {
    display:inline-block; font-size:10px; font-weight:700; font-family:'Courier New',monospace;
    padding:3px 8px; border-radius:6px;
    background:rgba(147,197,253,0.1); color:#93c5fd;
    border:1px solid rgba(147,197,253,0.25);
}
.spec-chip {
    display:inline-block; font-size:10px; font-weight:600;
    padding:2px 9px; border-radius:20px;
    background:var(--surface3); color:var(--accent2);
    border:1px solid rgba(125,211,252,0.25);
}
.diag-text { font-size:12px; color:var(--text-muted); }
.time-cell { font-size:12px; color:var(--text-muted); white-space:nowrap; }
.empty-state { text-align:center; padding:60px 20px; color:var(--text-muted); }
.empty-state svg { opacity:.3; margin-bottom:12px; }

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
            <div>
                <h2><?= icon('records', 22) ?> Examination Log</h2>
                <p class="page-sub">Audit trail of all recorded medical examinations</p>
            </div>
        </div>

        <!-- Stats -->
        <div class="stats-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:20px;">
            <div class="stat-card">
                <div class="stat-label"><?= icon('records', 13) ?> Total Logs</div>
                <div class="stat-val"><?= number_format($total_logs) ?></div>
                <div class="stat-sub">All-time entries</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Today</div>
                <div class="stat-val"><?= $today_logs ?></div>
                <div class="stat-sub">Logged today</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Last 7 Days</div>
                <div class="stat-val"><?= $this_week ?></div>
                <div class="stat-sub">Recent activity</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Latest Entry</div>
                <div class="stat-val" style="font-size:14px;line-height:1.3;">
                    <?= $latest_time ? date('d M Y', strtotime($latest_time)) : '—' ?>
                </div>
                <div class="stat-sub"><?= $latest_time ? date('H:i:s', strtotime($latest_time)) : 'No entries yet' ?></div>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="filter-bar">
            <form method="GET" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;width:100%;">
                <div class="form-group">
                    <label>Search</label>
                    <input type="text" name="q" placeholder="Patient, doctor, or diagnosis..." value="<?= htmlspecialchars($search) ?>" style="width:260px;">
                </div>
                <div class="form-group">
                    <label>Date</label>
                    <input type="date" name="date" value="<?= htmlspecialchars($filter_date) ?>">
                </div>
                <button type="submit" class="btn btn-outline" style="padding:8px 16px;font-size:12px;">Filter</button>
                <?php if ($search || $filter_date): ?>
                    <a href="log_examination.php" class="btn btn-ghost" style="padding:8px 14px;font-size:12px;">✕ Clear</a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Table -->
        <div class="panel">
            <div class="panel-header">
                <div class="panel-title"><?= icon('records', 14) ?> Log Entries</div>
                <span style="font-size:12px;color:var(--text-muted);"><?= number_format($total) ?> entr<?= $total != 1 ? 'ies' : 'y' ?></span>
            </div>

            <?php if (mysqli_num_rows($logs) > 0): ?>
            <table class="data-table">
                <thead><tr>
                    <th>Log ID</th>
                    <th>Exam ID</th>
                    <th>Patient</th>
                    <th>Doctor</th>
                    <th>Specialization</th>
                    <th>Exam Date</th>
                    <th>Diagnosis</th>
                    <th>Logged At</th>
                </tr></thead>
                <tbody>
                <?php while ($row = mysqli_fetch_assoc($logs)): ?>
                <tr>
                    <td><span class="log-id-badge">#<?= $row['log_id'] ?></span></td>
                    <td>
                        <a href="examinations.php?action=view&id=<?= $row['exam_id'] ?>"
                           style="color:var(--accent);text-decoration:none;font-weight:600;font-size:13px;">
                            EX-<?= str_pad($row['exam_id'], 4, '0', STR_PAD_LEFT) ?>
                        </a>
                    </td>
                    <td><strong><?= htmlspecialchars($row['patient_name']) ?></strong></td>
                    <td><?= htmlspecialchars($row['doctor_name']) ?></td>
                    <td>
                        <?php if ($row['specialization']): ?>
                            <span class="spec-chip"><?= htmlspecialchars($row['specialization']) ?></span>
                        <?php else: ?>
                            <span style="color:var(--text-faint);font-size:12px;">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="time-cell"><?= $row['exam_date'] ? date('d M Y', strtotime($row['exam_date'])) : '—' ?></td>
                    <td>
                        <?php if ($row['diagnosis']): ?>
                            <span class="diag-text"><?= htmlspecialchars($row['diagnosis']) ?></span>
                        <?php else: ?>
                            <span style="color:var(--text-faint);font-size:12px;">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="time-cell">
                        <?= date('d M Y', strtotime($row['action_time'])) ?><br>
                        <span style="font-size:11px;opacity:.7;"><?= date('H:i:s', strtotime($row['action_time'])) ?></span>
                    </td>
                </tr>
                <?php endwhile; ?>
                </tbody>
            </table>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <div class="pagination">
                <?php
                $qs = http_build_query(array_filter(['q' => $search, 'date' => $filter_date]));
                $base = "log_examination.php?" . ($qs ? $qs . "&" : "");
                ?>
                <?php if ($page > 1): ?>
                    <a href="<?= $base ?>page=<?= $page - 1 ?>">‹ Prev</a>
                <?php endif; ?>

                <?php for ($i = max(1, $page-2); $i <= min($total_pages, $page+2); $i++): ?>
                    <?php if ($i === $page): ?>
                        <span class="current"><?= $i ?></span>
                    <?php else: ?>
                        <a href="<?= $base ?>page=<?= $i ?>"><?= $i ?></a>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($page < $total_pages): ?>
                    <a href="<?= $base ?>page=<?= $page + 1 ?>">Next ›</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php else: ?>
            <div class="empty-state">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                    <polyline points="14 2 14 8 20 8"/>
                    <line x1="16" y1="13" x2="8" y2="13"/>
                    <line x1="16" y1="17" x2="8" y2="17"/>
                </svg>
                <p style="font-weight:700;margin-bottom:6px;">No log entries found</p>
                <p style="font-size:13px;">
                    <?= ($search || $filter_date) ? 'Try adjusting your search or filter.' : 'Logs will appear here automatically when examinations are recorded.' ?>
                </p>
            </div>
            <?php endif; ?>
        </div>

    </main>
</div>
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
