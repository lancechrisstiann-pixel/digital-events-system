<?php

session_start();
include('connection.php');

if (!isset($_SESSION['user_id']) || $_SESSION['username'] !== 'admin') {
    header("Location: login.php"); exit();
}

function esc($v) { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

/* ── Active filter values from GET params ── */
$year   = isset($_GET['pivot_year'])   ? intval($_GET['pivot_year'])   : (int)date('Y');
$status = isset($_GET['slice_status']) ? trim($_GET['slice_status'])   : 'all';
$loc    = isset($_GET['dice_loc'])     ? trim($_GET['dice_loc'])       : 'all';

/* ── Filter dropdown source data (always from OLTP — never stale) ── */
$years     = $con->query("SELECT DISTINCT YEAR(event_date) AS yr FROM events ORDER BY yr DESC");
$statuses  = $con->query("SELECT DISTINCT status FROM events ORDER BY status");
$locations = $con->query("SELECT DISTINCT TRIM(location) AS loc FROM events WHERE location != '' ORDER BY loc");

/* ── Check whether Star Schema has been populated by ETL ── */
$hasStar = false;
$chk = $con->query("SELECT COUNT(*) AS c FROM fact_events");
if ($chk && ($chk->fetch_assoc()['c'] ?? 0) > 0) $hasStar = true;


/* Star Schema filter fragments */
$starYearCond   = "dt.year_num = " . intval($year);
$starStatusCond = ($status !== 'all') ? "ds.status_name = '" . $con->real_escape_string($status) . "'" : null;
$starLocCond    = ($loc    !== 'all') ? "dl.location_name = '" . $con->real_escape_string($loc)    . "'" : null;

/* OLTP fallback filter fragments */
$oltpYearCond   = "YEAR(event_date) = " . intval($year);
$oltpStatusCond = ($status !== 'all') ? "e.status = '"             . $con->real_escape_string($status) . "'" : null;
$oltpLocCond    = ($loc    !== 'all') ? "TRIM(e.location) = '"     . $con->real_escape_string($loc)    . "'" : null;

function buildWhere(array $conds, string $prefix = 'WHERE'): string {
    $conds = array_filter($conds); // drop nulls
    if (empty($conds)) return '';
    return $prefix . ' ' . implode(' AND ', $conds);
}


/* ════════════════════════════════════════════════════════════════
 *  1. SLICE + DRILL-DOWN — daily event count for the active year
 *     Filters: year, status, location
 * ════════════════════════════════════════════════════════════════ */
$drillLabels = $drillCounts = [];

if ($hasStar) {
    $where = buildWhere([$starYearCond, $starStatusCond, $starLocCond]);
    $res = $con->query("
        SELECT dt.full_date AS d, COUNT(DISTINCT f.fact_id) AS c
        FROM fact_events    f
        JOIN dim_time       dt ON dt.time_key     = f.time_key
        JOIN dim_status     ds ON ds.status_key   = f.status_key
        JOIN dim_location   dl ON dl.location_key = f.location_key
        $where
        GROUP BY dt.full_date
        ORDER BY dt.full_date
    ");
} else {
    $where = buildWhere([$oltpYearCond, $oltpStatusCond, $oltpLocCond]);
    $res = $con->query("
        SELECT DATE(e.event_date) AS d, COUNT(*) AS c
        FROM events e
        $where
        GROUP BY DATE(e.event_date)
        ORDER BY e.event_date
    ");
}
if ($res) while ($r = $res->fetch_assoc()) {
    $drillLabels[] = $r['d'];
    $drillCounts[] = (int)$r['c'];
}

$rollupRows = $rollupLabels = $rollupData = [];

if ($hasStar) {
    /* Apply status + location filters inside the ROLLUP subquery */
    $innerConds = array_filter([$starStatusCond, $starLocCond]);
    $innerJoins = '';
    $innerWhere = '';
    if ($starStatusCond || $starLocCond) {
        /* Need to join dimension tables inside the subquery to filter */
        $innerJoins = "
            JOIN dim_status   ds ON ds.status_key   = f.status_key
            JOIN dim_location dl ON dl.location_key = f.location_key";
        $innerWhere = buildWhere(array_filter([$starStatusCond, $starLocCond]));
    }
    $res = $con->query("
        SELECT * FROM (
            SELECT dt.year_num   AS year,
                   dt.quarter    AS quarter,
                   dt.month_num  AS month_num,
                   dt.month_name AS month_name,
                   COUNT(DISTINCT f.fact_id) AS c
            FROM fact_events f
            JOIN dim_time dt ON dt.time_key = f.time_key
            $innerJoins
            $innerWhere
            GROUP BY dt.year_num, dt.quarter, dt.month_num, dt.month_name WITH ROLLUP
        ) sub
        WHERE sub.year IS NOT NULL
        LIMIT 48
    ");
} else {
    /* OLTP path — apply status + location filters inside subquery */
    $innerWhere = buildWhere(array_filter([$oltpStatusCond, $oltpLocCond]));
    $res = $con->query("
        SELECT * FROM (
            SELECT YEAR(e.event_date)      AS year,
                   QUARTER(e.event_date)   AS quarter,
                   MONTH(e.event_date)     AS month_num,
                   MONTHNAME(e.event_date) AS month_name,
                   COUNT(*) AS c
            FROM events e
            $innerWhere
            GROUP BY YEAR(e.event_date), QUARTER(e.event_date),
                     MONTH(e.event_date), MONTHNAME(e.event_date) WITH ROLLUP
        ) sub
        WHERE sub.year IS NOT NULL
        LIMIT 48
    ");
}
if ($res) while ($r = $res->fetch_assoc()) $rollupRows[] = $r;

/* Sort in PHP (ORDER BY is forbidden inside WITH ROLLUP in MariaDB) */
usort($rollupRows, function($a, $b) {
    if ($b['year']     !== $a['year'])     return $b['year'] - $a['year'];
    if ($a['quarter']  === null)           return 1;
    if ($b['quarter']  === null)           return -1;
    if ($a['quarter']  !== $b['quarter'])  return $a['quarter'] - $b['quarter'];
    if ($a['month_num'] === null)          return 1;
    if ($b['month_num'] === null)          return -1;
    return $a['month_num'] - $b['month_num'];
});

/* Extract only the month-level rows for the selected year */
foreach ($rollupRows as $r) {
    if ($r['year'] == $year && $r['month_num'] !== null) {
        $rollupLabels[] = $r['month_name'];
        $rollupData[]   = (int)$r['c'];
    }
}

$diceWhere = buildWhere(array_filter([
    "u.username != 'admin'",
    "YEAR(e.event_date) = " . intval($year),
    ($loc !== 'all') ? "TRIM(e.location) = '" . $con->real_escape_string($loc) . "'" : null,
    /* If a specific status is selected, only count events of that status as total */
    ($status !== 'all') ? "e.status = '" . $con->real_escape_string($status) . "'" : null,
]));

$matrix = $con->query("
    SELECT u.username,
        SUM(e.status = 'unready')  AS unready_cnt,
        SUM(e.status = 'ready')    AS ready_cnt,
        SUM(e.status = 'ongoing')  AS ongoing_cnt,
        SUM(e.status = 'stopped')  AS stopped_cnt,
        SUM(e.status = 'finished') AS finished_cnt,
        COUNT(e.id)                AS total_cnt
    FROM users u
    JOIN events e ON u.id = e.user_id
    $diceWhere
    GROUP BY u.id
    HAVING total_cnt > 0
    ORDER BY total_cnt DESC
");



$locLabels = $locCounts = [];
$venueWhere = buildWhere(array_filter([
    "e.location != ''",
    "YEAR(e.event_date) = " . intval($year),
    ($status !== 'all') ? "e.status = '" . $con->real_escape_string($status) . "'" : null,
]));
$res = $con->query("
    SELECT TRIM(e.location) AS location, COUNT(*) AS c
    FROM events e
    $venueWhere
    GROUP BY TRIM(e.location)
    ORDER BY c DESC
    LIMIT 5
");
if ($res) while ($r = $res->fetch_assoc()) {
    $locLabels[] = $r['location'] ?: 'Unspecified';
    $locCounts[] = (int)$r['c'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>OLAP Analytics — Admin</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
<div class="app-container">
    <?php include('admin_sidebar.php'); ?>

    <main class="main-content">

        <!-- ── Top bar ── -->
        <div class="topbar">
            <div class="topbar-left">
                <h1>OLAP Analytics</h1>
                <p>Slice · Roll-up · Drill-down · Dice</p>
            </div>
            <div class="topbar-actions">
                <?php if (!$hasStar): ?>
                    <span class="alert alert-w" style="margin:0;padding:7px 12px;">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                        ETL not run — <a href="etl_sync.php" style="color:#92400e;font-weight:700;">Sync now</a>
                    </span>
                <?php else: ?>
                    <span class="alert alert-s" style="margin:0;padding:7px 12px;">
                        <i class="fa-solid fa-check-circle"></i> Star Schema active
                    </span>
                <?php endif; ?>
                <a href="export_report.php?format=csv&scope=analytics" class="export-btn">
                    <i class="fa-solid fa-file-csv"></i> Export CSV
                </a>
            </div>
        </div>

        <!-- ── Slice & Dice filter bar ── -->
        <form method="GET" class="pivot-bar">
            <label><i class="fa-solid fa-calendar"></i> Year (Slice):</label>
            <select name="pivot_year" onchange="this.form.submit()">
                <?php $years->data_seek(0); while ($y = $years->fetch_assoc()): ?>
                    <option value="<?= $y['yr'] ?>" <?= $year == $y['yr'] ? 'selected' : '' ?>><?= $y['yr'] ?></option>
                <?php endwhile; ?>
            </select>

            <label><i class="fa-solid fa-filter"></i> Status (Slice):</label>
            <select name="slice_status" onchange="this.form.submit()">
                <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>All Statuses</option>
                <?php $statuses->data_seek(0); while ($st = $statuses->fetch_assoc()): ?>
                    <option value="<?= esc($st['status']) ?>" <?= $status === $st['status'] ? 'selected' : '' ?>><?= ucfirst($st['status']) ?></option>
                <?php endwhile; ?>
            </select>

            <label><i class="fa-solid fa-map-pin"></i> Location (Dice):</label>
            <select name="dice_loc" onchange="this.form.submit()">
                <option value="all" <?= $loc === 'all' ? 'selected' : '' ?>>All Locations</option>
                <?php $locations->data_seek(0); while ($l = $locations->fetch_assoc()): ?>
                    <option value="<?= esc($l['loc']) ?>" <?= $loc === $l['loc'] ? 'selected' : '' ?>><?= esc($l['loc']) ?></option>
                <?php endwhile; ?>
            </select>
        </form>

        <div class="olap-layout">

            <!-- ── Row 1: Slice/Drill-down + Top Venues ── -->
            <div class="row-grid">

                <!-- Slice + Drill-down: daily distribution filtered by year/status/location -->
                <div class="olap-card">
                    <div class="olap-card-header">
                        <span class="olap-card-title"><i class="fa-regular fa-clock"></i> Daily Events</span>
                        <span class="operation-tag">Slice + Drill-down</span>
                    </div>
                    <?php if ($drillLabels): ?>
                        <div class="chart-box"><canvas id="drillDownChart"></canvas></div>
                    <?php else: ?>
                        <div class="no-records">No records for selected filters.</div>
                    <?php endif; ?>
                </div>

                <!-- Geographic: top 5 venues filtered by year + status -->
                <div class="olap-card">
                    <div class="olap-card-header">
                        <span class="olap-card-title"><i class="fa-solid fa-map-location-dot"></i> Top Venues</span>
                        <span class="operation-tag">Geographic</span>
                    </div>
                    <?php if ($locLabels): ?>
                        <div class="chart-box"><canvas id="locationDensityChart"></canvas></div>
                    <?php else: ?>
                        <div class="no-records">No location data for selected filters.</div>
                    <?php endif; ?>
                </div>

            </div><!-- /row-grid -->

            <!-- ── Row 2: Roll-up monthly bar chart ── -->
            <!-- Roll-up: monthly aggregation for selected year, filtered by status + location -->
            <div class="olap-card">
                <div class="olap-card-header">
                    <span class="olap-card-title">
                        <i class="fa-solid fa-layer-group"></i> Monthly Roll-Up (<?= $year ?>)
                        <?php if ($status !== 'all'): ?>
                            <small style="font-weight:400; color:#94a3b8; font-size:.75rem;">
                                — <?= ucfirst(esc($status)) ?>
                            </small>
                        <?php endif; ?>
                        <?php if ($loc !== 'all'): ?>
                            <small style="font-weight:400; color:#94a3b8; font-size:.75rem;">
                                @ <?= esc($loc) ?>
                            </small>
                        <?php endif; ?>
                    </span>
                    <span class="operation-tag">Roll-up (WITH ROLLUP)</span>
                </div>
                <?php if (!empty($rollupLabels)): ?>
                    <div class="chart-box"><canvas id="rollupChart"></canvas></div>
                <?php else: ?>
                    <div class="no-records">No roll-up data for the selected filters.</div>
                <?php endif; ?>
            </div>

            <!-- ── Row 3: Dice matrix ── -->
            <!--
                Dice: organizer × status cross-tabulation.
                Filtered by year + location + status
            -->
            <div class="olap-card">
                <div class="olap-card-header">
                    <span class="olap-card-title">
                        <i class="fa-solid fa-table-cells"></i> Organizer × Status (Dice)
                        <?php if ($loc !== 'all' || $status !== 'all'): ?>
                            <small style="font-weight:400; color:#94a3b8; font-size:.75rem;">
                                — filtered view
                            </small>
                        <?php endif; ?>
                    </span>
                    <span class="operation-tag">Dice (Multi-Dimension)</span>
                </div>
                <div style="overflow-x:auto;">
                    <table class="matrix-table">
                        <thead>
                            <tr>
                                <th style="text-align:left;padding-left:20px;">Organizer</th>
                                <th>Unready</th>
                                <th>Ready</th>
                                <th>Ongoing</th>
                                <th>Stopped</th>
                                <th>Finished</th>
                                <th class="total-cell">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ($matrix && $matrix->num_rows > 0): ?>
                            <?php while ($row = $matrix->fetch_assoc()): ?>
                            <tr>
                                <td style="text-align:left;font-weight:600;padding-left:20px;"><?= esc($row['username']) ?></td>
                                <td><?= (int)$row['unready_cnt'] ?></td>
                                <td><?= (int)$row['ready_cnt'] ?></td>
                                <td><?= (int)$row['ongoing_cnt'] ?></td>
                                <td><?= (int)$row['stopped_cnt'] ?></td>
                                <td><?= (int)$row['finished_cnt'] ?></td>
                                <td class="total-cell"><?= (int)$row['total_cnt'] ?></td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" class="no-records">
                                    No organizers found for the selected filters.
                                </td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div><!-- /olap-layout -->
    </main>
</div>

<!-- ── Chart.js initialisation ── -->
<script>
<?php if ($drillLabels): ?>
/* Slice + Drill-down: daily event line chart */
new Chart(document.getElementById('drillDownChart'), {
    type: 'line',
    data: {
        labels: <?= json_encode($drillLabels) ?>,
        datasets: [{
            label: 'Events',
            data: <?= json_encode($drillCounts) ?>,
            borderColor: '#6366f1',
            backgroundColor: 'rgba(99,102,241,0.05)',
            borderWidth: 2, pointRadius: 4, fill: true, tension: 0.1
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }
    }
});
<?php endif; ?>

<?php if ($locLabels): ?>
/* Geographic: top venues polar chart */
new Chart(document.getElementById('locationDensityChart'), {
    type: 'polarArea',
    data: {
        labels: <?= json_encode($locLabels) ?>,
        datasets: [{
            data: <?= json_encode($locCounts) ?>,
            backgroundColor: [
                'rgba(99,102,241,.7)', 'rgba(16,185,129,.7)',
                'rgba(245,158,11,.7)', 'rgba(239,68,68,.7)',
                'rgba(139,92,246,.7)'
            ]
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, padding: 10 } } },
        scales: { r: { ticks: { stepSize: 1 }, suggestedMin: 0 } }
    }
});
<?php endif; ?>

<?php if (!empty($rollupLabels)): ?>
/* Roll-up: monthly bar chart */
new Chart(document.getElementById('rollupChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($rollupLabels) ?>,
        datasets: [{
            label: 'Events (<?= $year ?>)',
            data: <?= json_encode($rollupData) ?>,
            backgroundColor: 'rgba(99,102,241,.75)',
            borderRadius: 6
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }
    }
});
<?php endif; ?>
</script>
</body>
</html>