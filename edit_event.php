<?php
/**
 * edit_event.php — Edit an existing event.
 * Only the event owner can edit
 */
session_start();
include('connection.php');

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }

$user_id  = $_SESSION['user_id'];
$event_id = intval($_GET['id'] ?? 0);

$stmt = $con->prepare("SELECT * FROM events WHERE id = ? AND user_id = ?");
$stmt->bind_param("ii", $event_id, $user_id);
$stmt->execute();
$event = $stmt->get_result()->fetch_assoc();
$stmt->close();

/* Redirect away if the event does not belong to this user or is currently live */
if (!$event || strtolower(trim($event['status'])) === 'ongoing') {
    header("Location: user_dashboard.php"); exit();
}

if (isset($_POST['update_event'])) {
    $title    = trim($_POST['title']);
    $location = trim($_POST['location']);
    $desc     = trim($_POST['description']);
    $date     = $_POST['event_date'];

    if (!$title || !$location || !$date) {
        $error = "All required fields must be filled.";
    } else {
        $con->begin_transaction();
        try {
            $stmt = $con->prepare("UPDATE events SET title=?, location=?, description=?, event_date=? WHERE id=? AND user_id=? AND status != 'ongoing'");
            $stmt->bind_param("ssssii", $title, $location, $desc, $date, $event_id, $user_id);
            $stmt->execute(); $stmt->close();
            $con->commit();
            header("Location: user_dashboard.php"); exit();
        } catch (Exception $e) { $con->rollback(); $error = "Update failed. Please try again."; }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit Event — EventHub</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<div class="app-container">
    <aside class="sidebar">
        <div class="sidebar-brand">
            <div class="brand-icon"><i class="fa-solid fa-calendar-star"></i></div>
            <div><h2>EventHub</h2></div>
        </div>
        <nav class="sidebar-menu">
            <a href="user_dashboard.php" class="menu-item"><i class="fa-solid fa-house"></i> Dashboard</a>
            <a href="logout.php"         class="menu-item"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
        </nav>
    </aside>

    <main class="main-content">
        <div class="edit-container">
            <div class="edit-header">
                <i class="fa-solid fa-pen-to-square" style="color:#6366f1;font-size:1.2rem;"></i>
                <h2>Edit Event</h2>
            </div>

            <?php if (!empty($error)): ?>
                <div class="alert alert-w"><i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST">
                <div class="form-group">
                    <label>Event Title</label>
                    <input type="text" name="title" value="<?= htmlspecialchars($event['title']) ?>" required>
                </div>
                <div class="form-group">
                    <label>Location</label>
                    <input type="text" name="location" value="<?= htmlspecialchars($event['location']) ?>" required>
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" rows="4"><?= htmlspecialchars($event['description']) ?></textarea>
                </div>
                <div class="form-group">
                    <label>Event Date</label>
                    <input type="datetime-local" name="event_date" value="<?= date('Y-m-d\TH:i', strtotime($event['event_date'])) ?>" required>
                </div>
                <div class="form-actions">
                    <a href="user_dashboard.php" class="btn-edit-cancel">Cancel</a>
                    <button type="submit" name="update_event" class="btn-edit-save">Save Changes</button>
                </div>
            </form>
        </div>
    </main>
</div>
</body>
</html>
