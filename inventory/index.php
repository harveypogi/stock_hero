
<?php
session_start();

// Prevent cached pages after logout
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login/login.php");
    exit;
}

require_once "../connection/connect.php";

// Page settings
$pageTitle = "Inventory";

// Generate CSRF token
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

// Helper for safe HTML output
function escape($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

// Handle flash messages
$successMessage = $_SESSION["success_message"] ?? "";
$errorMessage = $_SESSION["error_message"] ?? "";

unset($_SESSION["success_message"], $_SESSION["error_message"]);

// Handle Stock In and Stock Out
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // Validate CSRF token
    if (
        !isset($_POST["csrf_token"]) ||
        !hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"])
    ) {
        $_SESSION["error_message"] = "Invalid request. Please try again.";
        header("Location: index.php");
        exit;
    }

    $productId = filter_var(
        $_POST["product_id"] ?? "",
        FILTER_VALIDATE_INT
    );

    $movementType = $_POST["movement_type"] ?? "";

    $quantity = filter_var(
        $_POST["quantity"] ?? "",
        FILTER_VALIDATE_INT
    );

    $notes = trim($_POST["notes"] ?? "");

    if (!$productId || $quantity === false || $quantity <= 0) {
        $_SESSION["error_message"] =
            "Please select a product and enter a valid quantity.";

        header("Location: index.php");
        exit;
    }

    if (!in_array($movementType, ["stock_in", "stock_out"], true)) {
        $_SESSION["error_message"] = "Invalid stock movement type.";
        header("Location: index.php");
        exit;
    }

    try {

        // Begin transaction
        $pdo->beginTransaction();

        // Lock product row to prevent conflicting stock updates
        $productStmt = $pdo->prepare(
            "SELECT id, product_name, stock_quantity
             FROM products
             WHERE id = :id
             FOR UPDATE"
        );

        $productStmt->execute(["id" => $productId]);
        $product = $productStmt->fetch();

        if (!$product) {
            throw new Exception("Selected product does not exist.");
        }

        $currentStock = (int)$product["stock_quantity"];

        if ($movementType === "stock_in") {

            // Prevent exceeding the database INT limit
            if ($quantity > 2147483647 - $currentStock) {
                throw new Exception("The resulting stock quantity is too large.");
            }

            $newStock = $currentStock + $quantity;

        } else {

            // Prevent negative stock
            if ($quantity > $currentStock) {
                throw new Exception(
                    "Insufficient stock. Available quantity: " . $currentStock
                );
            }

            $newStock = $currentStock - $quantity;
        }

        // Update product stock
        $updateStmt = $pdo->prepare(
            "UPDATE products
             SET stock_quantity = :stock
             WHERE id = :id"
        );

        $updateStmt->execute([
            "stock" => $newStock,
            "id" => $productId
        ]);

        // Record stock movement history
        $movementStmt = $pdo->prepare(
            "INSERT INTO stock_movements
                (product_id, user_id, movement_type, quantity, notes)
             VALUES
                (:product_id, :user_id, :movement_type, :quantity, :notes)"
        );

        $movementStmt->execute([
            "product_id" => $productId,
            "user_id" => $_SESSION["user_id"],
            "movement_type" => $movementType,
            "quantity" => $quantity,
            "notes" => $notes !== "" ? $notes : null
        ]);

        // Commit both operations
        $pdo->commit();

        $_SESSION["success_message"] =
            "Stock movement recorded successfully! " .
            $product["product_name"] .
            " now has " . $newStock . " units.";

    } catch (Throwable $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ($e instanceof PDOException) {
            $_SESSION["error_message"] =
                "A database error occurred. Please try again.";
        } else {
            $_SESSION["error_message"] = $e->getMessage();
        }
    }

    header("Location: index.php");
    exit;
}

// Retrieve products, including reorder level
$productStmt = $pdo->query(
    "SELECT id, product_name, sku, stock_quantity, reorder_level
     FROM products
     ORDER BY product_name ASC"
);

$products = $productStmt->fetchAll();

// Retrieve latest 100 stock movements
$historySql = "
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
    ORDER BY sm.created_at DESC, sm.id DESC
    LIMIT 100
";

$historyStmt = $pdo->query($historySql);
$movements = $historyStmt->fetchAll();

// Calculate inventory summary
$totalProducts = count($products);
$totalStock = 0;

foreach ($products as $product) {
    $totalStock += (int)$product["stock_quantity"];
}

// Retrieve low-stock and out-of-stock counts
$summaryStmt = $pdo->query(
    "SELECT
        SUM(CASE
            WHEN stock_quantity <= reorder_level
                 AND stock_quantity > 0
            THEN 1 ELSE 0
        END) AS low_stock,
        SUM(CASE
            WHEN stock_quantity = 0
            THEN 1 ELSE 0
        END) AS out_of_stock
     FROM products"
);

$summary = $summaryStmt->fetch();

$lowStock = (int)($summary["low_stock"] ?? 0);
$outOfStock = (int)($summary["out_of_stock"] ?? 0);

// Shared layout
require_once "../includes/header.php";
?>

<style>
    .inventory-page {
        padding: 30px;
    }

    .summary-card {
        background: #fff;
        border-radius: 12px;
        padding: 20px;
        height: 100%;
        box-shadow: 0 2px 12px rgba(0, 0, 0, 0.04);
    }

    .summary-icon {
        width: 44px;
        height: 44px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 21px;
    }

    .content-card {
        background: #fff;
        border-radius: 12px;
        padding: 22px;
        box-shadow: 0 2px 12px rgba(0, 0, 0, 0.04);
    }

    .inventory-page .table th {
        color: #64748b;
        font-size: 13px;
        font-weight: 600;
        white-space: nowrap;
    }

    .inventory-page .table td {
        vertical-align: middle;
    }

    .inventory-page .form-label {
        font-weight: 500;
    }

    @media (max-width: 576px) {
        .inventory-page {
            padding: 15px;
        }
    }
</style>

<div class="inventory-page">

    <!-- PAGE HEADER -->
    <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap mb-4">

        <div>
            <h3 class="fw-bold mb-1">Inventory Management</h3>
            <p class="text-muted mb-0">
                Monitor stock levels and record inventory movements.
            </p>
        </div>

        <button
            class="btn btn-primary"
            data-bs-toggle="modal"
            data-bs-target="#stockMovementModal">

            <i class="bi bi-plus-lg me-1"></i>
            Record Stock Movement

        </button>

    </div>

    <!-- FLASH MESSAGES -->
    <?php if ($successMessage): ?>

        <div class="alert alert-success alert-dismissible fade show">
            <?= escape($successMessage) ?>

            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert">
            </button>
        </div>

    <?php endif; ?>

    <?php if ($errorMessage): ?>

        <div class="alert alert-danger alert-dismissible fade show">
            <?= escape($errorMessage) ?>

            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert">
            </button>
        </div>

    <?php endif; ?>

    <!-- SUMMARY CARDS -->
    <div class="row g-3 mb-4">

        <div class="col-md-6 col-xl-3">
            <div class="summary-card">
                <div class="d-flex justify-content-between align-items-center">

                    <div>
                        <p class="text-muted mb-1">Total Products</p>
                        <h3 class="fw-bold mb-0">
                            <?= number_format($totalProducts) ?>
                        </h3>
                    </div>

                    <div class="summary-icon bg-primary-subtle text-primary">
                        <i class="bi bi-box-seam"></i>
                    </div>

                </div>
            </div>
        </div>

        <div class="col-md-6 col-xl-3">
            <div class="summary-card">
                <div class="d-flex justify-content-between align-items-center">

                    <div>
                        <p class="text-muted mb-1">Total Stock Units</p>
                        <h3 class="fw-bold mb-0">
                            <?= number_format($totalStock) ?>
                        </h3>
                    </div>

                    <div class="summary-icon bg-success-subtle text-success">
                        <i class="bi bi-boxes"></i>
                    </div>

                </div>
            </div>
        </div>

        <div class="col-md-6 col-xl-3">
            <div class="summary-card">
                <div class="d-flex justify-content-between align-items-center">

                    <div>
                        <p class="text-muted mb-1">Low Stock</p>
                        <h3 class="fw-bold mb-0">
                            <?= number_format($lowStock) ?>
                        </h3>
                    </div>

                    <div class="summary-icon bg-warning-subtle text-warning">
                        <i class="bi bi-exclamation-triangle"></i>
                    </div>

                </div>
            </div>
        </div>

        <div class="col-md-6 col-xl-3">
            <div class="summary-card">
                <div class="d-flex justify-content-between align-items-center">

                    <div>
                        <p class="text-muted mb-1">Out of Stock</p>
                        <h3 class="fw-bold mb-0">
                            <?= number_format($outOfStock) ?>
                        </h3>
                    </div>

                    <div class="summary-icon bg-danger-subtle text-danger">
                        <i class="bi bi-x-circle"></i>
                    </div>

                </div>
            </div>
        </div>

    </div>

    <!-- CURRENT STOCK TABLE -->
    <div class="content-card mb-4">

        <h5 class="fw-bold mb-3">Current Stock Levels</h5>

        <div class="table-responsive">

            <table class="table table-hover align-middle">

                <thead>
                    <tr>
                        <th>PRODUCT</th>
                        <th>SKU</th>
                        <th>AVAILABLE STOCK</th>
                        <th>STATUS</th>
                    </tr>
                </thead>

                <tbody>

                    <?php if (count($products) > 0): ?>

                        <?php foreach ($products as $product): ?>

                            <?php
                            $stock = (int)$product["stock_quantity"];
                            $reorderLevel = (int)$product["reorder_level"];

                            if ($stock === 0) {
                                $status = "Out of Stock";
                                $badgeClass = "text-bg-danger";
                            } elseif ($stock <= $reorderLevel) {
                                $status = "Low Stock";
                                $badgeClass = "text-bg-warning";
                            } else {
                                $status = "In Stock";
                                $badgeClass = "text-bg-success";
                            }
                            ?>

                            <tr>

                                <td>
                                    <strong>
                                        <?= escape($product["product_name"]) ?>
                                    </strong>
                                </td>

                                <td>
                                    <?= escape($product["sku"]) ?>
                                </td>

                                <td>
                                    <?= number_format($stock) ?>
                                </td>

                                <td>
                                    <span class="badge <?= $badgeClass ?>">
                                        <?= escape($status) ?>
                                    </span>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="4" class="text-center text-muted py-4">
                                No products available. Add products first.
                            </td>
                        </tr>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

    <!-- STOCK MOVEMENT HISTORY -->
    <div class="content-card">

        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">

            <h5 class="fw-bold mb-0">Stock Movement History</h5>

            <span class="badge text-bg-secondary">
                Latest 100 Records
            </span>

        </div>

        <div class="table-responsive">

            <table class="table table-hover align-middle">

                <thead>
                    <tr>
                        <th>DATE</th>
                        <th>PRODUCT</th>
                        <th>TYPE</th>
                        <th>QUANTITY</th>
                        <th>USER</th>
                        <th>NOTES</th>
                    </tr>
                </thead>

                <tbody>

                    <?php if (count($movements) > 0): ?>

                        <?php foreach ($movements as $movement): ?>

                            <tr>

                                <td>
                                    <?= escape(date(
                                        "M d, Y h:i A",
                                        strtotime($movement["created_at"])
                                    )) ?>
                                </td>

                                <td>
                                    <strong>
                                        <?= escape($movement["product_name"]) ?>
                                    </strong>

                                    <div class="text-muted small">
                                        <?= escape($movement["sku"]) ?>
                                    </div>
                                </td>

                                <td>

                                    <?php if ($movement["movement_type"] === "stock_in"): ?>

                                        <span class="badge text-bg-success">
                                            <i class="bi bi-arrow-down-circle me-1"></i>
                                            Stock In
                                        </span>

                                    <?php else: ?>

                                        <span class="badge text-bg-danger">
                                            <i class="bi bi-arrow-up-circle me-1"></i>
                                            Stock Out
                                        </span>

                                    <?php endif; ?>

                                </td>

                                <td>
                                    <?= number_format((int)$movement["quantity"]) ?>
                                </td>

                                <td>
                                    <?= escape($movement["user_name"] ?? "Unknown User") ?>
                                </td>

                                <td>
                                    <?= escape($movement["notes"] ?: "—") ?>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">
                                <i class="bi bi-clock-history fs-2 d-block mb-2"></i>
                                No stock movements recorded yet.
                            </td>
                        </tr>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

</div>

<!-- STOCK MOVEMENT MODAL -->
<div class="modal fade" id="stockMovementModal" tabindex="-1">

    <div class="modal-dialog modal-lg modal-dialog-centered">

        <div class="modal-content">

            <form method="POST" action="index.php">

                <div class="modal-header">

                    <h5 class="modal-title fw-bold">
                        Record Stock Movement
                    </h5>

                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="modal">
                    </button>

                </div>

                <div class="modal-body">

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= escape($_SESSION["csrf_token"]) ?>">

                    <div class="mb-3">

                        <label class="form-label">
                            Select Product *
                        </label>

                        <select
                            name="product_id"
                            class="form-select"
                            required>

                            <option value="">Choose a product</option>

                            <?php foreach ($products as $product): ?>

                                <option value="<?= (int)$product["id"] ?>">

                                    <?= escape($product["product_name"]) ?>
                                    (<?= escape($product["sku"]) ?>)
                                    — Stock: <?= (int)$product["stock_quantity"] ?>

                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <div class="mb-3">

                        <label class="form-label">
                            Movement Type *
                        </label>

                        <select
                            name="movement_type"
                            class="form-select"
                            required>

                            <option value="">Choose movement type</option>

                            <option value="stock_in">
                                Stock In — Add Quantity
                            </option>

                            <option value="stock_out">
                                Stock Out — Remove Quantity
                            </option>

                        </select>

                    </div>

                    <div class="mb-3">

                        <label class="form-label">
                            Quantity *
                        </label>

                        <input
                            type="number"
                            name="quantity"
                            class="form-control"
                            min="1"
                            step="1"
                            placeholder="Enter quantity"
                            required>

                    </div>

                    <div class="mb-3">

                        <label class="form-label">
                            Notes
                        </label>

                        <textarea
                            name="notes"
                            class="form-control"
                            rows="3"
                            placeholder="Example: New supplier delivery, product sold, damaged items..."></textarea>

                    </div>

                </div>

                <div class="modal-footer">

                    <button
                        type="button"
                        class="btn btn-light"
                        data-bs-dismiss="modal">
                        Cancel
                    </button>

                    <button
                        type="submit"
                        class="btn btn-primary">

                        <i class="bi bi-check-lg me-1"></i>
                        Save Movement

                    </button>

                </div>

            </form>

        </div>

    </div>

</div>

<?php require_once "../includes/footer.php"; ?>
