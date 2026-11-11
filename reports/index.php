
<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login/login.php");
    exit;
}

require_once "../connection/connect.php";

$pageTitle = "Reports";

$userName = $_SESSION["user_name"] ?? "User";
$userRole = $_SESSION["user_role"] ?? "staff";

// Escape output safely
function escape($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

// Validate report type
$reportType = $_GET["type"] ?? "movements";

$allowedReports = ["movements", "stock", "low_stock"];

if (!in_array($reportType, $allowedReports, true)) {
    $reportType = "movements";
}

// Get filters
$startDate = $_GET["start_date"] ?? "";
$endDate = $_GET["end_date"] ?? "";

$productId = filter_var(
    $_GET["product_id"] ?? "",
    FILTER_VALIDATE_INT
);

$movementType = $_GET["movement_type"] ?? "";

if ($productId === false || $productId === null) {
    $productId = "";
}

if (!in_array($movementType, ["stock_in", "stock_out"], true)) {
    $movementType = "";
}

// Validate date format and actual calendar dates
function isValidDate($date)
{
    if ($date === "") {
        return true;
    }

    $dateObject = DateTime::createFromFormat("!Y-m-d", $date);

    return $dateObject && $dateObject->format("Y-m-d") === $date;
}

if (!isValidDate($startDate) || !isValidDate($endDate)) {
    die("Invalid date filter. Please select a valid date.");
}

if ($startDate !== "" && $endDate !== "" && $startDate > $endDate) {
    die("Start date cannot be later than end date.");
}

// Get products for dropdown
$productStmt = $pdo->query("
    SELECT id, product_name, sku
    FROM products
    ORDER BY product_name ASC
");

$products = $productStmt->fetchAll(PDO::FETCH_ASSOC);

// Report variables
$reportData = [];
$totalRecords = 0;
$totalQuantity = 0;
$totalValue = 0;

// Generate report data
if ($reportType === "movements") {

    $sql = "
        SELECT
            sm.id,
            sm.movement_type,
            sm.quantity,
            sm.notes,
            sm.created_at,
            p.product_name,
            p.sku,
            u.name AS user_name
        FROM stock_movements sm
        INNER JOIN products p ON sm.product_id = p.id
        LEFT JOIN users u ON sm.user_id = u.id
        WHERE 1 = 1
    ";

    $params = [];

    if ($startDate !== "") {
        $sql .= " AND sm.created_at >= :start_date";
        $params["start_date"] = $startDate . " 00:00:00";
    }

    if ($endDate !== "") {
        $sql .= " AND sm.created_at < :end_date";
        $params["end_date"] = date(
            "Y-m-d",
            strtotime($endDate . " +1 day")
        ) . " 00:00:00";
    }

    if ($productId !== "") {
        $sql .= " AND sm.product_id = :product_id";
        $params["product_id"] = $productId;
    }

    if ($movementType !== "") {
        $sql .= " AND sm.movement_type = :movement_type";
        $params["movement_type"] = $movementType;
    }

    $sql .= " ORDER BY sm.created_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalRecords = count($reportData);

    foreach ($reportData as $row) {
        $totalQuantity += (int)$row["quantity"];
    }

} elseif ($reportType === "stock") {

    $sql = "
        SELECT
            p.id,
            p.product_name,
            p.sku,
            c.category_name,
            p.cost_price,
            p.selling_price,
            p.stock_quantity,
            p.reorder_level,
            (p.stock_quantity * p.cost_price) AS inventory_value
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        WHERE 1 = 1
    ";

    $params = [];

    if ($productId !== "") {
        $sql .= " AND p.id = :product_id";
        $params["product_id"] = $productId;
    }

    $sql .= " ORDER BY p.product_name ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalRecords = count($reportData);

    foreach ($reportData as $row) {
        $totalQuantity += (int)$row["stock_quantity"];
        $totalValue += (float)$row["inventory_value"];
    }

} elseif ($reportType === "low_stock") {

    $sql = "
        SELECT
            p.id,
            p.product_name,
            p.sku,
            c.category_name,
            p.stock_quantity,
            p.reorder_level,
            p.cost_price,
            (p.stock_quantity * p.cost_price) AS inventory_value
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        WHERE p.stock_quantity <= p.reorder_level
    ";

    $params = [];

    if ($productId !== "") {
        $sql .= " AND p.id = :product_id";
        $params["product_id"] = $productId;
    }

    $sql .= " ORDER BY p.stock_quantity ASC, p.product_name ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalRecords = count($reportData);

    foreach ($reportData as $row) {
        $totalQuantity += (int)$row["stock_quantity"];
        $totalValue += (float)$row["inventory_value"];
    }
}

// CSV Export
if (isset($_GET["export"]) && $_GET["export"] === "csv") {

    $filename = "stockhero_" . $reportType . "_" . date("Y-m-d") . ".csv";

    header("Content-Type: text/csv; charset=UTF-8");
    header("Content-Disposition: attachment; filename=\"$filename\"");

    $output = fopen("php://output", "w");

    // UTF-8 BOM for Excel compatibility
    fwrite($output, "\xEF\xBB\xBF");

    if ($reportType === "movements") {

        fputcsv($output, [
            "Transaction ID",
            "Product",
            "SKU",
            "Movement Type",
            "Quantity",
            "User",
            "Notes",
            "Date"
        ]);

        foreach ($reportData as $row) {
            fputcsv($output, [
                $row["id"],
                $row["product_name"],
                $row["sku"],
                strtoupper(str_replace("_", " ", $row["movement_type"])),
                $row["quantity"],
                $row["user_name"] ?? "Unknown",
                $row["notes"],
                $row["created_at"]
            ]);
        }

    } elseif ($reportType === "stock") {

        fputcsv($output, [
            "Product",
            "SKU",
            "Category",
            "Cost Price",
            "Selling Price",
            "Stock Quantity",
            "Reorder Level",
            "Inventory Value"
        ]);

        foreach ($reportData as $row) {
            fputcsv($output, [
                $row["product_name"],
                $row["sku"],
                $row["category_name"] ?? "Uncategorized",
                $row["cost_price"],
                $row["selling_price"],
                $row["stock_quantity"],
                $row["reorder_level"],
                $row["inventory_value"]
            ]);
        }

    } else {

        fputcsv($output, [
            "Product",
            "SKU",
            "Category",
            "Current Stock",
            "Reorder Level",
            "Cost Price",
            "Inventory Value"
        ]);

        foreach ($reportData as $row) {
            fputcsv($output, [
                $row["product_name"],
                $row["sku"],
                $row["category_name"] ?? "Uncategorized",
                $row["stock_quantity"],
                $row["reorder_level"],
                $row["cost_price"],
                $row["inventory_value"]
            ]);
        }
    }

    fclose($output);
    exit;
}

// Report titles
$reportTitles = [
    "movements" => "Stock Movement Report",
    "stock" => "Current Stock Report",
    "low_stock" => "Low Stock Report"
];

$reportTitle = $reportTitles[$reportType];

// Load shared header
require_once "../includes/header.php";
?>

<style>
    .report-card {
        border: none;
        border-radius: 12px;
        box-shadow: 0 3px 15px rgba(0, 0, 0, 0.05);
    }

    .report-title {
        color: #172554;
        font-weight: 700;
    }

    .stat-icon {
        width: 45px;
        height: 45px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 10px;
        font-size: 21px;
        flex-shrink: 0;
    }

    .filter-label {
        font-size: 13px;
        font-weight: 600;
    }

    .report-table th {
        color: #64748b;
        font-size: 12px;
        white-space: nowrap;
    }

    .report-table td {
        vertical-align: middle;
        white-space: nowrap;
    }

    .report-table .notes-cell {
        white-space: normal;
        min-width: 150px;
        max-width: 250px;
    }

    @media (max-width: 768px) {
        .report-content {
            padding: 20px 12px;
        }
    }
</style>

<div class="report-content">

    <!-- PAGE HEADER -->
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">

        <div>
            <h2 class="report-title mb-1">Reports</h2>
            <p class="text-secondary mb-0">
                Monitor inventory performance and stock transactions.
            </p>
        </div>

    </div>

    <!-- REPORT SELECTOR -->
    <div class="card report-card mb-4">

        <div class="card-body">

            <form method="GET" class="row align-items-end g-3">

                <div class="col-md-4">

                    <label class="form-label filter-label">
                        Select Report
                    </label>

                    <select
                        name="type"
                        class="form-select"
                        onchange="this.form.submit()"
                    >

                        <option value="movements"
                            <?= $reportType === "movements" ? "selected" : ""; ?>>
                            Stock Movement Report
                        </option>

                        <option value="stock"
                            <?= $reportType === "stock" ? "selected" : ""; ?>>
                            Current Stock Report
                        </option>

                        <option value="low_stock"
                            <?= $reportType === "low_stock" ? "selected" : ""; ?>>
                            Low Stock Report
                        </option>

                    </select>

                </div>

            </form>

        </div>

    </div>

    <!-- FILTERS -->
    <div class="card report-card mb-4">

        <div class="card-body">

            <form method="GET" class="row g-3 align-items-end">

                <input
                    type="hidden"
                    name="type"
                    value="<?= escape($reportType); ?>"
                >

                <?php if ($reportType === "movements"): ?>

                    <div class="col-md-3">

                        <label class="form-label filter-label">
                            Start Date
                        </label>

                        <input
                            type="date"
                            name="start_date"
                            class="form-control"
                            value="<?= escape($startDate); ?>"
                        >

                    </div>

                    <div class="col-md-3">

                        <label class="form-label filter-label">
                            End Date
                        </label>

                        <input
                            type="date"
                            name="end_date"
                            class="form-control"
                            value="<?= escape($endDate); ?>"
                        >

                    </div>

                    <div class="col-md-3">

                        <label class="form-label filter-label">
                            Movement Type
                        </label>

                        <select name="movement_type" class="form-select">

                            <option value="">All Movements</option>

                            <option value="stock_in"
                                <?= $movementType === "stock_in" ? "selected" : ""; ?>>
                                Stock In
                            </option>

                            <option value="stock_out"
                                <?= $movementType === "stock_out" ? "selected" : ""; ?>>
                                Stock Out
                            </option>

                        </select>

                    </div>

                <?php endif; ?>

                <div class="col-md-3">

                    <label class="form-label filter-label">
                        Product
                    </label>

                    <select name="product_id" class="form-select">

                        <option value="">All Products</option>

                        <?php foreach ($products as $product): ?>

                            <option
                                value="<?= escape($product["id"]); ?>"
                                <?= (string)$productId === (string)$product["id"] ? "selected" : ""; ?>
                            >
                                <?= escape($product["product_name"]); ?>
                                (<?= escape($product["sku"]); ?>)
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

                <div class="col-12 d-flex flex-wrap gap-2">

                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-funnel me-1"></i>
                        Apply Filters
                    </button>

                    <a
                        href="index.php?type=<?= escape($reportType); ?>"
                        class="btn btn-outline-secondary"
                    >
                        <i class="bi bi-arrow-counterclockwise me-1"></i>
                        Reset
                    </a>

                </div>

            </form>

        </div>

    </div>

    <!-- SUMMARY CARDS -->
    <div class="row g-3 mb-4">

        <div class="col-md-4">

            <div class="card report-card h-100">

                <div class="card-body d-flex align-items-center gap-3">

                    <div class="stat-icon"
                         style="background:#e0e7ff;color:#1e40af;">
                        <i class="bi bi-file-earmark-text"></i>
                    </div>

                    <div>
                        <small class="text-secondary">Total Records</small>

                        <h4 class="fw-bold mb-0">
                            <?= number_format($totalRecords); ?>
                        </h4>
                    </div>

                </div>

            </div>

        </div>

        <div class="col-md-4">

            <div class="card report-card h-100">

                <div class="card-body d-flex align-items-center gap-3">

                    <div class="stat-icon"
                         style="background:#dcfce7;color:#15803d;">
                        <i class="bi bi-box-seam"></i>
                    </div>

                    <div>
                        <small class="text-secondary">
                            <?= $reportType === "movements"
                                ? "Total Movement Quantity"
                                : "Total Stock Units"; ?>
                        </small>

                        <h4 class="fw-bold mb-0">
                            <?= number_format($totalQuantity); ?>
                        </h4>
                    </div>

                </div>

            </div>

        </div>

        <div class="col-md-4">

            <div class="card report-card h-100">

                <div class="card-body d-flex align-items-center gap-3">

                    <div class="stat-icon"
                         style="background:#fef3c7;color:#b45309;">
                        <i class="bi bi-currency-exchange"></i>
                    </div>

                    <div>
                        <small class="text-secondary">Inventory Value</small>

                        <h4 class="fw-bold mb-0">
                            ₱<?= number_format($totalValue, 2); ?>
                        </h4>
                    </div>

                </div>

            </div>

        </div>

    </div>

    <!-- REPORT TABLE -->
    <div class="card report-card">

        <div class="card-body">

            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">

                <div>
                    <h5 class="fw-bold mb-1">
                        <?= escape($reportTitle); ?>
                    </h5>

                    <small class="text-secondary">
                        Showing <?= number_format($totalRecords); ?> records
                    </small>
                </div>

                <a
                    href="?<?= escape(http_build_query(array_merge($_GET, ["export" => "csv"]))); ?>"
                    class="btn btn-success"
                >
                    <i class="bi bi-download me-1"></i>
                    Export CSV
                </a>

            </div>

            <div class="table-responsive">

                <table class="table table-hover align-middle report-table">

                    <?php if ($reportType === "movements"): ?>

                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>PRODUCT</th>
                                <th>SKU</th>
                                <th>TYPE</th>
                                <th>QUANTITY</th>
                                <th>USER</th>
                                <th>NOTES</th>
                                <th>DATE</th>
                            </tr>
                        </thead>

                        <tbody>

                            <?php if (count($reportData) > 0): ?>

                                <?php foreach ($reportData as $row): ?>

                                    <tr>

                                        <td><?= escape($row["id"]); ?></td>

                                        <td class="fw-semibold">
                                            <?= escape($row["product_name"]); ?>
                                        </td>

                                        <td><?= escape($row["sku"]); ?></td>

                                        <td>

                                            <?php if ($row["movement_type"] === "stock_in"): ?>

                                                <span class="badge bg-success">
                                                    Stock In
                                                </span>

                                            <?php else: ?>

                                                <span class="badge bg-danger">
                                                    Stock Out
                                                </span>

                                            <?php endif; ?>

                                        </td>

                                        <td>
                                            <?= number_format((int)$row["quantity"]); ?>
                                        </td>

                                        <td>
                                            <?= escape($row["user_name"] ?? "Unknown"); ?>
                                        </td>

                                        <td class="notes-cell">
                                            <?= escape($row["notes"] ?: "—"); ?>
                                        </td>

                                        <td>
                                            <?= escape(date(
                                                "M d, Y h:i A",
                                                strtotime($row["created_at"])
                                            )); ?>
                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            <?php else: ?>

                                <tr>
                                    <td colspan="8"
                                        class="text-center text-secondary py-5">
                                        No stock movements found.
                                    </td>
                                </tr>

                            <?php endif; ?>

                        </tbody>

                    <?php elseif ($reportType === "stock"): ?>

                        <thead>
                            <tr>
                                <th>PRODUCT</th>
                                <th>SKU</th>
                                <th>CATEGORY</th>
                                <th>COST PRICE</th>
                                <th>SELLING PRICE</th>
                                <th>STOCK</th>
                                <th>REORDER LEVEL</th>
                                <th>INVENTORY VALUE</th>
                            </tr>
                        </thead>

                        <tbody>

                            <?php if (count($reportData) > 0): ?>

                                <?php foreach ($reportData as $row): ?>

                                    <tr>

                                        <td class="fw-semibold">
                                            <?= escape($row["product_name"]); ?>
                                        </td>

                                        <td><?= escape($row["sku"]); ?></td>

                                        <td>
                                            <?= escape($row["category_name"] ?? "Uncategorized"); ?>
                                        </td>

                                        <td>
                                            ₱<?= number_format((float)$row["cost_price"], 2); ?>
                                        </td>

                                        <td>
                                            ₱<?= number_format((float)$row["selling_price"], 2); ?>
                                        </td>

                                        <td>
                                            <?= number_format((int)$row["stock_quantity"]); ?>
                                        </td>

                                        <td>
                                            <?= number_format((int)$row["reorder_level"]); ?>
                                        </td>

                                        <td class="fw-semibold">
                                            ₱<?= number_format((float)$row["inventory_value"], 2); ?>
                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            <?php else: ?>

                                <tr>
                                    <td colspan="8"
                                        class="text-center text-secondary py-5">
                                        No products found.
                                    </td>
                                </tr>

                            <?php endif; ?>

                        </tbody>

                    <?php else: ?>

                        <thead>
                            <tr>
                                <th>PRODUCT</th>
                                <th>SKU</th>
                                <th>CATEGORY</th>
                                <th>CURRENT STOCK</th>
                                <th>REORDER LEVEL</th>
                                <th>STATUS</th>
                                <th>INVENTORY VALUE</th>
                            </tr>
                        </thead>

                        <tbody>

                            <?php if (count($reportData) > 0): ?>

                                <?php foreach ($reportData as $row): ?>

                                    <tr>

                                        <td class="fw-semibold">
                                            <?= escape($row["product_name"]); ?>
                                        </td>

                                        <td><?= escape($row["sku"]); ?></td>

                                        <td>
                                            <?= escape($row["category_name"] ?? "Uncategorized"); ?>
                                        </td>

                                        <td>
                                            <?= number_format((int)$row["stock_quantity"]); ?>
                                        </td>

                                        <td>
                                            <?= number_format((int)$row["reorder_level"]); ?>
                                        </td>

                                        <td>

                                            <?php if ((int)$row["stock_quantity"] === 0): ?>

                                                <span class="badge bg-danger">
                                                    Out of Stock
                                                </span>

                                            <?php else: ?>

                                                <span class="badge bg-warning text-dark">
                                                    Low Stock
                                                </span>

                                            <?php endif; ?>

                                        </td>

                                        <td>
                                            ₱<?= number_format((float)$row["inventory_value"], 2); ?>
                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            <?php else: ?>

                                <tr>
                                    <td colspan="7"
                                        class="text-center text-secondary py-5">
                                        Great! No low-stock products found.
                                    </td>
                                </tr>

                            <?php endif; ?>

                        </tbody>

                    <?php endif; ?>

                </table>

            </div>

        </div>

    </div>

</div>

<?php
// Load shared footer
require_once "../includes/footer.php";
?>
