
<?php
$currentPage = basename($_SERVER["PHP_SELF"]);
$userRole = $_SESSION["user_role"] ?? "staff";

function navActive($page, $currentPage) {
    return $page === $currentPage ? "active" : "";
}
?>

<style>
    .stockhero-sidebar {
        width: 250px;
        min-height: 100vh;
        background: #172554;
        color: #fff;
        padding: 24px 16px;
        flex-shrink: 0;
    }

    .stockhero-brand {
        font-size: 23px;
        font-weight: 700;
        margin-bottom: 35px;
        padding-left: 12px;
    }

    .stockhero-brand span {
        color: #60a5fa;
    }

    .stockhero-sidebar .nav-label {
        color: #94a3b8;
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 1px;
        padding: 0 12px;
        margin: 22px 0 10px;
    }

    .stockhero-sidebar .nav-link {
        display: flex;
        align-items: center;
        gap: 12px;
        color: #cbd5e1;
        padding: 11px 12px;
        margin-bottom: 5px;
        border-radius: 8px;
        text-decoration: none;
        font-size: 14px;
        transition: 0.2s;
    }

    .stockhero-sidebar .nav-link:hover {
        background: #263b68;
        color: #fff;
    }

    .stockhero-sidebar .nav-link.active {
        background: #2563eb;
        color: #fff;
    }

    .stockhero-sidebar .nav-link i {
        font-size: 17px;
        width: 20px;
        text-align: center;
    }

    @media (max-width: 768px) {
        .stockhero-sidebar {
            width: 100%;
            min-height: auto;
        }
    }
</style>

<aside class="stockhero-sidebar">

    <div class="stockhero-brand">
        <i class="bi bi-box-seam"></i>
        Stock<span>Hero</span>
    </div>

    <div class="nav-label">MAIN MENU</div>

    <a href="../dashboard/dashboard.php"
       class="nav-link <?= navActive('dashboard.php', $currentPage) ?>">
        <i class="bi bi-grid"></i>
        Dashboard
    </a>

    <div class="nav-label">INVENTORY MANAGEMENT</div>

    <a href="../products/index.php"
       class="nav-link <?= navActive('index.php', $currentPage) && str_contains($_SERVER['PHP_SELF'], '/products/') ? 'active' : '' ?>">
        <i class="bi bi-box"></i>
        Products
    </a>

    <a href="../categories/index.php"
       class="nav-link <?= str_contains($_SERVER['PHP_SELF'], '/categories/') ? 'active' : '' ?>">
        <i class="bi bi-tags"></i>
        Categories
    </a>

    <a href="../inventory/index.php"
       class="nav-link <?= str_contains($_SERVER['PHP_SELF'], '/inventory/') ? 'active' : '' ?>">
        <i class="bi bi-arrow-left-right"></i>
        Stock Movements
    </a>

    <a href="../suppliers/index.php"
       class="nav-link <?= str_contains($_SERVER['PHP_SELF'], '/suppliers/') ? 'active' : '' ?>">
        <i class="bi bi-truck"></i>
        Suppliers
    </a>

    <div class="nav-label">ANALYTICS</div>

    <a href="../reports/index.php"
       class="nav-link <?= str_contains($_SERVER['PHP_SELF'], '/reports/') ? 'active' : '' ?>">
        <i class="bi bi-bar-chart"></i>
        Reports
    </a>

    <?php if ($userRole === "admin"): ?>

        <div class="nav-label">ADMINISTRATION</div>

        <a href="../users/index.php"
           class="nav-link <?= str_contains($_SERVER['PHP_SELF'], '/users/') ? 'active' : '' ?>">
            <i class="bi bi-people"></i>
            User Management
        </a>

    <?php endif; ?>

</aside>
