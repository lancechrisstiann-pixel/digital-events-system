<?php
session_start();
include('connection.php');

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }

$event_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($event_id <= 0) { header("Location: user_dashboard.php"); exit(); }

$stmt = $con->prepare("
    SELECT e.*, u.username AS organizer, e.event_type,
           (SELECT COUNT(*) FROM attendees a WHERE a.event_id = e.id) AS attendee_count
    FROM events e
    JOIN users u ON u.id = e.user_id
    WHERE e.id = ?
");
$stmt->bind_param("i", $event_id);
$stmt->execute();
$event = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$event) { header("Location: user_dashboard.php"); exit(); }

$ts        = strtotime($event['event_date']);
$cap       = $event['max_attendees'];
$filled    = $event['attendee_count'];
$spotsLeft = $cap ? max(0, $cap - $filled) : null;
$catIcon   = '📅';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invitation — <?= htmlspecialchars($event['title']) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,700;1,400&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
    /* Invitation card — standalone design, intentionally separate from style.css */
    :root { --ink: #1a1a2e; --gold: #c9a84c; --gold-lt: #f0d98e; --gold-dk: #8b6914; --cream: #fdfaf3; --cream-dk: #f5eed8; --border: rgba(201,168,76,0.35); }
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'DM Sans', sans-serif; background: #2d2d2d; display: flex; flex-direction: column; align-items: center; }

    /* Action bar */
    .action-bar { width: 100%; background: var(--ink); padding: 14px 32px; display: flex; justify-content: space-between; position: sticky; top: 0; z-index: 100; box-shadow: 0 2px 12px rgba(0,0,0,.4); }
    .action-bar .brand { display: flex; align-items: center; gap: 10px; color: var(--gold); font-family: 'Playfair Display', serif; font-size: 1.1rem; }
    .action-buttons { display: flex; gap: 10px; align-items: center; }
    .btn-back  { color: rgba(255,255,255,.7); text-decoration: none; font-size: .85rem; display: flex; align-items: center; gap: 6px; }
    .btn-print { background: var(--gold); color: var(--ink); border: none; padding: 9px 22px; border-radius: 6px; font-weight: 600; font-size: .88rem; cursor: pointer; display: flex; align-items: center; gap: 7px; }
    .btn-share { background: rgba(255,255,255,.1); color: #fff; border: 1px solid rgba(255,255,255,.2); padding: 9px 18px; border-radius: 6px; font-weight: 600; font-size: .88rem; cursor: pointer; display: flex; align-items: center; gap: 7px; }

    /* Card wrapper */
    .page-wrapper { padding: 40px 20px 60px; display: flex; flex-direction: column; align-items: center; width: 100%; }
    .invite-card  { width: 794px; background: var(--cream); position: relative; overflow: hidden; box-shadow: 0 8px 48px rgba(26,26,46,0.14); }
    .invite-card::before { content: ''; position: absolute; inset: 14px; border: 1.5px solid var(--border); pointer-events: none; z-index: 2; }

    /* Header */
    .card-header { background: var(--ink); padding: 52px 64px 44px; position: relative; }
    .gold-rule { display: flex; align-items: center; gap: 14px; margin-bottom: 24px; color: var(--gold); font-size: .78rem; letter-spacing: .22em; text-transform: uppercase; }
    .gold-rule::before, .gold-rule::after { content: ''; flex: 1; height: 1px; background: linear-gradient(to right, transparent, var(--gold), transparent); }
    .card-category { display: flex; align-items: center; gap: 8px; color: var(--gold); font-size: .8rem; letter-spacing: .15em; text-transform: uppercase; margin-bottom: 14px; font-weight: 500; }
    .card-title    { font-family: 'Playfair Display', serif; font-size: 2.9rem; line-height: 1.15; color: #fff; margin-bottom: 10px; }
    .card-subtitle { font-family: 'Playfair Display', serif; font-style: italic; color: rgba(255,255,255,.55); font-size: 1.05rem; margin-bottom: 28px; }
    .header-meta   { display: flex; gap: 28px; flex-wrap: wrap; }
    .header-chip   { display: flex; align-items: center; gap: 7px; color: rgba(255,255,255,.75); font-size: .84rem; }
    .header-chip i { color: var(--gold); }

    /* Date section */
    .date-showcase { background: var(--cream-dk); border-top: 3px solid var(--gold); border-bottom: 1px solid var(--border); padding: 32px 64px; display: flex; align-items: center; }
    .date-left { text-align: center; padding-right: 40px; border-right: 1px solid var(--border); min-width: 110px; }
    .date-day-name  { font-size: .72rem; letter-spacing: .2em; text-transform: uppercase; color: var(--gold-dk); font-weight: 600; }
    .date-day-num   { font-family: 'Playfair Display', serif; font-size: 4rem; line-height: 1; color: var(--ink); font-weight: 700; }
    .date-month-year { font-size: .82rem; color: #555; letter-spacing: .08em; text-transform: uppercase; }
    .date-right { padding-left: 40px; flex: 1; }
    .date-detail { display: flex; align-items: flex-start; gap: 14px; margin-bottom: 16px; }
    .date-detail:last-child { margin-bottom: 0; }
    .date-detail-icon  { width: 36px; height: 36px; border-radius: 8px; background: var(--ink); display: flex; align-items: center; justify-content: center; flex-shrink: 0; color: var(--gold); }
    .date-detail-label { font-size: .72rem; text-transform: uppercase; letter-spacing: .1em; color: #888; font-weight: 600; }
    .date-detail-value { font-size: .97rem; color: var(--ink); font-weight: 500; }

    /* Body */
    .card-body     { padding: 40px 64px; }
    .section-label { font-size: .72rem; letter-spacing: .18em; text-transform: uppercase; color: var(--gold-dk); font-weight: 600; margin-bottom: 14px; display: flex; align-items: center; gap: 8px; }
    .section-label::after { content: ''; flex: 1; height: 1px; background: rgba(139,105,20,0.15); }
    .body-text { font-size: .96rem; line-height: 1.7; color: #3a3a4a; margin-bottom: 36px; text-align: justify; }
    .organizer-section { display: flex; align-items: center; gap: 14px; margin-bottom: 40px; background: rgba(26,26,46,0.02); padding: 14px 20px; border-radius: 6px; border: 1px dashed var(--border); }
    .organizer-avatar  { width: 44px; height: 44px; background: var(--ink); color: var(--gold); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-family: 'Playfair Display', serif; font-weight: 700; font-size: 1.1rem; }
    .organizer-role    { font-size: .72rem; text-transform: uppercase; color: #888; letter-spacing: .05em; font-weight: 600; }
    .organizer-name    { font-size: .98rem; color: var(--ink); font-weight: 600; }
    .gold-divider { display: flex; justify-content: center; align-items: center; color: var(--gold); font-size: .75rem; margin: 32px 0; gap: 15px; opacity: 0.6; }
    .gold-divider::before, .gold-divider::after { content: ''; width: 60px; height: 1px; background: var(--gold); }

    /* RSVP zone */
    .rsvp-box    { border: 1px solid var(--border); background: #fff; padding: 28px; text-align: center; border-radius: 4px; box-shadow: 0 4px 16px rgba(201,168,76,0.06); }
    .rsvp-title  { font-family: 'Playfair Display', serif; font-size: 1.4rem; color: var(--ink); margin-bottom: 6px; font-weight: 700; }
    .rsvp-sub    { font-size: .88rem; color: #666; margin-bottom: 18px; line-height: 1.5; }
    .btn-join-direct { display: inline-flex; align-items: center; gap: 8px; background: var(--ink); color: var(--gold); text-decoration: none; padding: 12px 32px; border-radius: 6px; font-weight: 600; font-size: 0.92rem; margin-top: 15px; border: 1px solid var(--gold); transition: all 0.2s ease; }
    .btn-join-direct:hover { background: var(--gold); color: var(--ink); }
    .badge-lock { display: inline-flex; align-items: center; gap: 6px; background: #fff3e0; color: #ef6c00; border: 1px solid #ffe0b2; padding: 6px 16px; border-radius: 50px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; margin-top: 10px; }

    /* Footer */
    .card-footer   { background: #111122; padding: 24px 64px; display: flex; justify-content: space-between; align-items: center; color: rgba(255,255,255,.45); font-size: .82rem; }
    .footer-brand  { color: var(--gold); font-family: 'Playfair Display', serif; font-size: .92rem; font-weight: 600; margin-bottom: 2px; }

    /* Toast */
    .toast { position: fixed; bottom: 30px; background: var(--ink); color: var(--gold); padding: 12px 24px; border-radius: 6px; font-size: .88rem; font-weight: 500; border: 1px solid var(--gold); box-shadow: 0 4px 20px rgba(0,0,0,.3); opacity: 0; transform: translateY(10px); transition: all .3s ease; pointer-events: none; z-index: 200; }
    .toast.show { opacity: 1; transform: translateY(0); }

    @media print {
        .action-bar { display: none !important; }
        .invite-card { box-shadow: none !important; }
        body { background: #fff; }
    }
    </style>
</head>
<body>

<div class="action-bar">
    <div class="brand"><i class="fa-solid fa-gavel"></i> <span>Executive Correspondence</span></div>
    <div class="action-buttons">
        <a href="user_dashboard.php" class="btn-back"><i class="fa-solid fa-arrow-left"></i> Back to Hub</a>
        <button class="btn-share" onclick="copyLink()"><i class="fa-solid fa-share-nodes"></i> Share URL</button>
        <button class="btn-print" onclick="window.print()"><i class="fa-solid fa-print"></i> Print Blueprint</button>
    </div>
</div>

<div class="page-wrapper">
    <div class="invite-card">
        <div class="card-header">
            <div class="gold-rule">Formal Portfolio Presentation</div>
            <div class="card-category"><?= $catIcon ?> General Event</div>
            <h1 class="card-title"><?= htmlspecialchars($event['title']) ?></h1>
            <div class="card-subtitle">Official Statement of Organizational Gathering</div>
            <div class="header-meta">
                <div class="header-chip"><i class="fa-solid fa-signature"></i> <span>ID Ref: #<?= $event['id'] ?></span></div>
                <div class="header-chip"><i class="fa-solid fa-shield-halved"></i> <span>Classification: <strong><?= ucfirst($event['event_type'] ?? 'Public') ?></strong></span></div>
            </div>
        </div>

        <div class="date-showcase">
            <div class="date-left">
                <div class="date-day-name"><?= date('l', $ts) ?></div>
                <div class="date-day-num"><?= date('d', $ts) ?></div>
                <div class="date-month-year"><?= date('M Y', $ts) ?></div>
            </div>
            <div class="date-right">
                <div class="date-detail">
                    <div class="date-detail-icon"><i class="fa-regular fa-clock"></i></div>
                    <div>
                        <div class="date-detail-label">Time of the event</div>
                        <div class="date-detail-value"><?= date('h:i A', $ts) ?> Execution Windows</div>
                    </div>
                </div>
                <div class="date-detail">
                    <div class="date-detail-icon"><i class="fa-solid fa-location-dot"></i></div>
                    <div>
                        <div class="date-detail-label">Designated Location Context</div>
                        <div class="date-detail-value"><?= htmlspecialchars($event['location']) ?></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card-body">
            <div class="section-label">Description</div>
            <p class="body-text">
                <?= !empty($event['description']) ? nl2br(htmlspecialchars($event['description'])) : 'No explicit operational description statements or structural parameter boundaries provided.' ?>
            </p>

            <div class="organizer-section">
                <div class="organizer-avatar"><?= mb_strtoupper(mb_substr($event['organizer'] ?? 'U', 0, 1)) ?></div>
                <div>
                    <div class="organizer-role">Managing Representative</div>
                    <div class="organizer-name">@<?= htmlspecialchars($event['organizer']) ?></div>
                </div>
            </div>

            <div class="gold-divider"><i class="fa-solid fa-snowflake"></i></div>

            <?php if (($event['event_type'] ?? '') === 'private'): ?>
                <div class="rsvp-box" style="background:#fffcf5; border-color:#f0d98e;">
                    <i class="fa-solid fa-envelope-open-text" style="font-size:2rem; color:var(--gold-dk); margin-bottom:10px; display:block;"></i>
                    <div class="rsvp-title">Exclusive Executive Assembly</div>
                    <p class="rsvp-sub">
                        This assembly is classified strictly as a <strong>Private Invitation</strong>.<br>
                        Public structural join pathways are locked. Attendance verification is managed manually by the organizer.
                    </p>
                    <div class="badge-lock"><i class="fa-solid fa-lock"></i> Restricted Access Protocol</div>
                </div>
            <?php else: ?>
                <div class="rsvp-box">
                    <div class="rsvp-title">Public Open Enrollment Registry</div>
                    <p class="rsvp-sub">
                        Event capacity limits are monitored.
                        <?php if ($spotsLeft !== null): ?>
                            Only <strong><?= $spotsLeft ?> slots remaining</strong> out of <?= $cap ?> total available seats.
                        <?php else: ?>
                            Dynamic unconstrained volume configuration enabled.
                        <?php endif; ?>
                    </p>
                    <br>
                    <a href="user_dashboard.php" class="btn-join-direct">
                        <i class="fa-solid fa-right-to-bracket"></i> Authenticate & Secure Seat Slot Now
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <div class="card-footer">
            <div>
                <div class="footer-brand">EventHub Centralized Architecture</div>
                <div>CvSU CCAT Campus · Rosario, Cavite</div>
            </div>
            <div><i class="fa-solid fa-calendar-check"></i> <span><?= date('M j, Y', $ts) ?></span></div>
        </div>
    </div>
</div>

<div class="toast" id="toast">✓ Envelope URL string mapped to execution clipboard!</div>

<script>
function copyLink() {
    navigator.clipboard.writeText(window.location.href).then(() => {
        const t = document.getElementById('toast');
        t.classList.add('show');
        setTimeout(() => t.classList.remove('show'), 2500);
    });
}
</script>
</body>
</html>