<?php
// includes/sidebar.php
$current = basename($_SERVER['PHP_SELF']);
function isActive($file) {
    global $current;
    return $current === $file ? 'active' : '';
}

// SVG icon helper - returns inline SVG
function icon($name, $size=16) {
    $icons = [
        'dashboard'    => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
        'patients'     => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'doctors'      => '<path d="M3 9a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M8 9V7a4 4 0 0 1 8 0v2"/><line x1="12" y1="12" x2="12" y2="16"/><line x1="10" y1="14" x2="14" y2="14"/>',
        'appointments' => '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
        'examinations' => '<path d="M4.5 6H20"/><path d="M4.5 10H20"/><path d="M4.5 14H12"/><circle cx="17" cy="17" r="3"/><path d="m21 21-1.5-1.5"/>',
        'medications'  => '<path d="m10.5 20.5 10-10a4.95 4.95 0 1 0-7-7l-10 10a4.95 4.95 0 1 0 7 7Z"/><path d="m8.5 8.5 7 7"/>',
        'payments'     => '<rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/>',
        'users'        => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/>',
        'home'         => '<path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',
        'records'      => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/>',
        'bills'        => '<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>',
        'logout'       => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
        'reports'      => '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>',
        'schedule'     => '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><line x1="8" y1="14" x2="8" y2="14"/><line x1="12" y1="14" x2="12" y2="14"/><line x1="8" y1="18" x2="8" y2="18"/>',
        'profile'      => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/><line x1="16" y1="11" x2="22" y2="11"/><line x1="19" y1="8" x2="19" y2="14"/>',
        'sun'          => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/>',
        'moon'         => '<path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>',
        'stethoscope'  => '<circle cx="12" cy="12" r="9"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/>',
    ];
    $path = $icons[$name] ?? $icons['dashboard'];
    return '<svg width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0">'.$path.'</svg>';
}
?>
<aside class="sidebar">
    <div class="sidebar-brand">
        <div style="width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,#f9a8d4,#93c5fd);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <?= icon('stethoscope', 20) ?>
        </div>
        <div>
            <h1 style="font-size:15px;line-height:1.1;">IS Class 2</h1>
            <span style="font-size:9px;letter-spacing:1.5px;text-transform:uppercase;color:#93c5fd;">Healthcare</span>
        </div>
    </div>
    <nav class="sidebar-nav">
        <?php if (isAdmin()): ?>
        <div class="nav-label">Main Menu</div>
        <a href="dashboard.php"    class="nav-link <?= isActive('dashboard.php') ?>"><span class="nav-icon"><?= icon('dashboard') ?></span> Dashboard</a>
        <a href="patients.php"     class="nav-link <?= isActive('patients.php') ?>"><span class="nav-icon"><?= icon('patients') ?></span> Patients</a>
        <a href="doctors.php"      class="nav-link <?= isActive('doctors.php') ?>"><span class="nav-icon"><?= icon('doctors') ?></span> Doctors</a>
        <a href="appointments.php" class="nav-link <?= isActive('appointments.php') ?>"><span class="nav-icon"><?= icon('appointments') ?></span> Appointments</a>
        <a href="examinations.php" class="nav-link <?= isActive('examinations.php') ?>"><span class="nav-icon"><?= icon('examinations') ?></span> Examinations</a>
        <div class="nav-label">Management</div>
        <a href="medications.php"   class="nav-link <?= isActive('medications.php') ?>"><span class="nav-icon"><?= icon('medications') ?></span> Medications</a>
        <a href="prescriptions.php" class="nav-link <?= isActive('prescriptions.php') ?>"><span class="nav-icon"><?= icon('records') ?></span> Prescriptions</a>
        <a href="payments.php"      class="nav-link <?= isActive('payments.php') ?>"><span class="nav-icon"><?= icon('payments') ?></span> Payments</a>
        <a href="users.php"            class="nav-link <?= isActive('users.php') ?>"><span class="nav-icon"><?= icon('users') ?></span> Users</a>
        <a href="doctor_schedule.php"  class="nav-link <?= isActive('doctor_schedule.php') ?>"><span class="nav-icon"><?= icon('schedule') ?></span> Schedules</a>
        <a href="reports.php"          class="nav-link <?= isActive('reports.php') ?>"><span class="nav-icon"><?= icon('reports') ?></span> Reports</a>
        <a href="log_examination.php"  class="nav-link <?= isActive('log_examination.php') ?>"><span class="nav-icon"><?= icon('records') ?></span> Exam Log</a>
        <?php elseif (isDoctor()): ?>
        <div class="nav-label">Main Menu</div>
        <a href="dashboard.php"    class="nav-link <?= isActive('dashboard.php') ?>"><span class="nav-icon"><?= icon('dashboard') ?></span> Dashboard</a>
        <a href="appointments.php" class="nav-link <?= isActive('appointments.php') ?>"><span class="nav-icon"><?= icon('appointments') ?></span> My Schedule</a>
        <a href="examinations.php" class="nav-link <?= isActive('examinations.php') ?>"><span class="nav-icon"><?= icon('examinations') ?></span> Examinations</a>
        <div class="nav-label">Other</div>
        <a href="prescriptions.php" class="nav-link <?= isActive('prescriptions.php') ?>"><span class="nav-icon"><?= icon('records') ?></span> Prescriptions</a>
        <a href="medications.php"   class="nav-link <?= isActive('medications.php') ?>"><span class="nav-icon"><?= icon('medications') ?></span> Medications</a>
        <a href="doctor_schedule.php" class="nav-link <?= isActive('doctor_schedule.php') ?>"><span class="nav-icon"><?= icon('schedule') ?></span> My Schedule</a>
        <?php elseif (isPatient()): ?>
        <div class="nav-label">Main Menu</div>
        <a href="dashboard.php"   class="nav-link <?= isActive('dashboard.php') ?>"><span class="nav-icon"><?= icon('home') ?></span> Dashboard</a>
        <a href="booking.php"     class="nav-link <?= isActive('booking.php') ?>"><span class="nav-icon"><?= icon('appointments') ?></span> Book Appointment</a>
        <a href="my_records.php"  class="nav-link <?= isActive('my_records.php') ?>"><span class="nav-icon"><?= icon('records') ?></span> My Records</a>
        <a href="my_payments.php" class="nav-link <?= isActive('my_payments.php') ?>"><span class="nav-icon"><?= icon('bills') ?></span> My Bills</a>
        <?php endif; ?>
        <div class="nav-label">Account</div>
        <a href="profile.php" class="nav-link <?= isActive('profile.php') ?>"><span class="nav-icon"><?= icon('profile') ?></span> My Profile</a>
    </nav>
    <div class="sidebar-footer">
        <div class="user-info">
            <div class="user-name"><?= htmlspecialchars($_SESSION['username']) ?></div>
            <span class="user-role"><?= htmlspecialchars($_SESSION['role']) ?></span>
        </div>
        <div class="theme-toggle">
            <span class="theme-toggle-label" id="theme-label" style="display:flex;align-items:center;gap:5px;">
                <span id="theme-icon"><?= icon('moon', 13) ?></span> Dark
            </span>
            <div class="toggle-switch" id="theme-toggle" onclick="toggleTheme()">
                <div class="toggle-knob"></div>
            </div>
        </div>
        <a href="logout.php" class="logout-btn" style="display:flex;align-items:center;justify-content:center;gap:6px;">
            <?= icon('logout', 14) ?> Sign Out
        </a>
    </div>
</aside>
<script>
(function() {
    const sunIcon = `<?= icon('sun', 13) ?>`;
    const moonIcon = `<?= icon('moon', 13) ?>`;
    const saved = localStorage.getItem('clinic-theme') || 'dark';
    applyTheme(saved);
    function applyTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        const toggle = document.getElementById('theme-toggle');
        const label  = document.getElementById('theme-label');
        const iconEl = document.getElementById('theme-icon');
        if (!toggle) return;
        if (theme === 'light') {
            toggle.classList.add('on');
            if (iconEl) iconEl.innerHTML = sunIcon;
            if (label) label.childNodes[label.childNodes.length-1].textContent = ' Light';
        } else {
            toggle.classList.remove('on');
            if (iconEl) iconEl.innerHTML = moonIcon;
            if (label) label.childNodes[label.childNodes.length-1].textContent = ' Dark';
        }
    }
    window.toggleTheme = function() {
        const cur  = document.documentElement.getAttribute('data-theme') || 'light';
        const next = cur === 'light' ? 'dark' : 'light';
        localStorage.setItem('clinic-theme', next);
        applyTheme(next);
    };
})();
</script>
