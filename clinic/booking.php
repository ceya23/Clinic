<?php
require_once 'includes/config.php';
requireLogin();
if (!isPatient()) { header("Location: dashboard.php"); exit(); }

$patient_id = getPatientId($conn);
$error = '';
$success = '';
$step = (int)($_GET['step'] ?? 1);
$doctor_id = isset($_GET['doctor_id']) ? (int)$_GET['doctor_id'] : 0;

// ── SUBMIT BOOKING ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $doctor_id        = (int)($_POST['doctor_id'] ?? 0);
    $appointment_date = trim($_POST['appointment_date'] ?? '');
    $appointment_time = trim($_POST['appointment_time'] ?? '');
    $notes            = trim($_POST['notes'] ?? '');

    if (!$doctor_id || empty($appointment_date) || empty($appointment_time)) {
        $error = 'Please fill in all required fields.';
    } else {
        // FIX #9: Validate that selected date matches doctor's schedule day
        $day_name = date('l', strtotime($appointment_date)); // e.g. "Monday"
        $sched_stmt = mysqli_prepare($conn,
            "SELECT schedule_id, start_time, end_time, quota
             FROM doctor_schedule
             WHERE doctor_id = ? AND day_of_week = ?");
        mysqli_stmt_bind_param($sched_stmt, 'is', $doctor_id, $day_name);
        mysqli_stmt_execute($sched_stmt);
        $schedule = mysqli_fetch_assoc(mysqli_stmt_get_result($sched_stmt));

        if (!$schedule) {
            $error = "This doctor does not have a schedule on $day_name. Please choose a valid date.";
        } else {
            // FIX #9: Validate time is within schedule hours
            $chosen_time = $appointment_time . ':00';
            if ($chosen_time < $schedule['start_time'] || $chosen_time >= $schedule['end_time']) {
                $s = date('H:i', strtotime($schedule['start_time']));
                $e = date('H:i', strtotime($schedule['end_time']));
                $error = "Please choose a time between $s and $e for this doctor.";
            } else {
                // FIX #8: Check quota — count only Scheduled/Completed appointments
                $quota_stmt = mysqli_prepare($conn,
                    "SELECT COUNT(*) cnt FROM appointment
                     WHERE doctor_id = ? AND DATE(appointment_date) = ?
                       AND status IN ('Scheduled','Completed')");
                mysqli_stmt_bind_param($quota_stmt, 'is', $doctor_id, $appointment_date);
                mysqli_stmt_execute($quota_stmt);
                $booked = (int)mysqli_fetch_assoc(mysqli_stmt_get_result($quota_stmt))['cnt'];

                if ($booked >= (int)$schedule['quota']) {
                    $error = "Sorry, this doctor's quota for $day_name is full ($schedule[quota] patients). Please choose another date.";
                } else {
                    $datetime = $appointment_date . ' ' . $appointment_time . ':00';

                    // FIX #2: Remove manual queue calculation — let trigger auto_queue_number handle it.
                    // Also fixed trigger to skip Cancelled statuses (see SQL patch).
                    $stmt = mysqli_prepare($conn,
                        "INSERT INTO appointment (patient_id, doctor_id, appointment_date, status, notes)
                         VALUES (?, ?, ?, 'Scheduled', ?)");
                    mysqli_stmt_bind_param($stmt, 'iiss', $patient_id, $doctor_id, $datetime, $notes);
                    mysqli_stmt_execute($stmt);
                    $success = 'Appointment booked successfully!';
                    $step = 0;
                }
            }
        }
    }
}

// ── FETCH DOCTORS ─────────────────────────────────────────────────────────────
$doctors = mysqli_query($conn, "
    SELECT d.*, COUNT(DISTINCT ds.schedule_id) as has_schedule
    FROM doctor d
    LEFT JOIN doctor_schedule ds ON d.doctor_id = ds.doctor_id
    GROUP BY d.doctor_id
    ORDER BY d.specialization, d.name
");

// ── FETCH SELECTED DOCTOR ─────────────────────────────────────────────────────
$selected_doctor = null;
$schedules = [];
if ($doctor_id) {
    // FIX: use prepared statement instead of raw variable
    $doc_stmt = mysqli_prepare($conn, "SELECT * FROM doctor WHERE doctor_id = ?");
    mysqli_stmt_bind_param($doc_stmt, 'i', $doctor_id);
    mysqli_stmt_execute($doc_stmt);
    $selected_doctor = mysqli_fetch_assoc(mysqli_stmt_get_result($doc_stmt));

    $sched_rows = mysqli_prepare($conn,
        "SELECT * FROM doctor_schedule WHERE doctor_id = ?
         ORDER BY FIELD(day_of_week,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday')");
    mysqli_stmt_bind_param($sched_rows, 'i', $doctor_id);
    mysqli_stmt_execute($sched_rows);
    $schedules = mysqli_fetch_all(mysqli_stmt_get_result($sched_rows), MYSQLI_ASSOC);
}

$specs = [];
$doc_rows = mysqli_fetch_all($doctors, MYSQLI_ASSOC);
foreach ($doc_rows as $d) {
    $specs[$d['specialization']][] = $d;
}

$spec_icons = [
    'General Practitioner' => '🩺',
    'Dentist'              => '🦷',
    'Neurologist'          => '🧠',
    'Pediatrician'         => '👶',
    'Dermatologist'        => '🧴',
    'Cardiologist'         => '❤️',
    'Orthopedist'          => '🦴',
    'Ophthalmologist'      => '👁️',
    'ENT Specialist'       => '👂',
    'Psychiatrist'         => '🧘',
];
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Book Appointment — IS Class 2 Healthcare</title>
<link rel="stylesheet" href="includes/theme.css">
<style>
.steps-bar {
    display: flex; align-items: center; gap: 0;
    margin-bottom: 28px;
    background: var(--surface);
    border: 1.5px solid var(--border);
    border-radius: var(--radius-md);
    padding: 16px 24px;
}
.step-item { display: flex; align-items: center; gap: 8px; flex: 1; }
.step-num {
    width: 28px; height: 28px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 11px; font-weight: 700;
    background: var(--surface2); color: var(--text-muted);
    border: 1.5px solid var(--border); flex-shrink: 0;
    transition: all .2s;
}
.step-num.active { background: var(--accent); color: #4a2040; border-color: var(--accent); }
.step-num.done   { background: var(--success); color: #fff; border-color: var(--success); }
.step-text { font-size: 12px; font-weight: 600; color: var(--text-muted); }
.step-text.active { color: var(--accent); }
.step-line { flex: 1; height: 1.5px; background: var(--border); margin: 0 8px; }

.doctor-grid { display: grid; grid-template-columns: repeat(3,1fr); gap: 12px; margin-bottom: 20px; }
.doctor-card {
    background: var(--surface);
    border: 1.5px solid var(--border);
    border-radius: var(--radius-md);
    padding: 18px;
    cursor: pointer;
    transition: all .2s;
    text-decoration: none;
    display: block;
}
.doctor-card:hover { border-color: var(--accent); transform: translateY(-2px); box-shadow: 0 6px 20px rgba(232,121,160,0.15); }
.doctor-card.selected { border-color: var(--accent); background: var(--accent-bg); }
.doc-avatar-lg {
    width: 52px; height: 52px; border-radius: 50%;
    background: linear-gradient(135deg, var(--accent-bg), var(--surface3));
    border: 2px solid var(--accent);
    display: flex; align-items: center; justify-content: center;
    font-size: 22px; margin-bottom: 12px;
}
.doc-card-name { font-size: 14px; font-weight: 700; color: var(--text); margin-bottom: 4px; }
.doc-card-spec {
    font-size: 11px; padding: 3px 9px; border-radius: 20px;
    background: var(--accent-bg); color: var(--accent-text);
    display: inline-block; font-weight: 600;
}
.doc-card-schedule { font-size: 11px; color: var(--text-muted); margin-top: 8px; }

.spec-section { margin-bottom: 24px; }
.spec-label {
    font-size: 11px; font-weight: 700; text-transform: uppercase;
    letter-spacing: .07em; color: var(--text-muted);
    margin-bottom: 10px; display: flex; align-items: center; gap: 8px;
}
.spec-label::after { content: ''; flex: 1; height: 1px; background: var(--border); }

.schedule-grid { display: grid; grid-template-columns: repeat(4,1fr); gap: 8px; margin-bottom: 16px; }
.sched-card {
    background: var(--surface2); border: 1.5px solid var(--border);
    border-radius: var(--radius-sm); padding: 10px 12px; text-align: center;
}
.sched-day { font-size: 12px; font-weight: 700; color: var(--accent); }
.sched-time { font-size: 11px; color: var(--text-muted); margin-top: 2px; }
.sched-quota { font-size: 10px; color: var(--text-faint); margin-top: 2px; }

.booking-form-panel {
    background: var(--surface); border: 1.5px solid var(--border);
    border-radius: var(--radius-md); overflow: hidden;
}
.selected-doctor-banner {
    background: linear-gradient(135deg, var(--accent-bg), var(--surface3));
    border-bottom: 1.5px solid var(--border);
    padding: 16px 24px;
    display: flex; align-items: center; gap: 14px;
}
.success-box {
    background: var(--surface); border: 1.5px solid var(--success);
    border-radius: var(--radius-md); padding: 32px;
    text-align: center;
}
.success-icon { font-size: 52px; margin-bottom: 16px; }
.success-title { font-family: 'Playfair Display', serif; font-size: 22px; color: var(--text); margin-bottom: 8px; }
.success-sub { font-size: 13px; color: var(--text-muted); margin-bottom: 24px; }

/* Highlight valid schedule days in date hint */
.schedule-hint {
    font-size: 11px; color: var(--text-muted);
    background: var(--surface2); border: 1px solid var(--border);
    border-radius: var(--radius-sm); padding: 8px 12px; margin-bottom: 8px;
}

@media(max-width:900px) { .doctor-grid { grid-template-columns: 1fr 1fr; } .schedule-grid { grid-template-columns: 1fr 1fr; } }
@media(max-width:600px) { .doctor-grid { grid-template-columns: 1fr; } }

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
            <h2>📅 Book an Appointment</h2>
            <p class="page-sub">Choose a doctor and schedule your visit</p>
        </div>

        <!-- Steps bar -->
        <?php if ($step > 0): ?>
        <div class="steps-bar">
            <div class="step-item">
                <div class="step-num <?= $step >= 1 ? ($step > 1 ? 'done' : 'active') : '' ?>">
                    <?= $step > 1 ? '✓' : '1' ?>
                </div>
                <span class="step-text <?= $step === 1 ? 'active' : '' ?>">Choose Doctor</span>
            </div>
            <div class="step-line"></div>
            <div class="step-item">
                <div class="step-num <?= $step >= 2 ? 'active' : '' ?>">2</div>
                <span class="step-text <?= $step === 2 ? 'active' : '' ?>">Pick Date & Time</span>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php /* ── SUCCESS ── */ if ($step === 0): ?>
        <div class="success-box">
            <div class="success-icon">🎉</div>
            <div class="success-title">Appointment Booked!</div>
            <p class="success-sub">Your appointment has been successfully scheduled. Please arrive 10 minutes early.</p>
            <div style="display:flex;gap:10px;justify-content:center;">
                <a href="booking.php" class="btn btn-primary">📅 Book Another</a>
                <a href="dashboard.php" class="btn btn-outline">🏠 Dashboard</a>
            </div>
        </div>

        <?php /* ── STEP 1: CHOOSE DOCTOR ── */ elseif ($step === 1): ?>
        <?php foreach ($specs as $spec => $docs): ?>
        <div class="spec-section">
            <div class="spec-label"><?= $spec_icons[$spec] ?? '🩺' ?> <?= htmlspecialchars($spec) ?></div>
            <div class="doctor-grid">
                <?php foreach ($docs as $doc): ?>
                <a href="booking.php?step=2&doctor_id=<?= $doc['doctor_id'] ?>"
                   class="doctor-card <?= $doctor_id == $doc['doctor_id'] ? 'selected' : '' ?>">
                    <div class="doc-avatar-lg"><?= $spec_icons[$doc['specialization']] ?? '👨‍⚕️' ?></div>
                    <div class="doc-card-name"><?= htmlspecialchars($doc['name']) ?></div>
                    <span class="doc-card-spec"><?= htmlspecialchars($doc['specialization']) ?></span>
                    <div class="doc-card-schedule">
                        <?= $doc['has_schedule'] ? '📅 Schedule available' : '⚠️ No schedule yet' ?>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>

        <?php /* ── STEP 2: PICK DATE ── */ elseif ($step === 2 && $selected_doctor): ?>
        <div class="booking-form-panel">
            <div class="selected-doctor-banner">
                <div style="font-size:32px;"><?= $spec_icons[$selected_doctor['specialization']] ?? '👨‍⚕️' ?></div>
                <div>
                    <div style="font-size:15px;font-weight:700;color:var(--text);"><?= htmlspecialchars($selected_doctor['name']) ?></div>
                    <span class="doc-card-spec"><?= htmlspecialchars($selected_doctor['specialization']) ?></span>
                    <?php if ($selected_doctor['phone']): ?>
                    <div style="font-size:11px;color:var(--text-muted);margin-top:4px;">📞 <?= htmlspecialchars($selected_doctor['phone']) ?></div>
                    <?php endif; ?>
                </div>
                <a href="booking.php?step=1" class="btn btn-outline btn-sm" style="margin-left:auto;">← Change Doctor</a>
            </div>

            <div style="padding:24px;">
                <!-- Schedule info -->
                <?php if (count($schedules) > 0): ?>
                <div style="margin-bottom:20px;">
                    <div style="font-size:12px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:10px;">📅 Practice Schedule</div>
                    <div class="schedule-grid">
                        <?php foreach ($schedules as $s): ?>
                        <div class="sched-card">
                            <div class="sched-day"><?= $s['day_of_week'] ?></div>
                            <div class="sched-time"><?= date('H:i', strtotime($s['start_time'])) ?> - <?= date('H:i', strtotime($s['end_time'])) ?></div>
                            <div class="sched-quota">Max <?= $s['quota'] ?> patients</div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Booking form -->
                <form method="POST">
                    <input type="hidden" name="doctor_id" value="<?= (int)$selected_doctor['doctor_id'] ?>">
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                        <div class="form-group">
                            <label class="form-label">Appointment Date <span style="color:var(--danger)">*</span></label>
                            <?php
                            // FIX #9: Build hint of valid days for UI guidance
                            $valid_days = array_column($schedules, 'day_of_week');
                            if ($valid_days): ?>
                            <div class="schedule-hint">⚠️ Only available on: <?= implode(', ', $valid_days) ?></div>
                            <?php endif; ?>
                            <input type="date" name="appointment_date" class="form-control"
                                min="<?= date('Y-m-d', strtotime('+1 day')) ?>"
                                value="<?= htmlspecialchars($_POST['appointment_date'] ?? '') ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Preferred Time <span style="color:var(--danger)">*</span></label>
                            <?php if (count($schedules) > 0): ?>
                            <div class="schedule-hint">
                                ⏰ Hours vary by day — check schedule above
                            </div>
                            <?php endif; ?>
                            <input type="time" name="appointment_time" class="form-control"
                                value="<?= htmlspecialchars($_POST['appointment_time'] ?? '08:00') ?>" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Reason / Notes</label>
                        <textarea name="notes" class="form-control" rows="3"
                            placeholder="Describe your symptoms or reason for visit..."><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
                    </div>
                    <div style="display:flex;gap:10px;">
                        <button type="submit" class="btn btn-primary">✅ Confirm Booking</button>
                        <a href="booking.php?step=1" class="btn btn-outline">← Back</a>
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
