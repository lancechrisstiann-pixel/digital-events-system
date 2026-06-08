<?php
/**
 * update_status.php — AJAX-free status update endpoint.
 * Accepts a POST from a user-dashboard form, validates the new status,
 * and updates only the events owned by the current session user.
 */
session_start();
include('connection.php');

/* ---------------- AUTH CHECK ---------------- */
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

/* ---------------- VALID POST CHECK ---------------- */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: user_dashboard.php");
    exit();
}

$user_id = $_SESSION['user_id'];

$event_id = isset($_POST['event_id']) ? intval($_POST['event_id']) : 0;
$new_status = isset($_POST['new_status']) ? trim($_POST['new_status']) : '';

/* ---------------- VALID STATUS LIST ---------------- */
$allowed_status = ['unready', 'ready', 'ongoing', 'stopped', 'finished'];

if ($event_id <= 0 || !in_array($new_status, $allowed_status)) {
    header("Location: user_dashboard.php?msg=invalid");
    exit();
}

/* ---------------- UPDATE STATUS (SECURE) ---------------- */
$stmt = $con->prepare("
    UPDATE events 
    SET status = ? 
    WHERE id = ? AND user_id = ?
");

$stmt->bind_param("sii", $new_status, $event_id, $user_id);

if ($stmt->execute()) {
    $stmt->close();
    header("Location: user_dashboard.php?msg=success");
    exit();
} else {
    $stmt->close();
    header("Location: user_dashboard.php?msg=error");
    exit();
}
?>