<?php
/**
 * export_report.php — PDF & CSV Report Export
 * Generates downloadable business eventsr  from analytical data.
 * Linked from admin_dashboard.php.
 */
session_start();
include('connection.php');

if (!isset($_SESSION['user_id']) || $_SESSION['username'] !== 'admin') {
    header("Location: login.php"); exit();
}

function esc($v) { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

$format = $_GET['format'] ?? 'csv';   // csv | pdf
$scope  = $_GET['scope']  ?? 'events'; // events | analytics

/* ── CSV EXPORT ── */
if ($format === 'csv') {
    if ($scope === 'analytics') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="analytics_export_' . date('Ymd_His') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Organizer', 'Unready', 'Ready', 'Ongoing', 'Stopped', 'Finished', 'Total Events']);
        $rows = $con->query("
            SELECT u.username,
                SUM(e.status='unready')  AS unready_cnt,
                SUM(e.status='ready')    AS ready_cnt,
                SUM(e.status='ongoing')  AS ongoing_cnt,
                SUM(e.status='stopped')  AS stopped_cnt,
                SUM(e.status='finished') AS finished_cnt,
                COUNT(e.id)              AS total_cnt
            FROM users u JOIN events e ON u.id = e.user_id
            WHERE u.username != 'admin'
            GROUP BY u.id ORDER BY total_cnt DESC
        ");
        while ($r = $rows->fetch_assoc()) {
            fputcsv($out, [$r['username'], $r['unready_cnt'], $r['ready_cnt'],
                           $r['ongoing_cnt'], $r['stopped_cnt'], $r['finished_cnt'], $r['total_cnt']]);
        }
        fclose($out);
    } else {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="events_export_' . date('Ymd_His') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['ID', 'Title', 'Organizer', 'Location', 'Event Date', 'Status', 'Type', 'Attendees', 'Created At']);
        $rows = $con->query("
            SELECT e.id, e.title, u.username, e.location, e.event_date, e.status, e.event_type,
                   (SELECT COUNT(*) FROM attendees a WHERE a.event_id = e.id) AS att_count,
                   e.created_at
            FROM events e JOIN users u ON e.user_id = u.id
            ORDER BY e.id DESC
        ");
        while ($r = $rows->fetch_assoc()) {
            fputcsv($out, [$r['id'], $r['title'], $r['username'], $r['location'],
                           $r['event_date'], $r['status'], $r['event_type'], $r['att_count'], $r['created_at']]);
        }
        fclose($out);
    }
    exit();
}

if ($format === 'pdf') {
    /* Gather data */
    $totalUsers     = $con->query("SELECT COUNT(*) AS c FROM users WHERE username != 'admin'")->fetch_assoc()['c'] ?? 0;
    $totalEvents    = $con->query("SELECT COUNT(*) AS c FROM events")->fetch_assoc()['c'] ?? 0;
    $activeEvents   = $con->query("SELECT COUNT(*) AS c FROM events WHERE status IN ('ready','ongoing')")->fetch_assoc()['c'] ?? 0;
    $finishedEvents = $con->query("SELECT COUNT(*) AS c FROM events WHERE status = 'finished'")->fetch_assoc()['c'] ?? 0;

    $rows = $con->query("
        SELECT e.id, e.title, u.username, e.location, e.event_date, e.status, e.event_type,
               (SELECT COUNT(*) FROM attendees a WHERE a.event_id = e.id) AS att_count
        FROM events e JOIN users u ON e.user_id = u.id ORDER BY e.id DESC LIMIT 50
    ");

    $generated = date('F j, Y \a\t h:i A');

    header('Content-Type: text/html; charset=utf-8');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Event System Report — <?= date('Y-m-d') ?></title>
    <style>
    @media print {
        .no-print { display: none !important; }
        body { margin: 0; }
    }
    body { font-family: 'Segoe UI', Arial, sans-serif; background: #fff; color: #212529; margin: 0; padding: 30px; }
    .report-header { text-align: center; border-bottom: 3px solid #3b7ddd; padding-bottom: 20px; margin-bottom: 30px; }
    .report-header h1 { font-size: 1.6rem; color: #3b7ddd; margin: 0 0 6px; }
    .report-header p  { font-size: 0.85rem; color: #6c757d; margin: 0; }
    .summary-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 30px; }
    .summary-box { border: 1px solid #dee2e6; border-radius: 8px; padding: 16px; text-align: center; }
    .summary-box .val { font-size: 2rem; font-weight: 700; color: #3b7ddd; }
    .summary-box .lbl { font-size: 0.78rem; color: #6c757d; text-transform: uppercase; font-weight: 600; }
    h2 { font-size: 1rem; color: #495057; margin: 24px 0 12px; border-left: 4px solid #3b7ddd; padding-left: 10px; }
    table { width: 100%; border-collapse: collapse; font-size: 0.82rem; }
    th { background: #f8f9fa; color: #495057; font-weight: 700; text-transform: uppercase; font-size: 0.72rem; padding: 10px; border-bottom: 2px solid #dee2e6; text-align: left; }
    td { padding: 8px 10px; border-bottom: 1px solid #edf2f9; }
    tr:hover { background: #f8f9fa; }
    .badge { display: inline-block; padding: 2px 7px; border-radius: 4px; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; }
    .badge-ready    { background: #cff4fc; color: #055160; }
    .badge-ongoing  { background: #d1e7dd; color: #0f5132; }
    .badge-finished { background: #efe2fe; color: #52188c; }
    .badge-unready  { background: #ffc107; color: #212529; }
    .badge-stopped  { background: #6c757d; color: #fff; }
    .print-btn { background: #3b7ddd; color: #fff; border: none; padding: 10px 24px; border-radius: 6px; font-weight: 600; cursor: pointer; font-size: 0.88rem; margin-right: 10px; }
    .back-btn  { background: #6c757d; color: #fff; border: none; padding: 10px 24px; border-radius: 6px; font-weight: 600; cursor: pointer; font-size: 0.88rem; text-decoration: none; display: inline-block; }
    .footer { text-align: center; margin-top: 40px; font-size: 0.78rem; color: #adb5bd; border-top: 1px solid #edf2f9; padding-top: 16px; }
    </style>
</head>
<body>

<div class="no-print" style="margin-bottom:20px;">
    <button class="print-btn" onclick="window.print()"><i>🖨</i> Print / Save as PDF</button>
    <a href="admin_dashboard.php" class="back-btn">← Back to Dashboard</a>
</div>

<div class="report-header">
    <h1>Digital Events System — Events   Report</h1>
    <p>Generated on <?= $generated ?> &nbsp;|&nbsp; CvSU CCAT Campus, Rosario, Cavite</p>
</div>

<div class="summary-grid">
    <div class="summary-box"><div class="val"><?= $totalUsers ?></div><div class="lbl">Registered Users</div></div>
    <div class="summary-box"><div class="val"><?= $totalEvents ?></div><div class="lbl">Total Events</div></div>
    <div class="summary-box"><div class="val"><?= $activeEvents ?></div><div class="lbl">Live Events</div></div>
    <div class="summary-box"><div class="val"><?= $finishedEvents ?></div><div class="lbl">Concluded</div></div>
</div>

<h2>Event Records (Latest 50)</h2>
<table>
    <thead>
        <tr>
            <th>#</th><th>Title</th><th>Organizer</th><th>Location</th>
            <th>Date</th><th>Status</th><th>Type</th><th>Attendees</th>
        </tr>
    </thead>
    <tbody>
    <?php while ($r = $rows->fetch_assoc()): ?>
        <tr>
            <td><?= $r['id'] ?></td>
            <td><?= esc($r['title']) ?></td>
            <td><?= esc($r['username']) ?></td>
            <td><?= esc($r['location']) ?></td>
            <td><?= date('M d, Y', strtotime($r['event_date'])) ?></td>
            <td><span class="badge badge-<?= $r['status'] ?>"><?= ucfirst($r['status']) ?></span></td>
            <td><?= esc($r['event_type']) ?></td>
            <td><?= $r['att_count'] ?></td>
        </tr>
    <?php endwhile; ?>
    </tbody>
</table>

<div class="footer">
     &nbsp;·&nbsp;  Digital Event Management System &nbsp;·&nbsp; <?= date('Y') ?>
</div>
</body>
</html>
<?php
    exit();
}

/* Default: redirect back */
header("Location: admin_dashboard.php");
exit();
