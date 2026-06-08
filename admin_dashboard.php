<?php

session_start();
include('connection.php');

if (!isset($_SESSION['user_id']) || $_SESSION['username'] !== 'admin') {
    header("Location: login.php"); exit();
}

function q($con, $sql) { return $con->query($sql)->fetch_assoc(); }
function esc($v) { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

$totalUsers     = q($con, "SELECT COUNT(*) AS total FROM users WHERE username != 'admin'")['total'] ?? 0;
$totalEvents    = q($con, "SELECT COUNT(*) AS total FROM events")['total'] ?? 0;
$activeEvents   = q($con, "SELECT COUNT(*) AS total FROM events WHERE status IN ('ready','ongoing')")['total'] ?? 0;
$finishedEvents = q($con, "SELECT COUNT(*) AS total FROM events WHERE status = 'finished'")['total'] ?? 0;

$activeUserRow   = q($con, "SELECT u.username, COUNT(e.id) AS total_events FROM users u JOIN events e ON u.id = e.user_id WHERE u.username != 'admin' GROUP BY u.id ORDER BY total_events DESC LIMIT 1");
$mostActiveUser  = $activeUserRow['username']     ?? 'No Activity';
$mostActiveCount = $activeUserRow['total_events'] ?? 0;

/* Last ETL run — used to show the sync status banner at the top */
$lastEtl = q($con, "SELECT run_at, status FROM etl_log ORDER BY run_at DESC LIMIT 1");

/* Monthly bookings — data for the line chart (label = "Jan 2025", count = events that month) */
$rollupLabels = $rollupCounts = [];
$res = $con->query("SELECT DATE_FORMAT(event_date,'%b %Y') AS m, COUNT(*) AS c FROM events GROUP BY YEAR(event_date), MONTH(event_date) ORDER BY event_date");
while ($r = $res->fetch_assoc()) { $rollupLabels[] = $r['m']; $rollupCounts[] = (int)$r['c']; }

/* Status breakdown — data for the pie chart */
$sliceLabels = $sliceCounts = [];
$res = $con->query("SELECT status, COUNT(*) AS c FROM events GROUP BY status");
while ($r = $res->fetch_assoc()) { $sliceLabels[] = ucfirst($r['status']); $sliceCounts[] = (int)$r['c']; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Dashboard — Event Mangement</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
<div class="app-container">
    <?php include('admin_sidebar.php'); ?>

    <main class="main-content">
        <div class="topbar">
            <div class="topbar-left">
                <h1>System Overview</h1>
                <p><?= date('l, F j, Y') ?></p>
            </div>
            <div class="topbar-actions">
                <a href="export_report.php?format=csv&scope=events" class="export-btn"><i class="fa-solid fa-file-csv"></i> Export CSV</a>
                <a href="export_report.php?format=pdf" class="export-btn" style="background:#ef4444;" target="_blank"><i class="fa-solid fa-file-pdf"></i> PDF Report</a>
                <a href="olap_analytics.php" class="btn-primary"><i class="fa-solid fa-chart-pie"></i> OLAP Analytics</a>
            </div>
        </div>

        <?php if ($lastEtl): ?>
        <div class="etl-status" style="margin-bottom:18px;">
            <span class="<?= $lastEtl['status'] === 'success' ? 'etl-dot-ok' : 'etl-dot-err' ?>"></span>
            Last ETL sync: <?= esc($lastEtl['run_at']) ?> — <a href="etl_sync.php" style="color:#6366f1;font-weight:600;">Run again</a>
        </div>
        <?php else: ?>
        <div class="etl-status" style="margin-bottom:18px;">
            <span class="etl-dot-err"></span>
            ETL never run — <a href="etl_sync.php" style="color:#6366f1;font-weight:600;">Sync now to enable analytics</a>
        </div>
        <?php endif; ?>

        <section class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Registered Users</div>
                <div class="stat-number"><?= $totalUsers ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Total Events</div>
                <div class="stat-number"><?= $totalEvents ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Live Events</div>
                <div class="stat-number"><?= $activeEvents ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Concluded</div>
                <div class="stat-number"><?= $finishedEvents ?></div>
            </div>
            <div class="stat-card stat-card-accent">
                <div class="stat-label">Most Active User</div>
                <div class="stat-number" style="font-size:1.4rem; margin-top:10px;" title="<?= esc($mostActiveUser) ?>">
                    <i class="fa-solid fa-crown" style="color:#f59e0b; font-size:1rem; margin-right:4px;"></i><?= esc($mostActiveUser) ?>
                </div>
                <div class="stat-meta"><?= $mostActiveCount ?> events</div>
            </div>
        </section>

        <div class="olap-grid">
            <div class="olap-card">
                <div class="olap-header">
                    <span class="olap-title"><i class="fa-solid fa-chart-line" style="color:#6366f1;margin-right:6px;"></i>Monthly Bookings</span>
                    <span class="olap-badge">Trend</span>
                </div>
                <?php if ($rollupLabels): ?>
                    <div class="chart-container"><canvas id="rollupChart"></canvas></div>
                <?php else: ?>
                    <div class="no-data"><i class="fa-solid fa-chart-line"></i> No data yet.</div>
                <?php endif; ?>
            </div>
            <div class="olap-card">
                <div class="olap-header">
                    <span class="olap-title"><i class="fa-solid fa-circle-half-stroke" style="color:#6366f1;margin-right:6px;"></i>Status Breakdown</span>
                    <span class="olap-badge">Status</span>
                </div>
                <?php if ($sliceLabels): ?>
                    <div class="chart-container" style="max-height:220px;"><canvas id="sliceChart"></canvas></div>
                <?php else: ?>
                    <div class="no-data">No events recorded.</div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>

<script>
<?php if ($rollupLabels): ?>
new Chart(document.getElementById('rollupChart'), {
    type: 'line',
    data: {
        labels: <?= json_encode($rollupLabels) ?>,
        datasets: [{ label: 'Events', data: <?= json_encode($rollupCounts) ?>,
            borderColor: '#6366f1', backgroundColor: 'rgba(99,102,241,0.05)', fill: true, tension: 0.3 }]
    },
    options: { responsive: true, maintainAspectRatio: false }
});
<?php endif; ?>
<?php if ($sliceLabels): ?>
new Chart(document.getElementById('sliceChart'), {
    type: 'pie',
    data: {
        labels: <?= json_encode($sliceLabels) ?>,
        datasets: [{ data: <?= json_encode($sliceCounts) ?>,
            backgroundColor: ['#94a3b8','#93c5fd','#6ee7b7','#fca5a5','#c4b5fd'] }]
    },
    options: { responsive: true, maintainAspectRatio: false }
});
<?php endif; ?>
</script>
</body>
</html>
