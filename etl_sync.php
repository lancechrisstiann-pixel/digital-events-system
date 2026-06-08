<?php
/**
 * etl_sync.php — ETL Data Pipeline: Extract → Transform → Load (OLTP → Star Schema)
 */
session_start();
include('connection.php');

if (!isset($_SESSION['user_id']) || $_SESSION['username'] !== 'admin') {
    header("Location: login.php"); exit();
}

function esc($v) { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

$success = $error = null;
$log = ['inserted' => 0, 'updated' => 0, 'skipped' => 0];

/* ══════════════════════════════════════════════════════
   ONLY RUN PIPELINE WHEN ?run=1 IS PASSED
   (prevents auto-run on every page visit)
   ══════════════════════════════════════════════════════ */
if (isset($_GET['run']) && $_GET['run'] === '1') {

    $con->begin_transaction();

    try {

        /* ── SEED dim_status if empty (self-healing) ── */
        $con->query("
            INSERT IGNORE INTO dim_status (status_name, status_category) VALUES
            ('unready',  'inactive'),
            ('ready',    'active'),
            ('ongoing',  'active'),
            ('stopped',  'inactive'),
            ('finished', 'complete')
        ");

        /* ── STEP 1: dim_time — populate missing dates ── */
        $dates = $con->query("
            SELECT DISTINCT DATE(event_date) AS d
            FROM events
            WHERE DATE(event_date) NOT IN (SELECT full_date FROM dim_time)
        ");
        $ins_time = $con->prepare("
            INSERT IGNORE INTO dim_time
                (full_date, day_of_week, day_name, day_of_month, week_of_year,
                 month_num, month_name, quarter, year_num)
            VALUES (?,
                WEEKDAY(?) + 1,
                DAYNAME(?),
                DAY(?),
                WEEK(?, 3),
                MONTH(?),
                MONTHNAME(?),
                QUARTER(?),
                YEAR(?))
        ");
        while ($row = $dates->fetch_assoc()) {
            $d = $row['d'];
            $ins_time->bind_param("sssssssss", $d,$d,$d,$d,$d,$d,$d,$d,$d);
            $ins_time->execute();
        }
        $ins_time->close();

        /* ── STEP 2: dim_location — populate missing locations ── */
        $locs = $con->query("
            SELECT DISTINCT TRIM(location) AS loc
            FROM events
            WHERE TRIM(location) != ''
              AND TRIM(location) NOT IN (SELECT location_name FROM dim_location)
        ");
        $ins_loc = $con->prepare("INSERT IGNORE INTO dim_location (location_name) VALUES (?)");
        while ($l = $locs->fetch_assoc()) {
            $ins_loc->bind_param("s", $l['loc']);
            $ins_loc->execute();
        }
        /* Ensure 'Unknown' fallback exists */
        $con->query("INSERT IGNORE INTO dim_location (location_name) VALUES ('Unknown')");
        $ins_loc->close();

        /* ── STEP 3: dim_user — populate missing users ── */
        $users = $con->query("
            SELECT u.id, u.username, DATE(u.created_at) AS reg_date
            FROM users u
            WHERE u.username != 'admin'
              AND u.id NOT IN (SELECT oltp_user_id FROM dim_user)
        ");
        $ins_user = $con->prepare("
            INSERT IGNORE INTO dim_user (oltp_user_id, username, registered_date)
            VALUES (?, ?, ?)
        ");
        while ($u = $users->fetch_assoc()) {
            $ins_user->bind_param("iss", $u['id'], $u['username'], $u['reg_date']);
            $ins_user->execute();
        }
        $ins_user->close();

        /* ── STEP 4: fact_events — upsert ── */

        /* Fetch all events with their dimension lookups done in PHP */
        $events = $con->query("
            SELECT
                e.id          AS event_id,
                e.user_id,
                DATE(e.event_date)  AS evt_date,
                TRIM(e.location)    AS loc,
                e.status,
                DATEDIFF(DATE(e.event_date), DATE(e.created_at)) AS days_lead
            FROM events e
            JOIN users u ON u.id = e.user_id
            WHERE u.username != 'admin'
        ");

        /* Lookup helpers — returns the surrogate key or NULL */
        $qTime   = $con->prepare("SELECT time_key     FROM dim_time     WHERE full_date    = ? LIMIT 1");
        $qLoc    = $con->prepare("SELECT location_key FROM dim_location WHERE location_name = ? LIMIT 1");
        $qUser   = $con->prepare("SELECT user_key     FROM dim_user     WHERE oltp_user_id = ? LIMIT 1");
        $qStatus = $con->prepare("SELECT status_key   FROM dim_status   WHERE status_name  = ? LIMIT 1");

        /* INSERT new fact row */
        $ins_fact = $con->prepare("
            INSERT IGNORE INTO fact_events
                (oltp_event_id, time_key, location_key, user_key, status_key,
                 event_count, days_until_event)
            VALUES (?, ?, ?, ?, ?, 1, ?)
        ");

        /* UPDATE status when it changes */
        $upd_fact = $con->prepare("
            UPDATE fact_events
            SET status_key   = ?,
                snapshot_date = NOW()
            WHERE oltp_event_id = ?
              AND status_key   != ?
        ");

        while ($ev = $events->fetch_assoc()) {

            /* ── Resolve time_key ── */
            $qTime->bind_param("s", $ev['evt_date']);
            $qTime->execute();
            $r = $qTime->get_result()->fetch_assoc();
            if (!$r) { $log['skipped']++; continue; }
            $time_key = (int)$r['time_key'];

            /* ── Resolve location_key ── */
            $locName = ($ev['loc'] !== '') ? $ev['loc'] : 'Unknown';
            $qLoc->bind_param("s", $locName);
            $qLoc->execute();
            $r = $qLoc->get_result()->fetch_assoc();
            if (!$r) { $log['skipped']++; continue; }
            $location_key = (int)$r['location_key'];

            /* ── Resolve user_key ── */
            $qUser->bind_param("i", $ev['user_id']);
            $qUser->execute();
            $r = $qUser->get_result()->fetch_assoc();
            if (!$r) { $log['skipped']++; continue; }
            $user_key = (int)$r['user_key'];

            /* ── Resolve status_key ── */
            $qStatus->bind_param("s", $ev['status']);
            $qStatus->execute();
            $r = $qStatus->get_result()->fetch_assoc();
            if (!$r) { $log['skipped']++; continue; }
            $status_key = (int)$r['status_key'];

            /* ── days_lead: safely handle NULL ── */
            $days_lead = ($ev['days_lead'] !== null) ? (int)$ev['days_lead'] : null;

            /* ── Try INSERT ── */
            $ins_fact->bind_param(
                "iiiiis",
                $ev['event_id'],
                $time_key,
                $location_key,
                $user_key,
                $status_key,
                $days_lead          /* 's' safely passes both int strings and NULL */
            );
            $ins_fact->execute();

            if ($ins_fact->affected_rows === 1) {
                $log['inserted']++;
            } else {
                /* Row already exists — update status if changed */
                $upd_fact->bind_param("iii", $status_key, $ev['event_id'], $status_key);
                $upd_fact->execute();
                if ($upd_fact->affected_rows > 0) {
                    $log['updated']++;
                }
            }
        }

        $qTime->close();
        $qLoc->close();
        $qUser->close();
        $qStatus->close();
        $ins_fact->close();
        $upd_fact->close();

        /* ── STEP 5: Audit log ── */
        $msg = "Pipeline OK — {$log['inserted']} inserted, {$log['updated']} updated, {$log['skipped']} skipped.";
        $stmt = $con->prepare("
            INSERT INTO etl_log (rows_inserted, rows_updated, status, message)
            VALUES (?, ?, 'success', ?)
        ");
        $stmt->bind_param("iis", $log['inserted'], $log['updated'], $msg);
        $stmt->execute();
        $stmt->close();

        $con->commit();
        $success = $msg;

    } catch (Exception $e) {
        $con->rollback();
        $errMsg = $e->getMessage();
        /* Try to log — use a fresh statement outside the rolled-back transaction */
        try {
            $stmt = $con->prepare("
                INSERT INTO etl_log (rows_inserted, rows_updated, status, message)
                VALUES (0, 0, 'error', ?)
            ");
            $stmt->bind_param("s", $errMsg);
            $stmt->execute();
            $stmt->close();
        } catch (Exception $ignored) {}
        $error = "Pipeline failed: " . $errMsg;
    }

} // end if ?run=1

/* ── ETL LOG HISTORY ── */
$history = $con->query("SELECT * FROM etl_log ORDER BY run_at DESC LIMIT 10");

/* ── Live counts for the info panel ── */
$factCount = (int)($con->query("SELECT COUNT(*) AS c FROM fact_events")->fetch_assoc()['c'] ?? 0);
$oltpCount = (int)($con->query("SELECT COUNT(*) AS c FROM events")->fetch_assoc()['c'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>ETL Pipeline — EventHub Admin</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<div class="app-container">
    <?php include('admin_sidebar.php'); ?>

    <main class="main-content">
        <div class="topbar">
            <div class="topbar-left">
                <h1>ETL Data Pipeline</h1>
                <p>Extract → Transform → Load &nbsp;·&nbsp; OLTP to Star Schema Sync</p>
            </div>
            <div class="topbar-actions">
                <!-- Run button passes ?run=1 to trigger the pipeline -->
                <a href="etl_sync.php?run=1" class="etl-run-btn">
                    <i class="fa-solid fa-rotate"></i> Run Pipeline Now
                </a>
            </div>
        </div>

        <!-- Pipeline Steps -->
        <div class="pipeline-flow">
            <div class="pipeline-step">
                <div class="step-icon extract"><i class="fa-solid fa-database"></i></div>
                <div class="step-num">Step 1–3</div>
                <div class="step-name">Extract</div>
            </div>
            <div class="pipeline-step">
                <div class="step-icon transform"><i class="fa-solid fa-arrows-rotate"></i></div>
                <div class="step-num">Step 4a</div>
                <div class="step-name">Transform</div>
            </div>
            <div class="pipeline-step">
                <div class="step-icon load"><i class="fa-solid fa-layer-group"></i></div>
                <div class="step-num">Step 4b</div>
                <div class="step-name">Load Dims</div>
               
            </div>
            <div class="pipeline-step">
                <div class="step-icon load"><i class="fa-solid fa-table"></i></div>
                <div class="step-num">Step 4c</div>
                <div class="step-name">Load Facts</div>
            </div>
            <div class="pipeline-step">
                <div class="step-icon audit"><i class="fa-solid fa-clipboard-check"></i></div>
                <div class="step-num">Step 5</div>
                <div class="step-name">Audit Log</div>
            </div>
        </div>

        <!-- Result banners (only shown after a run) -->
        <?php if (!empty($success)): ?>
        <div class="result-banner success">
            <div class="result-banner-icon"><i class="fa-solid fa-check"></i></div>
            <div>
                <div class="result-banner-msg"><?= esc($success) ?></div>
                <div class="result-banner-sub">Star Schema is now in sync. analytics reflect the latest data.</div>
            </div>
        </div>
        <div class="metric-row">
            <div class="metric-box">
                <div class="metric-val" style="color:#16a34a;"><?= $log['inserted'] ?></div>
                <div class="metric-lbl"><i class="fa-solid fa-plus" style="color:#16a34a;margin-right:4px;"></i>New Fact Rows</div>
            </div>
            <div class="metric-box">
                <div class="metric-val" style="color:#d97706;"><?= $log['updated'] ?></div>
                <div class="metric-lbl"><i class="fa-solid fa-arrow-rotate-right" style="color:#d97706;margin-right:4px;"></i>Status Updates</div>
            </div>
            <div class="metric-box">
                <div class="metric-val" style="color:#6366f1;"><?= $log['inserted'] + $log['updated'] ?></div>
                <div class="metric-lbl"><i class="fa-solid fa-sigma" style="color:#6366f1;margin-right:4px;"></i>Total Processed</div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
        <div class="result-banner error">
            <div class="result-banner-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
            <div>
                <div class="result-banner-msg"><?= esc($error) ?></div>
                <div class="result-banner-sub">Transaction rolled back. No data was modified.</div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Info + Schema cards -->
        <div class="info-grid">
            <div class="info-card">
                <div class="info-card-title"><i class="fa-solid fa-circle-info"></i> How It Works</div>
                <div class="info-item"><div class="info-dot"></div><div class="info-text"><strong>Extract:</strong> Reads all non-admin event and user records from the live OLTP database.</div></div>
                <div class="info-item"><div class="info-dot"></div><div class="info-text"><strong>Transform:</strong> Resolves each record's dimensions (time, location, user, status) in PHP.</div></div>
                <div class="info-item"><div class="info-dot"></div><div class="info-text"><strong>Load:</strong> Inserts new fact rows; updates status for existing ones. Skips any record with an unresolvable key.</div></div>
                <div class="info-item"><div class="info-dot" style="background:#ef4444;"></div><div class="info-text"><strong>Safety:</strong> Everything runs inside a database transaction.</div></div>
            </div>
            <div class="info-card">
                <div class="info-card-title"><i class="fa-solid fa-star"></i> Star Schema Status</div>
                <div class="info-item"><div class="info-dot" style="background:#2563eb;"></div><div class="info-text"><strong>dim_time</strong> — Date hierarchy: day → week → month → quarter → year</div></div>
                <div class="info-item"><div class="info-dot" style="background:#16a34a;"></div><div class="info-text"><strong>dim_location</strong> — Venue dimension with surrogate key</div></div>
                <div class="info-item"><div class="info-dot" style="background:#d97706;"></div><div class="info-text"><strong>dim_user</strong> — Organizer dimension decoupled from OLTP user IDs</div></div>
                <div class="info-item"><div class="info-dot" style="background:#7c3aed;"></div><div class="info-text"><strong>dim_status</strong> — Event lifecycle statuses (auto-seeded)</div></div>
                <div class="info-item"><div class="info-dot" style="background:#6366f1;"></div>
                    <div class="info-text">
                        <strong>fact_events</strong> — Central fact table &nbsp;
                        <span style="background:#e0e7ff;color:#4338ca;padding:2px 8px;border-radius:5px;font-size:.75rem;font-weight:700;">
                            <?= $factCount ?> / <?= $oltpCount ?> events synced
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Run History -->
        <div class="history-card">
            <div class="history-header">
                <div class="history-header-title"><i class="fa-solid fa-list-check"></i> Pipeline Run History (Last 10)</div>
                <span style="font-size:.75rem;color:#94a3b8;">Most recent first</span>
            </div>
            <table class="ht">
                <thead>
                    <tr>
                        <th>Timestamp</th>
                        <th>Status</th>
                        <th style="text-align:center;">Inserted</th>
                        <th style="text-align:center;">Updated</th>
                        <th>Message</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($history && $history->num_rows > 0): ?>
                    <?php while ($r = $history->fetch_assoc()): ?>
                    <tr>
                        <td style="color:#64748b;white-space:nowrap;">
                            <i class="fa-regular fa-clock" style="margin-right:5px;color:#94a3b8;"></i>
                            <?= esc($r['run_at']) ?>
                        </td>
                        <td>
                            <?= $r['status'] === 'success'
                                ? '<span class="run-status-ok">Success</span>'
                                : '<span class="run-status-err">Failed</span>' ?>
                        </td>
                        <td style="text-align:center;font-weight:700;color:#16a34a;"><?= (int)$r['rows_inserted'] ?></td>
                        <td style="text-align:center;font-weight:700;color:#d97706;"><?= (int)$r['rows_updated'] ?></td>
                        <td style="font-size:.8rem;color:#64748b;"><?= esc($r['message']) ?></td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5" class="no-records">
                            <i class="fa-solid fa-rotate" style="font-size:1.8rem;display:block;margin-bottom:8px;opacity:.3;"></i>
                            No pipeline runs yet. Click <strong>Run Pipeline Now</strong> to sync.
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

    </main>
</div>
</body>
</html>
