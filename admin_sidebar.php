<?php
/**
 * admin_sidebar.php — Shared sidebar included on every admin page.
 */
?>

<aside class="sidebar">
    <div class="sidebar-brand">
        <div class="brand-icon"><i class="fa-solid fa-user-shield"></i></div>
        <div>
            <h2>EventManagement</h2>
            <div class="brand-sub">Admin Panel</div>
        </div>
    </div>
    <div class="sidebar-section">Navigation</div>
    <nav class="sidebar-menu">
        <a href="admin_dashboard.php" class="menu-item <?= basename($_SERVER['PHP_SELF']) === 'admin_dashboard.php' ? 'active' : '' ?>"><i class="fa-solid fa-gauge"></i> Dashboard</a>
        <a href="admin_events.php"    class="menu-item <?= basename($_SERVER['PHP_SELF']) === 'admin_events.php'    ? 'active' : '' ?>"><i class="fa-solid fa-calendar-days"></i> Manage Events</a>
        <a href="olap_analytics.php"  class="menu-item <?= basename($_SERVER['PHP_SELF']) === 'olap_analytics.php'  ? 'active' : '' ?>"><i class="fa-solid fa-chart-pie"></i> Analytics</a>
        <a href="etl_sync.php"        class="menu-item <?= basename($_SERVER['PHP_SELF']) === 'etl_sync.php'        ? 'active' : '' ?>"><i class="fa-solid fa-rotate"></i> ETL Pipeline</a>
    </nav>
    <div class="sidebar-footer">
        <div class="user-pill">
            <div class="user-avatar">A</div>
            <div>
                <div class="user-name">Admin</div>
                <div class="user-role">System Administrator</div>
            </div>
            <a href="logout.php" style="margin-left:auto;color:rgba(255,255,255,.3);font-size:.85rem;" title="Logout"><i class="fa-solid fa-right-from-bracket"></i></a>
        </div>
    </div>
</aside>
