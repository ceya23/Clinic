<?php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');       // change to your phpMyAdmin username
define('DB_PASS', '');           // change to your phpMyAdmin password
define('DB_NAME', 'clinic_pr');

$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (!$conn) die("Connection failed: " . mysqli_connect_error());
mysqli_set_charset($conn, "utf8mb4");

if (session_status() === PHP_SESSION_NONE) session_start();

function requireLogin() {
    if (!isset($_SESSION['user_id'])) {
        header("Location: /clinic/login.php");
        exit();
    }
}

function isAdmin()   { return ($_SESSION['role'] ?? '') === 'admin'; }
function isDoctor()  { return ($_SESSION['role'] ?? '') === 'doctor'; }
function isPatient() { return ($_SESSION['role'] ?? '') === 'patient'; }

// FIX: Use separate session keys for patient_ref_id vs doctor_ref_id
// to prevent cross-role contamination (bug #12).
function getPatientId($conn) {
    if (!isset($_SESSION['user_id'])) return 0;
    if (isset($_SESSION['patient_ref_id']) && $_SESSION['patient_ref_id']) return (int)$_SESSION['patient_ref_id'];
    $stmt = mysqli_prepare($conn, "SELECT patient_id FROM patient WHERE user_id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $_SESSION['user_id']);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    $id  = (int)($row['patient_id'] ?? 0);
    $_SESSION['patient_ref_id'] = $id;
    return $id;
}

function getDoctorId($conn) {
    if (!isset($_SESSION['user_id'])) return 0;
    if (isset($_SESSION['doctor_ref_id']) && $_SESSION['doctor_ref_id']) return (int)$_SESSION['doctor_ref_id'];
    $stmt = mysqli_prepare($conn, "SELECT doctor_id FROM doctor WHERE user_id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $_SESSION['user_id']);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    $id  = (int)($row['doctor_id'] ?? 0);
    $_SESSION['doctor_ref_id'] = $id;
    return $id;
}

function requireRole($roles) {
    requireLogin();
    if (!in_array($_SESSION['role'] ?? '', (array)$roles)) {
        header("Location: /clinic/dashboard.php");
        exit();
    }
}
?>
