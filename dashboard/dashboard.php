
<?php
session_start();

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

// Protect the dashboard.
if (!isset($_SESSION["user_id"])) {
    header("Location: ../login/login.php");
    exit;
}

// Database connection.
require_once "../connection/connect.php";

// Page information for the shared layout.
$pageTitle = "Dashboard";
$pageStylesheet = "dashboard.css";

$userName = $_SESSION["user_name"] ?? "User";
$userRole = $_SESSION["user_role"] ?? "staff";

// Retrieve dashboard statistics.
$sql = "
    SELECT
        COUNT(*) AS total_products,

        COALESCE(SUM(
            CASE
                WHEN stock_quantity > reorder_level THEN 1
                ELSE 0
            END
        ), 0) AS in_stock,

        COALESCE(SUM(
            CASE
                WHEN stock_quantity > 0
                AND stock_quantity <= reorder_level THEN 1
                ELSE 0
            END
        ), 0) AS low_stock,

        COALESCE(SUM(
            CASE
                WHEN stock_quantity = 0 THEN 1
                ELSE 0
            END
        ), 0) AS out_of_stock,

        COALESCE(SUM(stock_quantity * cost_price), 0) AS inventory_value

    FROM products
";

$stmt = $pdo->query($sql);
$stats = $stmt->fetch();

// Assign values.
$totalProducts = $stats["total_products"];
$inStock = $stats["in_stock"];
$lowStock = $stats["low_stock"];
$outOfStock = $stats["out_of_stock"];
$inventoryValue = $stats["inventory_value"];

// Load the shared header and sidebar.
require_once "../includes/header.php";
?>

<!-- Dashboard Content -->
<section class="dashboard-content">

    <h5 class="mb-3">Overview</h5>

    <div class="row g-3">

        <!-- Total Products -->
        <div class="col-md-6 col-xl-3">
            <div class="stat-card">

                <div class="stat-icon blue">
                    <i class="bi bi-box-seam"></i>
                </div>

                <h6>Total Products</h6>

                <h3>
                    <?= number_format($totalProducts); ?>
                </h3>

                <small>Registered products</small>

            </div>
        </div>

        <!-- In Stock -->
        <div class="col-md-6 col-xl-3">
            <div class="stat-card">

                <div class="stat-icon green">
                    <i class="bi bi-check-circle"></i>
                </div>

                <h6>In Stock</h6>

                <h3>
                    <?= number_format($inStock); ?>
                </h3>

                <small>Products above reorder level</small>

            </div>
        </div>

        <!-- Low Stock -->
        <div class="col-md-6 col-xl-3">
            <div class="stat-card">

                <div class="stat-icon orange">
                    <i class="bi bi-exclamation-triangle"></i>
                </div>

                <h6>Low Stock</h6>

                <h3>
                    <?= number_format($lowStock); ?>
                </h3>

                <small>Products needing restocking</small>

            </div>
        </div>

        <!-- Out of Stock -->
        <div class="col-md-6 col-xl-3">
            <div class="stat-card">

                <div class="stat-icon red">
                    <i class="bi bi-x-circle"></i>
                </div>

                <h6>Out of Stock</h6>

                <h3>
                    <?= number_format($outOfStock); ?>
                </h3>

                <small>Products with zero quantity</small>

            </div>
        </div>

        <!-- Inventory Value -->
        <div class="col-md-6 col-xl-3">
            <div class="stat-card">

                <div class="stat-icon blue">
                    <i class="bi bi-currency-exchange"></i>
                </div>

                <h6>Inventory Value</h6>

                <h3>
                    ₱<?= number_format((float) $inventoryValue, 2); ?>
                </h3>

                <small>Based on product cost prices</small>

            </div>
        </div>

    </div>

    <!-- Welcome Panel -->
    <div class="welcome-panel mt-4">

        <h5>
            Welcome to StockHero, <?= htmlspecialchars($userName, ENT_QUOTES, "UTF-8"); ?>!
        </h5>

        <p>Your inventory management system is ready.</p>

    </div>

</section>

<script src="dashboard.js"></script>

<?php
// Close the shared layout.
require_once "../includes/footer.php";
?>
