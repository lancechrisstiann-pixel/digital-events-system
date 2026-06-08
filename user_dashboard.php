<?php
session_start();
include('connection.php');

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }
$user_id  = $_SESSION['user_id'];
$username = $_SESSION['username'] ?? 'User';
$msg      = $_GET['msg'] ?? '';

/* ── CREATE EVENT ──────────────────────────────────────────
   Handles the "New Event" form submission.
   Wraps insert in a transaction so it fully succeeds or rolls back.
   ─────────────────────────────────────────────────────────── */
if (isset($_POST['submit_event'])) {
    $t    = trim($_POST['title']);
    $l    = trim($_POST['location']);
    $d    = $_POST['event_date'];
    $type = trim($_POST['event_type'] ?? 'public');
    if ($t && $l && $d) {
        $con->begin_transaction();
        try {
            $desc = trim($_POST['description']);
            $mx   = intval($_POST['max_attendees']) ?: null;
            $s    = $con->prepare("INSERT INTO events (user_id,title,location,description,event_date,max_attendees,status,event_type) VALUES (?,?,?,?,?,?,'unready',?)");
            $s->bind_param("issssss", $user_id, $t, $l, $desc, $d, $mx, $type);
            $s->execute(); $s->close();
            $con->commit();
            header("Location: user_dashboard.php?msg=created"); exit();
        } catch (Exception $e) { $con->rollback(); }
    }
}

/* ── UPDATE EVENT SETUP ────────────────────────────────────
   Changes status (draft/ready/live/etc.) and visibility (public/private)
   for one of the user's own events.
   ─────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_event_settings'])) {
    $eid     = intval($_POST['event_id'] ?? 0);
    $status  = trim($_POST['new_status'] ?? '');
    $type    = trim($_POST['event_type'] ?? 'public');
    $allowed = ['unready', 'ready', 'ongoing', 'stopped', 'finished'];
    if ($eid > 0 && in_array($status, $allowed)) {
        $stmt = $con->prepare("UPDATE events SET status = ?, event_type = ? WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ssii", $status, $type, $eid, $user_id);
        $stmt->execute(); $stmt->close();
        header("Location: user_dashboard.php?msg=updated");
    } else {
        header("Location: user_dashboard.php?msg=error");
    }
    exit();
}

/* ── DIRECT INVITATION ─────────────────────────────────────
   Lets a private-event organiser add a specific user by username.
   Checks capacity before inserting into the attendees table.
   ─────────────────────────────────────────────────────────── */
if (isset($_POST['direct_invite_user'])) {
    $eid         = intval($_POST['event_id']);
    $target_user = trim($_POST['invited_username']);
    $con->begin_transaction();
    try {
        $auth_chk = $con->prepare("SELECT id, max_attendees, (SELECT COUNT(*) FROM attendees WHERE event_id=?) AS cur FROM events WHERE id=? AND user_id=?");
        $auth_chk->bind_param("iii", $eid, $eid, $user_id); $auth_chk->execute();
        $event_info = $auth_chk->get_result()->fetch_assoc(); $auth_chk->close();
        if ($event_info) {
            $user_chk = $con->prepare("SELECT id FROM users WHERE username=?");
            $user_chk->bind_param("s", $target_user); $user_chk->execute();
            $target_info = $user_chk->get_result()->fetch_assoc(); $user_chk->close();
            if ($target_info) {
                if ($event_info['max_attendees'] && $event_info['cur'] >= $event_info['max_attendees']) {
                    $con->rollback(); header("Location: user_dashboard.php?msg=full"); exit();
                }
                $s = $con->prepare("INSERT IGNORE INTO attendees (event_id, user_id) VALUES (?, ?)");
                $s->bind_param("ii", $eid, $target_info['id']); $s->execute(); $s->close();
                $con->commit(); header("Location: user_dashboard.php?msg=invited_success"); exit();
            } else {
                header("Location: user_dashboard.php?msg=user_not_found"); exit();
            }
        }
    } catch (Exception $e) { $con->rollback(); }
}

/* ── DELETE EVENT ──────────────────────────────────────────
   Removes an event only when it is in a safe state
   (draft, finished, or postponed) — never while it is live.
   ─────────────────────────────────────────────────────────── */
if (isset($_POST['delete_event'])) {
    $eid = intval($_POST['event_id']);
    $con->begin_transaction();
    try {
        $s = $con->prepare("DELETE FROM events WHERE id=? AND user_id=? AND status IN ('unready','finished','stopped')");
        $s->bind_param("ii", $eid, $user_id); $s->execute(); $s->close();
        $con->commit();
        header("Location: user_dashboard.php?msg=deleted"); exit();
    } catch (Exception $e) { $con->rollback(); }
}

/* ── JOIN / LEAVE PUBLIC EVENT ─────────────────────────────
   Registers or de-registers the current user for someone else's event.
   Blocks registration if the event is private or already full.
   ─────────────────────────────────────────────────────────── */
if (isset($_POST['toggle_attend'])) {
    $eid = intval($_POST['event_id']);
    $con->begin_transaction();
    try {
        if ($_POST['attend_action'] === 'register') {
            $chk = $con->prepare("SELECT max_attendees, event_type, (SELECT COUNT(*) FROM attendees WHERE event_id=?) AS cur FROM events WHERE id=?");
            $chk->bind_param("ii", $eid, $eid); $chk->execute();
            $r = $chk->get_result()->fetch_assoc(); $chk->close();
            if ($r['event_type'] === 'private') { $con->rollback(); header("Location: user_dashboard.php?msg=private_restricted"); exit(); }
            if ($r['max_attendees'] && $r['cur'] >= $r['max_attendees']) { $con->rollback(); header("Location: user_dashboard.php?msg=full"); exit(); }
            $s = $con->prepare("INSERT IGNORE INTO attendees (event_id,user_id) VALUES (?,?)");
        } else {
            $s = $con->prepare("DELETE FROM attendees WHERE event_id=? AND user_id=?");
        }
        $s->bind_param("ii", $eid, $user_id); $s->execute(); $s->close();
        $con->commit();
        header("Location: user_dashboard.php"); exit();
    } catch (Exception $e) { $con->rollback(); }
}

/* ── DATA RETRIEVAL ────────────────────────────────────────
   All SELECT queries run here before the HTML is rendered.
   Keeps logic at the top, display at the bottom.
   ─────────────────────────────────────────────────────────── */
$s = $con->prepare("
    SELECT e.*, (SELECT COUNT(*) FROM attendees a JOIN users u ON a.user_id = u.id WHERE a.event_id=e.id AND u.username != 'admin') AS att_count
    FROM events e WHERE e.user_id=? AND e.status != 'finished' ORDER BY e.event_date ASC
");
$s->bind_param("i", $user_id); $s->execute(); $myEventsResult = $s->get_result(); $s->close();

$myEvents = [];
while ($row = $myEventsResult->fetch_assoc()) {
    $attStmt = $con->prepare("SELECT u.username FROM attendees a JOIN users u ON a.user_id = u.id WHERE a.event_id = ? AND u.username != 'admin' ORDER BY a.registered_at ASC");
    $attStmt->bind_param("i", $row['id']); $attStmt->execute(); $attRes = $attStmt->get_result();
    $row['joined_users'] = [];
    while ($uRow = $attRes->fetch_assoc()) { $row['joined_users'][] = $uRow; }
    $attStmt->close();
    $myEvents[] = $row;
}

$s = $con->prepare("
    SELECT e.*, u.username AS organizer,
        (SELECT COUNT(*) FROM attendees a JOIN users u ON a.user_id = u.id WHERE a.event_id=e.id AND u.username != 'admin') AS att_count,
        (SELECT COUNT(*) FROM attendees a WHERE a.event_id=e.id AND a.user_id=?) AS i_reg
    FROM events e JOIN users u ON u.id=e.user_id
    WHERE e.user_id!=? AND e.status IN ('ready','ongoing','unready','stopped')
      AND (e.event_type = 'public' OR e.event_type IS NULL)
    ORDER BY e.event_date ASC LIMIT 20
");
$s->bind_param("ii", $user_id, $user_id); $s->execute(); $openEvents = $s->get_result(); $s->close();

$s = $con->prepare("
    SELECT e.*, u.username AS organizer
    FROM events e JOIN users u ON u.id = e.user_id JOIN attendees a ON a.event_id = e.id
    WHERE a.user_id = ? AND e.user_id != ? AND e.event_type = 'private' AND e.status != 'finished'
    ORDER BY e.event_date ASC
");
$s->bind_param("ii", $user_id, $user_id); $s->execute(); $myInvitationsResult = $s->get_result(); $s->close();

$myInvitations = [];
while ($invRow = $myInvitationsResult->fetch_assoc()) { $myInvitations[] = $invRow; }
$invitationCount = count($myInvitations);

$s = $con->prepare("SELECT id, title, location, event_date, max_attendees, event_type FROM events WHERE user_id = ? AND status = 'finished' ORDER BY event_date DESC");
$s->bind_param("i", $user_id); $s->execute(); $archivedHostedRes = $s->get_result(); $s->close();

$myArchivedHosted = [];
while ($archRow = $archivedHostedRes->fetch_assoc()) {
    $attStmt = $con->prepare("SELECT u.username FROM attendees a JOIN users u ON a.user_id = u.id WHERE a.event_id = ? AND u.username != 'admin' ORDER BY a.registered_at ASC");
    $attStmt->bind_param("i", $archRow['id']); $attStmt->execute(); $attRes = $attStmt->get_result();
    $archRow['joined_users'] = [];
    while ($uRow = $attRes->fetch_assoc()) { $archRow['joined_users'][] = $uRow; }
    $attStmt->close();
    $myArchivedHosted[] = $archRow;
}

$s = $con->prepare("
    SELECT e.*, u.username AS organizer
    FROM events e JOIN users u ON u.id = e.user_id JOIN attendees a ON a.event_id = e.id
    WHERE a.user_id = ? AND e.user_id != ? AND e.status = 'finished' ORDER BY e.event_date DESC
");
$s->bind_param("ii", $user_id, $user_id); $s->execute(); $myArchivedJoined = $s->get_result(); $s->close();

/* ── Quick stats for the 3 KPI cards at the top of the page ── */
$totalMyEvents  = count($myEvents);
$totalAttending = 0;
$s = $con->prepare("SELECT COUNT(*) AS c FROM attendees a JOIN events e ON e.id = a.event_id WHERE a.user_id = ? AND e.user_id != ? AND e.status != 'finished'");
$s->bind_param("ii", $user_id, $user_id); $s->execute();
$totalAttending = $s->get_result()->fetch_assoc()['c'] ?? 0; $s->close();

function badge($s) {
    $map = [
        'unready'  => ['label' => 'Not Ready',     'cls' => 'badge-unready'],
        'ready'    => ['label' => 'Ready',     'cls' => 'badge-ready'],
        'ongoing'  => ['label' => 'Live',      'cls' => 'badge-ongoing'],
        'stopped'  => ['label' => 'Postponed', 'cls' => 'badge-stopped'],
        'finished' => ['label' => 'Finished',  'cls' => 'badge-finished'],
    ];
    $b = $map[$s] ?? ['label' => ucfirst($s), 'cls' => 'badge-unready'];
    return "<span class='badge {$b['cls']}'>{$b['label']}</span>";
}
function esc($v) { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
function timeAgo($date) {
    $diff = time() - strtotime($date);
    if ($diff < 86400) return 'Today';
    if ($diff < 172800) return 'Tomorrow';
    return date('M j, Y', strtotime($date));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Dashboard — Event Mangement</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<div class="app-container">
    <aside class="sidebar">
        <div class="sidebar-brand">
            <div style="display:flex; align-items:center; gap:10px;">
                <div style="width:34px;height:34px;background:linear-gradient(135deg,#6366f1,#8b5cf6);border-radius:9px;display:flex;align-items:center;justify-content:center;">
                    <i class="fa-solid fa-calendar-star" style="color:#fff;font-size:0.9rem;"></i>
                </div>
                <div>
                    <h2 style="margin:0;">EventManagement</h2>
                    <div class="brand-sub">Events</div>
                </div>
            </div>
        </div>
        <div class="sidebar-section">Navigation</div>
        <nav class="sidebar-menu">
            <a href="user_dashboard.php" class="menu-item active">
                <i class="fa-solid fa-grid-2"></i> Dashboard
            </a>
        </nav>
        <div class="sidebar-footer">
            <div class="user-pill">
                <div class="user-avatar"><?= strtoupper(substr($username, 0, 1)) ?></div>
                <div>
                    <div class="user-name"><?= esc($username) ?></div>
                    <div class="user-role">Event Organizer</div>
                </div>
                <a href="logout.php" style="margin-left:auto; color:rgba(255,255,255,0.3); font-size:0.85rem;" title="Logout"><i class="fa-solid fa-right-from-bracket"></i></a>
            </div>
        </div>
    </aside>

    <main class="main-content">

        <!-- ══ TOP BAR: greeting + "New Event" button ══ -->
        <div class="topbar">
            <div class="topbar-left">
                <h1>Good <?= date('H') < 12 ? 'morning' : (date('H') < 17 ? 'afternoon' : 'evening') ?>, <?= esc($username) ?> 👋</h1>
                <p><?= date('l, F j, Y') ?></p>
            </div>
            <button class="btn-create" onclick="document.getElementById('createModal').classList.add('show')">
                <i class="fa-solid fa-plus"></i> New Event
            </button>
        </div>

        <!-- ══ FLASH ALERTS: shown once after a form action ══ -->
        <?php if ($msg === 'created'):         ?><div class="alert alert-s"><i class="fa-solid fa-check-circle"></i> Event created successfully.</div><?php endif; ?>
        <?php if ($msg === 'updated'):         ?><div class="alert alert-s"><i class="fa-solid fa-check-circle"></i> Event updated successfully.</div><?php endif; ?>
        <?php if ($msg === 'deleted'):         ?><div class="alert alert-s"><i class="fa-solid fa-check-circle"></i> Event deleted.</div><?php endif; ?>
        <?php if ($msg === 'invited_success'): ?><div class="alert alert-s"><i class="fa-solid fa-check-circle"></i> User invited successfully.</div><?php endif; ?>
        <?php if ($msg === 'user_not_found'):  ?><div class="alert alert-w"><i class="fa-solid fa-triangle-exclamation"></i> Username not found.</div><?php endif; ?>
        <?php if ($msg === 'full'):            ?><div class="alert alert-w"><i class="fa-solid fa-triangle-exclamation"></i> Event is at full capacity.</div><?php endif; ?>
        <?php if ($msg === 'error'):           ?><div class="alert alert-w"><i class="fa-solid fa-triangle-exclamation"></i> Something went wrong. Please try again.</div><?php endif; ?>

        <!-- ══ QUICK STATS: 3 KPI mini-cards ══ -->
        <div class="quick-stats">
            <div class="qs-card">
                <div class="qs-icon purple"><i class="fa-solid fa-calendar-days"></i></div>
                <div><div class="qs-val"><?= $totalMyEvents ?></div><div class="qs-lbl">My Active Events</div></div>
            </div>
            <div class="qs-card">
                <div class="qs-icon green"><i class="fa-solid fa-circle-check"></i></div>
                <div><div class="qs-val"><?= $totalAttending ?></div><div class="qs-lbl">Events I'm Attending</div></div>
            </div>
            <div class="qs-card">
                <div class="qs-icon orange"><i class="fa-solid fa-envelope"></i></div>
                <div><div class="qs-val"><?= $invitationCount ?></div><div class="qs-lbl">Pending Invitations</div></div>
            </div>
        </div>

        <!-- ══ TAB BAR: switches between the 4 content panels ══ -->
        <div class="tabs">
            <button class="tab-btn active" data-tab="my-events">
                <i class="fa-solid fa-calendar-check"></i> My Events
                <?php if ($totalMyEvents > 0): ?><span class="notif-badge"><?= $totalMyEvents ?></span><?php endif; ?>
            </button>
            <button class="tab-btn" data-tab="open-events">
                <i class="fa-solid fa-globe"></i> Discover
            </button>
            <button class="tab-btn" data-tab="my-invitations">
                <i class="fa-solid fa-envelope-open-text"></i> Invitations
                <?php if ($invitationCount > 0): ?><span class="notif-badge"><?= $invitationCount ?></span><?php endif; ?>
            </button>
            <button class="tab-btn" data-tab="archive-ledger">
                <i class="fa-solid fa-clock-rotate-left"></i> History
            </button>
        </div>

        <!-- ══ TAB 1: events this user created (not yet finished) ══ -->
        <div class="tab-panel active" id="my-events">
            <div class="section-header">
                <div class="section-title"><i class="fa-solid fa-folder-open"></i> Your Events</div>
                <span class="section-count"><?= $totalMyEvents ?> active</span>
            </div>
            <?php if (!empty($myEvents)): ?>
            <div class="ecard-grid">
                <?php foreach ($myEvents as $ev):
                    $cap     = $ev['max_attendees'] ?: 0;
                    $att     = $ev['att_count'];
                    $pct     = $cap > 0 ? min(100, round($att / $cap * 100)) : 0;
                ?>
                <div class="ecard">
                    <div class="ecard-head">
                        <h3 class="ecard-title"><?= esc($ev['title']) ?></h3>
                        <div class="ecard-badges">
                            <?= badge($ev['status']) ?>
                            <span class="pbadge"><?= esc($ev['event_type'] ?? 'public') ?></span>
                        </div>
                    </div>
                    <div class="emeta">
                        <div class="emeta-row"><i class="fa-solid fa-location-dot"></i><?= esc($ev['location']) ?></div>
                        <div class="emeta-row"><i class="fa-regular fa-calendar"></i><?= date('M j, Y · g:i A', strtotime($ev['event_date'])) ?></div>
                    </div>
                    <?php if ($cap > 0): ?>
                    <div class="cap-bar-wrap">
                        <div class="cap-bar"><div class="cap-bar-fill" style="width:<?= $pct ?>%"></div></div>
                        <span><?= $att ?>/<?= $cap ?></span>
                    </div>
                    <?php else: ?>
                    <div class="emeta-row"><i class="fa-solid fa-users" style="color:#94a3b8;font-size:0.75rem;"></i><span style="font-size:0.78rem;color:#64748b;"><?= $att ?> attending · Unlimited capacity</span></div>
                    <?php endif; ?>
                    <div class="ecard-divider"></div>
                    <div class="ecard-footer">
                        <a href="event_invite.php?id=<?= $ev['id'] ?>" class="btn-sm btn-outline" target="_blank">
                            <i class="fa-solid fa-eye"></i> Preview
                        </a>
                        <button type="button" class="btn-sm btn-indigo" onclick="openManageModal(<?= $ev['id'] ?>, '<?= esc($ev['status']) ?>', '<?= esc($ev['event_type'] ?? 'public') ?>', '<?= esc($ev['title']) ?>')">
                            <i class="fa-solid fa-sliders"></i> Manage
                        </button>
                        <?php if (($ev['event_type'] ?? '') === 'private'): ?>
                        <button type="button" class="btn-sm btn-green" onclick="openInviteModal(<?= $ev['id'] ?>, '<?= esc($ev['title']) ?>')">
                            <i class="fa-solid fa-user-plus"></i> Invite
                        </button>
                        <?php endif; ?>
                        <?php if (in_array($ev['status'], ['unready', 'finished', 'stopped'])): ?>
                        <form method="POST" onsubmit="return confirm('Delete this event?');" style="display:inline;">
                            <input type="hidden" name="event_id" value="<?= $ev['id'] ?>">
                            <button type="submit" name="delete_event" class="btn-sm btn-red">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <div class="empty-icon"><i class="fa-solid fa-calendar-plus"></i></div>
                <p>You haven't created any events yet.<br>Click <strong>New Event</strong> to get started.</p>
            </div>
            <?php endif; ?>
        </div>

        <!-- ══ TAB 2: public events from other users ══ -->
        <div class="tab-panel" id="open-events">
            <div class="section-header">
                <div class="section-title"><i class="fa-solid fa-globe"></i> Public Events</div>
            </div>
            <?php if ($openEvents->num_rows > 0): while ($oe = $openEvents->fetch_assoc()): ?>
            <div class="pub-card">
                <div style="width:42px;height:42px;border-radius:10px;background:linear-gradient(135deg,#e0e7ff,#ddd6fe);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="fa-solid fa-calendar" style="color:#6366f1;"></i>
                </div>
                <div class="pub-card-info">
                    <div class="pub-card-title"><?= esc($oe['title']) ?></div>
                    <div class="pub-card-meta">
                        <span><i class="fa-solid fa-user-tie"></i> <?= esc($oe['organizer']) ?></span>
                        <span><i class="fa-solid fa-location-dot"></i> <?= esc($oe['location']) ?></span>
                        <span><i class="fa-regular fa-calendar"></i> <?= date('M j, Y', strtotime($oe['event_date'])) ?></span>
                        <span><i class="fa-solid fa-users"></i> <?= $oe['att_count'] ?><?= $oe['max_attendees'] ? '/'.$oe['max_attendees'] : '' ?></span>
                    </div>
                </div>
                <div style="display:flex;align-items:center;gap:8px;">
                    <?= badge($oe['status']) ?>
                </div>
                <div class="pub-card-actions">
                    <a href="event_invite.php?id=<?= $oe['id'] ?>" class="btn-sm btn-outline" target="_blank"><i class="fa-solid fa-eye"></i></a>
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="event_id" value="<?= $oe['id'] ?>">
                        <?php if ($oe['i_reg'] > 0): ?>
                            <input type="hidden" name="attend_action" value="deregister">
                            <button type="submit" name="toggle_attend" class="btn-sm btn-slate"><i class="fa-solid fa-door-open"></i> Leave</button>
                        <?php else: ?>
                            <input type="hidden" name="attend_action" value="register">
                            <button type="submit" name="toggle_attend" class="btn-sm btn-indigo" <?= ($oe['max_attendees'] && $oe['att_count'] >= $oe['max_attendees']) ? 'disabled style="opacity:0.5;cursor:not-allowed;"' : '' ?>>
                                <i class="fa-solid fa-right-to-bracket"></i> Join
                            </button>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
            <?php endwhile; else: ?>
            <div class="empty-state">
                <div class="empty-icon"><i class="fa-solid fa-globe"></i></div>
                <p>No public events available right now.</p>
            </div>
            <?php endif; ?>
        </div>

        <!-- ══ TAB 3: private events the user was invited to ══ -->
        <div class="tab-panel" id="my-invitations">
            <div class="section-header">
                <div class="section-title"><i class="fa-solid fa-envelope-open-text"></i> Private Invitations</div>
                <?php if ($invitationCount > 0): ?><span class="section-count"><?= $invitationCount ?> pending</span><?php endif; ?>
            </div>
            <?php if (!empty($myInvitations)): foreach ($myInvitations as $inv): ?>
            <div class="pub-card">
                <div style="width:42px;height:42px;border-radius:10px;background:linear-gradient(135deg,#fef3c7,#fde68a);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="fa-solid fa-envelope" style="color:#d97706;"></i>
                </div>
                <div class="pub-card-info">
                    <div class="pub-card-title"><?= esc($inv['title']) ?></div>
                    <div class="pub-card-meta">
                        <span><i class="fa-solid fa-user-shield"></i> <?= esc($inv['organizer']) ?></span>
                        <span><i class="fa-solid fa-location-dot"></i> <?= esc($inv['location']) ?></span>
                        <span><i class="fa-regular fa-calendar"></i> <?= date('M j, Y', strtotime($inv['event_date'])) ?></span>
                    </div>
                </div>
                <div style="display:flex;align-items:center;gap:8px;">
                    <?= badge($inv['status']) ?>
                    <span class="pbadge">Invited</span>
                </div>
                <div class="pub-card-actions">
                    <a href="event_invite.php?id=<?= $inv['id'] ?>" class="btn-sm btn-amber" target="_blank"><i class="fa-solid fa-ticket"></i> View</a>
                </div>
            </div>
            <?php endforeach; else: ?>
            <div class="empty-state">
                <div class="empty-icon"><i class="fa-solid fa-envelope-open"></i></div>
                <p>No private invitations yet.</p>
            </div>
            <?php endif; ?>
        </div>

        <!-- ══ TAB 4: finished events (hosted + attended) ══ -->
        <div class="tab-panel" id="archive-ledger">
            <div class="section-title" style="margin-bottom:14px;"><i class="fa-solid fa-flag-checkered"></i> Events You Hosted</div>
            <?php if (!empty($myArchivedHosted)): foreach ($myArchivedHosted as $ah): ?>
            <div class="arch-card">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;">
                    <div class="arch-title"><i class="fa-solid fa-box-archive" style="color:#94a3b8;margin-right:6px;"></i><?= esc($ah['title']) ?></div>
                    <span class="badge badge-finished">Finished</span>
                </div>
                <div class="arch-meta">
                    <span><i class="fa-solid fa-location-dot"></i> <?= esc($ah['location']) ?></span>
                    <span><i class="fa-regular fa-calendar"></i> <?= date('M j, Y', strtotime($ah['event_date'])) ?></span>
                    <span class="pbadge"><?= esc($ah['event_type'] ?? 'public') ?></span>
                </div>
                <?php if (!empty($ah['joined_users'])): ?>
                <div class="attendee-chips">
                    <?php foreach ($ah['joined_users'] as $u): ?>
                        <span class="attendee-chip">@<?= esc($u['username']) ?></span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; else: ?>
            <div class="empty-state"><div class="empty-icon"><i class="fa-solid fa-box-open"></i></div><p>No hosted events concluded yet.</p></div>
            <?php endif; ?>

            <div class="section-title" style="margin:22px 0 14px;"><i class="fa-solid fa-award"></i> Events You Attended</div>
            <?php if ($myArchivedJoined && $myArchivedJoined->num_rows > 0): while ($aj = $myArchivedJoined->fetch_assoc()): ?>
            <div class="arch-card">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;">
                    <div class="arch-title"><?= esc($aj['title']) ?></div>
                    <span class="badge badge-finished">Attended</span>
                </div>
                <div class="arch-meta">
                    <span><i class="fa-solid fa-user-tie"></i> <?= esc($aj['organizer']) ?></span>
                    <span><i class="fa-solid fa-location-dot"></i> <?= esc($aj['location']) ?></span>
                    <span><i class="fa-regular fa-calendar"></i> <?= date('M j, Y', strtotime($aj['event_date'])) ?></span>
                </div>
            </div>
            <?php endwhile; else: ?>
            <div class="empty-state"><div class="empty-icon"><i class="fa-solid fa-ticket"></i></div><p>No attended events yet.</p></div>
            <?php endif; ?>
        </div>

    </main>
</div>

<!-- ══ MODAL: create a new event ══ -->
<div class="modal-overlay" id="createModal">
    <div class="modal-card">
        <div class="modal-head"><i class="fa-solid fa-calendar-plus"></i> Create New Event</div>
        <div class="modal-sub">Fill in the details below to publish your event.</div>
        <form method="POST">
            <div class="fg"><label>Event Title *</label><input type="text" name="title" placeholder="" ></div>
            <div class="fg"><label>Venue / Location *</label><input type="text" name="location" placeholder=""> </div>
            <div class="fg"><label>Date *</label><input type="date" name="event_date" required></div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <div class="fg"><label>Max Attendees</label><input type="number" name="max_attendees" min="1" placeholder=" "></div>
                <div class="fg">
                    <label>Visibility</label>
                    <select name="event_type">
                        <option value="public">Public</option>
                        <option value="private">Private (Invite Only)</option>
                    </select>
                </div>
            </div>
            <div class="fg"><label>Description</label><textarea name="description" rows="3" placeholder="Brief description of the event…"></textarea></div>
            <div class="mfoot">
                <button type="button" class="btn-cancel" onclick="document.getElementById('createModal').classList.remove('show')">Cancel</button>
                <button type="submit" name="submit_event" class="btn-save"><i class="fa-solid fa-plus" style="margin-right:5px;"></i>Create Event</button>
            </div>>
        </form>
    </div>
</div>

<!-- ══ MODAL: change event status / visibility ══ -->
<div class="modal-overlay" id="manageModal">
    <div class="modal-card">
        <div class="modal-head"><i class="fa-solid fa-sliders"></i> Manage Event</div>
        <p id="manageEventTitle" style="font-size:0.82rem;color:#64748b;margin:0 0 18px;"></p>
        <form method="POST">
            <input type="hidden" name="event_id" id="manageEventId">
            <input type="hidden" name="update_event_settings" value="1">
            <div class="fg">
                <label>Status</label>
                <select name="new_status" id="manageStatus">
                    <option value="unready">Not Ready</option>
                    <option value="ready">Ready</option>
                    <option value="ongoing">Ongoing / Live</option>
                    <option value="stopped">Postponed</option>
                    <option value="finished">Finished</option>
                </select>
            </div>
            <div class="fg">
                <label>Visibility</label>
                <select name="event_type" id="manageEventType">
                    <option value="public">Public</option>
                    <option value="private">Private (Invite Only)</option>
                </select>
            </div>
            <div class="mfoot">
                <button type="button" class="btn-cancel" onclick="document.getElementById('manageModal').classList.remove('show')">Cancel</button>
                <button type="submit" class="btn-save">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- ══ MODAL: invite a user by username to a private event ══ -->
<div class="modal-overlay" id="inviteModal">
    <div class="modal-card">
        <div class="modal-head"><i class="fa-solid fa-user-plus"></i> Invite a User</div>
        <p id="inviteEventTitle" style="font-size:0.82rem;color:#64748b;margin:0 0 18px;"></p>
        <form method="POST">
            <input type="hidden" name="event_id" id="inviteEventId">
            <div class="fg">
                <label>Username *</label>
                <input type="text" name="invited_username" required placeholder="Enter exact username">
            </div>
            <div class="mfoot">
                <button type="button" class="btn-cancel" onclick="document.getElementById('inviteModal').classList.remove('show')">Cancel</button>
                <button type="submit" name="direct_invite_user" class="btn-save" style="background:linear-gradient(135deg,#10b981,#059669);">Send Invite</button>
            </div>
        </form>
    </div>
</div>

<script>
/* ── TAB SWITCHING: click a tab button → show matching panel ── */
document.querySelectorAll('.tab-btn').forEach(b => b.addEventListener('click', () => {
    document.querySelectorAll('.tab-btn,.tab-panel').forEach(el => el.classList.remove('active'));
    b.classList.add('active');
    document.getElementById(b.dataset.tab).classList.add('active');
}));

/* ── Opens the Manage modal and pre-fills current status/type ── */
function openManageModal(id, status, type, title) {
    document.getElementById('manageEventId').value      = id;
    document.getElementById('manageEventTitle').innerText = title;
    document.getElementById('manageStatus').value       = status;
    document.getElementById('manageEventType').value    = type;
    document.getElementById('manageModal').classList.add('show');
}

/* ── Opens the Invite modal and sets the target event ID ── */
function openInviteModal(id, title) {
    document.getElementById('inviteEventId').value      = id;
    document.getElementById('inviteEventTitle').innerText = 'Event: ' + title;
    document.getElementById('inviteModal').classList.add('show');
}

/* ── Click outside a modal card to close it ── */
document.querySelectorAll('.modal-overlay').forEach(m =>
    m.addEventListener('click', e => { if (e.target === m) m.classList.remove('show'); })
);
</script>
</body>
</html>