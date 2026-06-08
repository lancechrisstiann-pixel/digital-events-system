<?php
/**
 * admin_events.php — View and manage all event records.
 */
session_start();
include('connection.php');

if (!isset($_SESSION['user_id']) || $_SESSION['username'] !== 'admin') {
    header("Location: login.php"); exit();
}

/* Only these status values are accepted — anything else is rejected */
$allowed = ['unready', 'ready', 'ongoing', 'stopped', 'finished'];

/* ── Handle the Manage modal form submission ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_event_settings'])) {
    $eid    = intval($_POST['event_id'] ?? 0);
    $status = trim($_POST['new_status'] ?? '');
    $type   = in_array(trim($_POST['event_type'] ?? ''), ['public','private']) ? trim($_POST['event_type']) : 'public';

    if ($eid > 0 && in_array($status, $allowed)) {
        $stmt = $con->prepare("UPDATE events SET status = ?, event_type = ? WHERE id = ?");
        $stmt->bind_param("ssi", $status, $type, $eid);
        $stmt->execute();
        $stmt->close();
        header("Location: admin_events.php?msg=success");
    } else {
        header("Location: admin_events.php?msg=error");
    }
    exit();
}

/* ── Fetch events, optionally filtered by a specific organiser ── */
$filter = isset($_GET['user_filter']) ? intval($_GET['user_filter']) : 0;
$users  = $con->query("SELECT id, username FROM users WHERE username != 'admin'");
$sql    = "SELECT e.*, u.username FROM events e JOIN users u ON e.user_id = u.id"
        . ($filter > 0 ? " WHERE e.user_id = $filter" : "")
        . " ORDER BY e.id DESC";
$events = $con->query($sql);

function badge($s) {
    $map = [
        'unready'  => ['badge-unready',  'Not Ready'],
        'ready'    => ['badge-ready',    'Ready'],
        'ongoing'  => ['badge-ongoing',  'Ongoing'],
        'stopped'  => ['badge-stopped',  'Postponed'],
        'finished' => ['badge-finished', 'Finished'],
    ];
    [$cls, $lbl] = $map[$s] ?? ['badge-unready', ucfirst($s)];
    return "<span class='badge $cls'>$lbl</span>";
}
function esc($v) { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Events — EventHub Admin</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<div class="app-container">
    <?php include('admin_sidebar.php'); ?>

    <main class="main-content">
        <div class="topbar">
            <div class="topbar-left">
                <h1>Manage Events</h1>
                <p></p>
            </div>
        </div>

        <?php if (($_GET['msg'] ?? '') === 'success'): ?>
            <div class="alert alert-s"><i class="fa-solid fa-check-circle"></i> Event updated successfully.</div>
        <?php elseif (($_GET['msg'] ?? '') === 'error'): ?>
            <div class="alert alert-w"><i class="fa-solid fa-triangle-exclamation"></i> Error updating event.</div>
        <?php endif; ?>

        <div class="filter-card">
            <label><i class="fa-solid fa-filter"></i> Filter by Coordinator:</label>
            <select onchange="location.href='admin_events.php?user_filter=' + this.value;">
                <option value="0" <?= $filter === 0 ? 'selected' : '' ?>>All Organizers</option>
                <?php while ($u = $users->fetch_assoc()): ?>
                    <option value="<?= $u['id'] ?>" <?= $filter == $u['id'] ? 'selected' : '' ?>><?= esc($u['username']) ?></option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="data-table-card">
            <table class="management-table">
                <thead>
                    <tr>
                        <th>Event Title</th>
                        <th>Coordinator</th>
                        <th>Scheduled Date</th>
                        <th>Status &amp; Type</th>
                        <th style="text-align:center;">Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($events->num_rows > 0): ?>
                    <?php while ($row = $events->fetch_assoc()): ?>
                    <tr>
                        <td style="font-weight:600;"><?= esc($row['title']) ?></td>
                        <td><i class="fa-regular fa-user" style="color:#94a3b8;margin-right:4px;"></i><?= esc($row['username']) ?></td>
                        <td style="color:#64748b;"><?= date('M d, Y', strtotime($row['event_date'])) ?></td>
                        <td>
                            <?= badge($row['status']) ?>
                            <span class="pbadge"><?= esc($row['event_type'] ?? 'public') ?></span>
                        </td>
                        <td style="text-align:center;">
                            <button type="button" class="btn-sm btn-indigo"
                                    onclick="openManageModal(<?= $row['id'] ?>, '<?= esc($row['status']) ?>', '<?= esc($row['event_type'] ?? 'public') ?>', '<?= esc(addslashes($row['title'])) ?>')">
                                <i class="fa-solid fa-sliders"></i> Manage
                            </button>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5" class="no-records">
                            <i class="fa-solid fa-folder-open" style="font-size:2rem;display:block;margin-bottom:8px;opacity:.3;"></i>
                            No events found.
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>
</div>

<div class="modal-overlay" id="manageModal">
    <div class="modal-card">
        <div class="modal-head"><i class="fa-solid fa-sliders"></i> Manage Event</div>
        <p id="manageEventTitle" class="modal-sub"></p>
        <form method="POST">
            <input type="hidden" name="event_id" id="manageEventId">
            <input type="hidden" name="update_event_settings" value="1">
            <div class="fg">
                <label>Event Status</label>
                <select name="new_status" id="manageStatus">
                    <option value="unready">Not Ready</option>
                    <option value="ready">Ready</option>
                    <option value="ongoing">Ongoing</option>
                    <option value="stopped">Postponed</option>
                    <option value="finished">Finished</option>
                </select>
            </div>
            <div class="fg">
                <label>Visibility</label>
                <select name="event_type" id="manageEventType">
                    <option value="public">Public</option>
                    <option value="private">Private</option>
                </select>
            </div>
            <div class="mfoot">
                <button type="button" class="btn-cancel" onclick="document.getElementById('manageModal').classList.remove('show')">Cancel</button>
                <button type="submit" class="btn-save">Apply Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
function openManageModal(id, status, type, title) {
    document.getElementById('manageEventId').value       = id;
    document.getElementById('manageEventTitle').innerText = 'Event: ' + title;
    document.getElementById('manageStatus').value        = status;
    document.getElementById('manageEventType').value     = type;
    document.getElementById('manageModal').classList.add('show');
}
document.querySelectorAll('.modal-overlay').forEach(m =>
    m.addEventListener('click', e => { if (e.target === m) m.classList.remove('show'); })
);
</script>
</body>
</html>
