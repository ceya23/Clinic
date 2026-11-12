<?php
require_once 'includes/config.php';
requireRole('admin');

$error   = '';
$success = '';
$action  = $_GET['action'] ?? 'list'; // list | add | edit
$id      = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// ── DELETE ────────────────────────────────────────────────────────────────────
if ($action === 'delete' && $id) {
    $stmt = mysqli_prepare($conn, "DELETE FROM medication WHERE medication_id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    header("Location: medications.php?success=deleted"); exit();
}

// ── ADD / EDIT SAVE ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name  = trim($_POST['name']        ?? '');
    $type  = trim($_POST['type']        ?? '');
    $stock = (int)($_POST['stock']      ?? 0);
    $price = (float)($_POST['price']    ?? 0);
    $desc  = trim($_POST['description'] ?? '');

    if (empty($name)) {
        $error = 'Medication name is required.';
    } elseif ($stock < 0) {
        $error = 'Stock cannot be negative.';
    } elseif ($price < 0) {
        $error = 'Price cannot be negative.';
    } else {
        if ($action === 'add') {
            $stmt = mysqli_prepare($conn, "INSERT INTO medication (name, type, stock, price, description) VALUES (?, ?, ?, ?, ?)");
            mysqli_stmt_bind_param($stmt, 'ssids', $name, $type, $stock, $price, $desc);
            mysqli_stmt_execute($stmt);
            header("Location: medications.php?success=added"); exit();
        } elseif ($action === 'edit' && $id) {
            $stmt = mysqli_prepare($conn, "UPDATE medication SET name=?, type=?, stock=?, price=?, description=? WHERE medication_id=?");
            mysqli_stmt_bind_param($stmt, 'ssidsi', $name, $type, $stock, $price, $desc, $id);
            mysqli_stmt_execute($stmt);
            header("Location: medications.php?success=updated"); exit();
        }
    }
}

// ── FETCH SINGLE (edit) ───────────────────────────────────────────────────────
$medication = null;
if ($action === 'edit' && $id) {
    $stmt = mysqli_prepare($conn, "SELECT * FROM medication WHERE medication_id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $medication = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    if (!$medication) { header("Location: medications.php"); exit(); }
}

// ── LIST + SEARCH ─────────────────────────────────────────────────────────────
$search      = trim($_GET['q']    ?? '');
$filter_type = trim($_GET['type'] ?? '');
$page        = max(1, (int)($_GET['page'] ?? 1));
$limit       = 10;
$offset      = ($page - 1) * $limit;

$where = [];
$params = [];
$types  = '';

if ($search !== '') {
    $like = "%$search%";
    $where[]  = "(name LIKE ? OR description LIKE ?)";
    $params[] = $like; $params[] = $like;
    $types   .= 'ss';
}
if ($filter_type !== '') {
    $where[]  = "type = ?";
    $params[] = $filter_type;
    $types   .= 's';
}
$where_sql = $where ? "WHERE " . implode(" AND ", $where) : "";

// Count
$count_stmt = mysqli_prepare($conn, "SELECT COUNT(*) FROM medication $where_sql");
if ($params) mysqli_stmt_bind_param($count_stmt, $types, ...$params);
mysqli_stmt_execute($count_stmt);
$total = mysqli_fetch_row(mysqli_stmt_get_result($count_stmt))[0];
$total_pages = max(1, ceil($total / $limit));

// List
$list_params  = array_merge($params, [$limit, $offset]);
$list_types   = $types . 'ii';
$list_stmt = mysqli_prepare($conn, "SELECT * FROM medication $where_sql ORDER BY name ASC LIMIT ? OFFSET ?");
mysqli_stmt_bind_param($list_stmt, $list_types, ...$list_params);
mysqli_stmt_execute($list_stmt);
$medications = mysqli_stmt_get_result($list_stmt);

// Stats
$total_meds  = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM medication"))['c'];
$low_stock   = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM medication WHERE stock < 40"))['c'];
$out_of_stock = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM medication WHERE stock = 0"))['c'];
$total_value = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(stock * price),0) c FROM medication"))['c'];

// Distinct types for filter dropdown
$types_result = mysqli_query($conn, "SELECT DISTINCT type FROM medication WHERE type != '' ORDER BY type");

// Flash messages
if (isset($_GET['success'])) {
    $msgs = ['added' => 'Medication added successfully.', 'updated' => 'Medication updated.', 'deleted' => 'Medication deleted.'];
    $success = $msgs[$_GET['success']] ?? '';
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Medications — IS Class 2 Healthcare</title>
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
.filter-bar input:focus, .filter-bar select:focus { border-color:var(--accent); }
.stock-badge {
    display:inline-block; font-size:10px; font-weight:700;
    padding:3px 10px; border-radius:20px;
}
.stock-ok      { background:rgba(52,211,153,0.12); color:#34d399; border:1px solid rgba(52,211,153,0.3); }
.stock-low     { background:rgba(251,191,36,0.12); color:#fbbf24; border:1px solid rgba(251,191,36,0.3); }
.stock-empty   { background:rgba(251,113,133,0.12); color:#fb7185; border:1px solid rgba(251,113,133,0.3); }
.type-chip {
    display:inline-block; font-size:10px; font-weight:600;
    padding:2px 9px; border-radius:20px;
    background:var(--surface3); color:var(--accent2);
    border:1px solid rgba(125,211,252,0.25);
}
.pagination { display:flex; gap:6px; align-items:center; justify-content:center; margin-top:20px; }
.pagination a, .pagination span {
    padding:6px 12px; border-radius:var(--radius-sm); font-size:12px; font-weight:700;
    border:1.5px solid var(--border); text-decoration:none; color:var(--text-muted);
}
.pagination a:hover { border-color:var(--accent); color:var(--accent); }
.pagination .current { background:linear-gradient(135deg,#f9a8d4,#93c5fd); color:#4a2040; border-color:transparent; }
.btn-ghost { background:transparent; border:none; color:var(--text-muted); padding:9px 14px; }
.btn-ghost:hover { color:var(--text); background:var(--surface2); border-radius:var(--radius-sm); }

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

    <?php if ($action === 'list'): ?>

        <!-- ═══════════ LIST VIEW ═══════════ -->
        <div class="page-header">
            <div>
                <h2><?= icon('medications', 22) ?> Medications</h2>
                <p class="page-sub">Manage medication inventory and stock levels</p>
            </div>
            <a href="medications.php?action=add" class="btn btn-primary">+ Add Medication</a>
        </div>

        <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

        <!-- Stats -->
        <div class="stats-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:20px;">
            <div class="stat-card">
                <div class="stat-label"><?= icon('medications', 13) ?> Total Items</div>
                <div class="stat-val"><?= $total_meds ?></div>
                <div class="stat-sub">Medications listed</div>
            </div>
            <div class="stat-card <?= $low_stock > 0 ? 'gold' : '' ?>">
                <div class="stat-label">Low Stock</div>
                <div class="stat-val"><?= $low_stock ?></div>
                <div class="stat-sub">Below 40 units</div>
            </div>
            <div class="stat-card <?= $out_of_stock > 0 ? 'gold' : '' ?>">
                <div class="stat-label">Out of Stock</div>
                <div class="stat-val"><?= $out_of_stock ?></div>
                <div class="stat-sub">0 units remaining</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Inventory Value</div>
                <div class="stat-val" style="font-size:20px;">Rp <?= number_format($total_value, 0, ',', '.') ?></div>
                <div class="stat-sub">Stock × price</div>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="filter-bar">
            <form method="GET" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;width:100%;">
                <div class="form-group">
                    <label>Search</label>
                    <input type="text" name="q" placeholder="Name or description..." value="<?= htmlspecialchars($search) ?>" style="width:220px;">
                </div>
                <div class="form-group">
                    <label>Type</label>
                    <select name="type">
                        <option value="">All Types</option>
                        <?php while ($t = mysqli_fetch_assoc($types_result)): ?>
                        <option value="<?= htmlspecialchars($t['type']) ?>" <?= $filter_type === $t['type'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($t['type']) ?>
                        </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-outline" style="padding:8px 16px;font-size:12px;">Filter</button>
                <?php if ($search || $filter_type): ?>
                    <a href="medications.php" class="btn btn-ghost" style="padding:8px 14px;font-size:12px;">✕ Clear</a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Table -->
        <div class="panel">
            <div class="panel-header">
                <div class="panel-title"><?= icon('medications', 14) ?> Medication List</div>
                <span style="font-size:12px;color:var(--text-muted);"><?= $total ?> item<?= $total != 1 ? 's' : '' ?></span>
            </div>
            <?php if (mysqli_num_rows($medications) > 0): ?>
            <table class="data-table">
                <thead><tr>
                    <th>#</th>
                    <th>Name</th>
                    <th>Type</th>
                    <th>Stock</th>
                    <th>Price / Unit</th>
                    <th>Description</th>
                    <th>Actions</th>
                </tr></thead>
                <tbody>
                <?php $no = $offset + 1; while ($row = mysqli_fetch_assoc($medications)): ?>
                <tr>
                    <td style="color:var(--text-muted);font-size:12px;"><?= $no++ ?></td>
                    <td><strong><?= htmlspecialchars($row['name']) ?></strong></td>
                    <td><?= $row['type'] ? '<span class="type-chip">'.htmlspecialchars($row['type']).'</span>' : '<span style="color:var(--text-faint);font-size:12px;">—</span>' ?></td>
                    <td>
                        <?php
                        $s = (int)$row['stock'];
                        if ($s === 0)      echo "<span class=\"stock-badge stock-empty\">Out of stock</span>";
                        elseif ($s < 40)   echo "<span class=\"stock-badge stock-low\">$s units</span>";
                        else               echo "<span class=\"stock-badge stock-ok\">$s units</span>";
                        ?>
                    </td>
                    <td>Rp <?= number_format($row['price'], 0, ',', '.') ?></td>
                    <td style="color:var(--text-muted);font-size:12px;max-width:200px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                        <?= $row['description'] ? htmlspecialchars($row['description']) : '—' ?>
                    </td>
                    <td>
                        <div style="display:flex;gap:6px;">
                            <a href="medications.php?action=edit&id=<?= $row['medication_id'] ?>" class="btn btn-outline btn-sm">Edit</a>
                            <a href="medications.php?action=delete&id=<?= $row['medication_id'] ?>"
                               class="btn btn-danger btn-sm"
                               onclick="return confirm('Delete <?= htmlspecialchars(addslashes($row['name'])) ?>? This cannot be undone.')">Delete</a>
                        </div>
                    </td>
                </tr>
                <?php endwhile; ?>
                </tbody>
            </table>

            <?php if ($total_pages > 1): ?>
            <div class="pagination" style="padding:16px;">
                <?php if ($page > 1): ?>
                    <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page-1])) ?>">← Prev</a>
                <?php endif; ?>
                <?php for ($p = max(1,$page-2); $p <= min($total_pages,$page+2); $p++): ?>
                    <?php if ($p == $page): ?>
                        <span class="current"><?= $p ?></span>
                    <?php else: ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $p])) ?>"><?= $p ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                <?php if ($page < $total_pages): ?>
                    <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page+1])) ?>">Next →</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php else: ?>
                <div class="empty-state">
                    <?= $search || $filter_type ? 'No medications found matching your filters.' : 'No medications added yet.' ?>
                </div>
            <?php endif; ?>
        </div>

    <?php elseif ($action === 'add' || $action === 'edit'): ?>

        <!-- ═══════════ ADD / EDIT FORM ═══════════ -->
        <div class="page-header">
            <div>
                <h2><?= icon('medications', 20) ?> <?= $action === 'add' ? 'Add Medication' : 'Edit Medication' ?></h2>
                <p class="page-sub"><?= $action === 'add' ? 'Add a new medication to inventory' : 'Update medication details' ?></p>
            </div>
            <a href="medications.php" class="btn btn-ghost">← Back</a>
        </div>

        <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

        <div class="panel" style="max-width:580px;">
            <div class="panel-header">
                <div class="panel-title"><?= $action === 'add' ? 'New Medication' : 'Edit: '.htmlspecialchars($medication['name']) ?></div>
            </div>
            <div style="padding:28px;">
            <form method="POST">
                <div class="form-group">
                    <label class="form-label">Medication Name <span style="color:var(--danger)">*</span></label>
                    <input type="text" name="name" class="form-control"
                        placeholder="e.g. Paracetamol 500mg"
                        value="<?= htmlspecialchars($medication['name'] ?? $_POST['name'] ?? '') ?>" required>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                    <div class="form-group">
                        <label class="form-label">Type / Category</label>
                        <input type="text" name="type" class="form-control"
                            placeholder="e.g. Tablet, Syrup, Capsule"
                            value="<?= htmlspecialchars($medication['type'] ?? $_POST['type'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Stock (units) <span style="color:var(--danger)">*</span></label>
                        <input type="number" name="stock" class="form-control" min="0"
                            placeholder="0"
                            value="<?= htmlspecialchars($medication['stock'] ?? $_POST['stock'] ?? '0') ?>" required>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Price per Unit (Rp) <span style="color:var(--danger)">*</span></label>
                    <input type="number" name="price" class="form-control" min="0" step="100"
                        placeholder="0"
                        value="<?= htmlspecialchars($medication['price'] ?? $_POST['price'] ?? '0') ?>" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Description / Notes</label>
                    <textarea name="description" class="form-control" rows="3"
                        placeholder="Dosage instructions, warnings, etc."
                        style="resize:vertical;"><?= htmlspecialchars($medication['description'] ?? $_POST['description'] ?? '') ?></textarea>
                </div>

                <div style="display:flex;gap:10px;margin-top:8px;">
                    <button type="submit" class="btn btn-primary" style="flex:1;justify-content:center;padding:12px;">
                        <?= $action === 'add' ? 'Add Medication' : 'Save Changes' ?> →
                    </button>
                    <a href="medications.php" class="btn btn-outline" style="padding:12px 20px;">Cancel</a>
                </div>
            </form>
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
