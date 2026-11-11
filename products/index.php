
<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login/login.php");
    exit;
}

require_once "../connection/connect.php";

// Page information for the shared layout.
$pageTitle = "Products";

$userId = (int) $_SESSION["user_id"];

// Generate CSRF token.
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

// Flash messages.
$successMessage = $_SESSION["success_message"] ?? "";
$errorMessage = $_SESSION["error_message"] ?? "";

unset($_SESSION["success_message"], $_SESSION["error_message"]);

// Helper for safe HTML output.
function escape($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

// Handle Add, Edit, and Delete.
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if (
        !isset($_POST["csrf_token"]) ||
        !hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"])
    ) {
        $_SESSION["error_message"] = "Invalid request. Please try again.";
        header("Location: index.php");
        exit;
    }

    $action = $_POST["action"] ?? "";

    try {

        // ADD PRODUCT
        if ($action === "add") {

            $productName = trim($_POST["product_name"] ?? "");
            $sku = trim($_POST["sku"] ?? "");
            $categoryId = $_POST["category_id"] ?? "";
            $description = trim($_POST["description"] ?? "");
            $costPrice = $_POST["cost_price"] ?? "";
            $sellingPrice = $_POST["selling_price"] ?? "";
            $stockQuantity = $_POST["stock_quantity"] ?? "";
            $reorderLevel = $_POST["reorder_level"] ?? "";

            if ($productName === "" || $sku === "") {
                throw new Exception("Product name and SKU are required.");
            }

            if (
                !is_numeric($costPrice) ||
                !is_numeric($sellingPrice) ||
                !is_finite((float) $costPrice) ||
                !is_finite((float) $sellingPrice) ||
                (float) $costPrice < 0 ||
                (float) $sellingPrice < 0
            ) {
                throw new Exception("Please enter valid, non-negative prices.");
            }

            if (
                filter_var($stockQuantity, FILTER_VALIDATE_INT) === false ||
                filter_var($reorderLevel, FILTER_VALIDATE_INT) === false ||
                (int) $stockQuantity < 0 ||
                (int) $reorderLevel < 0
            ) {
                throw new Exception(
                    "Stock quantity and reorder level must be non-negative whole numbers."
                );
            }

            $stockQuantity = (int) $stockQuantity;
            $reorderLevel = (int) $reorderLevel;

            $categoryId = $categoryId === "" ? null : $categoryId;

            if ($categoryId !== null) {

                $checkCategory = $pdo->prepare(
                    "SELECT id FROM categories WHERE id = :id"
                );

                $checkCategory->execute(["id" => $categoryId]);

                if (!$checkCategory->fetch()) {
                    throw new Exception("Selected category does not exist.");
                }
            }

            // Save the product and its initial stock movement together.
            $pdo->beginTransaction();

            $sql = "
                INSERT INTO products
                (
                    product_name,
                    sku,
                    category_id,
                    description,
                    cost_price,
                    selling_price,
                    stock_quantity,
                    reorder_level
                )
                VALUES
                (
                    :product_name,
                    :sku,
                    :category_id,
                    :description,
                    :cost_price,
                    :selling_price,
                    :stock_quantity,
                    :reorder_level
                )
            ";

            $stmt = $pdo->prepare($sql);

            $stmt->execute([
                "product_name" => $productName,
                "sku" => $sku,
                "category_id" => $categoryId,
                "description" => $description,
                "cost_price" => $costPrice,
                "selling_price" => $sellingPrice,
                "stock_quantity" => $stockQuantity,
                "reorder_level" => $reorderLevel
            ]);

            $newProductId = (int) $pdo->lastInsertId();

            // Record initial stock in the stock movement history.
            if ($stockQuantity > 0) {

                $movementStmt = $pdo->prepare("
                    INSERT INTO stock_movements
                    (
                        product_id,
                        user_id,
                        movement_type,
                        quantity,
                        notes
                    )
                    VALUES
                    (
                        :product_id,
                        :user_id,
                        'stock_in',
                        :quantity,
                        :notes
                    )
                ");

                $movementStmt->execute([
                    "product_id" => $newProductId,
                    "user_id" => $userId,
                    "quantity" => $stockQuantity,
                    "notes" => "Initial stock when product was created."
                ]);
            }

            $pdo->commit();

            $_SESSION["success_message"] = "Product added successfully!";
        }

        // EDIT PRODUCT
        elseif ($action === "edit") {

            $productId = filter_var(
                $_POST["product_id"] ?? "",
                FILTER_VALIDATE_INT
            );

            $productName = trim($_POST["product_name"] ?? "");
            $sku = trim($_POST["sku"] ?? "");
            $categoryId = $_POST["category_id"] ?? "";
            $description = trim($_POST["description"] ?? "");
            $costPrice = $_POST["cost_price"] ?? "";
            $sellingPrice = $_POST["selling_price"] ?? "";
            $reorderLevel = $_POST["reorder_level"] ?? "";

            if (!$productId || $productName === "" || $sku === "") {
                throw new Exception("Please provide valid product information.");
            }

            if (
                !is_numeric($costPrice) ||
                !is_numeric($sellingPrice) ||
                !is_finite((float) $costPrice) ||
                !is_finite((float) $sellingPrice) ||
                (float) $costPrice < 0 ||
                (float) $sellingPrice < 0
            ) {
                throw new Exception("Please enter valid, non-negative prices.");
            }

            if (
                filter_var($reorderLevel, FILTER_VALIDATE_INT) === false ||
                (int) $reorderLevel < 0
            ) {
                throw new Exception("Reorder level must be a non-negative whole number.");
            }

            $reorderLevel = (int) $reorderLevel;

            // Check if product exists.
            $checkProduct = $pdo->prepare(
                "SELECT id FROM products WHERE id = :id"
            );

            $checkProduct->execute(["id" => $productId]);

            if (!$checkProduct->fetch()) {
                throw new Exception("Product not found.");
            }

            $categoryId = $categoryId === "" ? null : $categoryId;

            if ($categoryId !== null) {

                $checkCategory = $pdo->prepare(
                    "SELECT id FROM categories WHERE id = :id"
                );

                $checkCategory->execute(["id" => $categoryId]);

                if (!$checkCategory->fetch()) {
                    throw new Exception("Selected category does not exist.");
                }
            }

            // Stock quantity is intentionally excluded.
            // Inventory is responsible for stock adjustments.
            $sql = "
                UPDATE products SET
                    product_name = :product_name,
                    sku = :sku,
                    category_id = :category_id,
                    description = :description,
                    cost_price = :cost_price,
                    selling_price = :selling_price,
                    reorder_level = :reorder_level
                WHERE id = :id
            ";

            $stmt = $pdo->prepare($sql);

            $stmt->execute([
                "product_name" => $productName,
                "sku" => $sku,
                "category_id" => $categoryId,
                "description" => $description,
                "cost_price" => $costPrice,
                "selling_price" => $sellingPrice,
                "reorder_level" => $reorderLevel,
                "id" => $productId
            ]);

            $_SESSION["success_message"] = "Product updated successfully!";
        }

        // DELETE PRODUCT
        elseif ($action === "delete") {

            $productId = filter_var(
                $_POST["product_id"] ?? "",
                FILTER_VALIDATE_INT
            );

            if (!$productId) {
                throw new Exception("Invalid product.");
            }

            // Protect historical stock movement records.
            $checkMovements = $pdo->prepare("
                SELECT id
                FROM stock_movements
                WHERE product_id = :product_id
                LIMIT 1
            ");

            $checkMovements->execute([
                "product_id" => $productId
            ]);

            if ($checkMovements->fetch()) {
                throw new Exception(
                    "This product has stock movement history and cannot be deleted."
                );
            }

            $stmt = $pdo->prepare(
                "DELETE FROM products WHERE id = :id"
            );

            $stmt->execute(["id" => $productId]);

            if ($stmt->rowCount() === 0) {
                throw new Exception("Product not found.");
            }

            $_SESSION["success_message"] = "Product deleted successfully!";
        }

        else {
            throw new Exception("Invalid action.");
        }

    } catch (PDOException $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ($e->getCode() === "23000") {
            $_SESSION["error_message"] =
                "This SKU already exists. Please use a different SKU.";
        } else {
            $_SESSION["error_message"] =
                "A database error occurred. Please try again.";
        }

    } catch (Exception $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $_SESSION["error_message"] = $e->getMessage();
    }

    header("Location: index.php");
    exit;
}

// Retrieve categories.
$categoryStmt = $pdo->query("
    SELECT id, category_name
    FROM categories
    ORDER BY category_name ASC
");

$categories = $categoryStmt->fetchAll();

// Retrieve products.
$productSql = "
    SELECT
        p.*,
        c.category_name
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    ORDER BY p.id DESC
";

$productStmt = $pdo->query($productSql);
$products = $productStmt->fetchAll();

// Load shared layout.
require_once "../includes/header.php";
?>

<style>
    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 15px;
        margin-bottom: 25px;
    }

    .content-card {
        background: #fff;
        border-radius: 12px;
        padding: 22px;
        box-shadow: 0 2px 12px rgba(0,0,0,0.04);
    }

    .table th {
        color: #64748b;
        font-size: 13px;
        font-weight: 600;
        white-space: nowrap;
    }

    .table td {
        vertical-align: middle;
    }

    .form-label {
        font-weight: 500;
    }

    .action-buttons {
        display: flex;
        gap: 6px;
    }

    @media (max-width: 576px) {
        .page-header {
            align-items: flex-start;
            flex-direction: column;
        }
    }
</style>

<div class="page-header">

    <div>
        <h3 class="fw-bold mb-1">Products</h3>
        <p class="text-muted mb-0">Manage your inventory products.</p>
    </div>

    <button
        class="btn btn-primary"
        data-bs-toggle="modal"
        data-bs-target="#addProductModal"
    >
        <i class="bi bi-plus-lg me-1"></i> Add Product
    </button>

</div>

<?php if ($successMessage): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <?= escape($successMessage) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($errorMessage): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <?= escape($errorMessage) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="content-card">

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="fw-bold mb-0">Product List</h5>

        <span class="badge text-bg-secondary">
            <?= count($products) ?> Products
        </span>
    </div>

    <div class="table-responsive">

        <table class="table table-hover align-middle">

            <thead>
                <tr>
                    <th>PRODUCT</th>
                    <th>SKU</th>
                    <th>CATEGORY</th>
                    <th>COST PRICE</th>
                    <th>SELLING PRICE</th>
                    <th>STOCK</th>
                    <th>REORDER LEVEL</th>
                    <th>ACTIONS</th>
                </tr>
            </thead>

            <tbody>

                <?php if (count($products) > 0): ?>

                    <?php foreach ($products as $product): ?>

                        <tr>

                            <td>
                                <strong><?= escape($product["product_name"]) ?></strong>

                                <?php if (!empty($product["description"])): ?>
                                    <div class="text-muted small">
                                        <?= escape($product["description"]) ?>
                                    </div>
                                <?php endif; ?>
                            </td>

                            <td><?= escape($product["sku"]) ?></td>

                            <td>
                                <?= escape($product["category_name"] ?? "Uncategorized") ?>
                            </td>

                            <td>
                                ₱<?= number_format((float) $product["cost_price"], 2) ?>
                            </td>

                            <td>
                                ₱<?= number_format((float) $product["selling_price"], 2) ?>
                            </td>

                            <td><?= escape($product["stock_quantity"]) ?></td>

                            <td><?= escape($product["reorder_level"]) ?></td>

                            <td>
                                <div class="action-buttons">

                                    <!-- EDIT BUTTON -->
                                    <button
                                        type="button"
                                        class="btn btn-sm btn-outline-primary"
                                        data-bs-toggle="modal"
                                        data-bs-target="#editProduct<?= (int) $product["id"] ?>"
                                        title="Edit Product"
                                    >
                                        <i class="bi bi-pencil-square"></i>
                                    </button>

                                    <!-- DELETE BUTTON -->
                                    <form
                                        method="POST"
                                        action="index.php"
                                        onsubmit="return confirm('Are you sure you want to delete this product?');"
                                    >

                                        <input
                                            type="hidden"
                                            name="csrf_token"
                                            value="<?= escape($_SESSION["csrf_token"]) ?>"
                                        >

                                        <input type="hidden" name="action" value="delete">

                                        <input
                                            type="hidden"
                                            name="product_id"
                                            value="<?= (int) $product["id"] ?>"
                                        >

                                        <button
                                            type="submit"
                                            class="btn btn-sm btn-outline-danger"
                                            title="Delete Product"
                                        >
                                            <i class="bi bi-trash"></i>
                                        </button>

                                    </form>

                                </div>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="8" class="text-center text-muted py-4">
                            <i class="bi bi-box-seam fs-2 d-block mb-2"></i>
                            No products found. Add your first product!
                        </td>
                    </tr>

                <?php endif; ?>

            </tbody>

        </table>

    </div>

</div>


<!-- ADD PRODUCT MODAL -->
<div class="modal fade" id="addProductModal" tabindex="-1">

    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">

            <form method="POST" action="index.php">

                <div class="modal-header">
                    <h5 class="modal-title fw-bold">Add New Product</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= escape($_SESSION["csrf_token"]) ?>"
                    >

                    <input type="hidden" name="action" value="add">

                    <div class="row g-3">

                        <div class="col-md-6">
                            <label class="form-label">Product Name *</label>
                            <input
                                type="text"
                                name="product_name"
                                class="form-control"
                                maxlength="150"
                                required
                            >
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">SKU *</label>
                            <input
                                type="text"
                                name="sku"
                                class="form-control"
                                maxlength="50"
                                required
                            >
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Category</label>

                            <select name="category_id" class="form-select">
                                <option value="">Select Category</option>

                                <?php foreach ($categories as $category): ?>
                                    <option value="<?= escape($category["id"]) ?>">
                                        <?= escape($category["category_name"]) ?>
                                    </option>
                                <?php endforeach; ?>

                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Description</label>
                            <input
                                type="text"
                                name="description"
                                class="form-control"
                            >
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Cost Price (₱) *</label>
                            <input
                                type="number"
                                name="cost_price"
                                class="form-control"
                                min="0"
                                step="0.01"
                                required
                            >
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Selling Price (₱) *</label>
                            <input
                                type="number"
                                name="selling_price"
                                class="form-control"
                                min="0"
                                step="0.01"
                                required
                            >
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Initial Stock Quantity *</label>
                            <input
                                type="number"
                                name="stock_quantity"
                                class="form-control"
                                min="0"
                                step="1"
                                value="0"
                                required
                            >
                            <small class="text-muted">
                                Initial stock will be recorded in Stock Movements.
                            </small>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Reorder Level *</label>
                            <input
                                type="number"
                                name="reorder_level"
                                class="form-control"
                                min="0"
                                step="1"
                                value="5"
                                required
                            >
                        </div>

                    </div>

                </div>

                <div class="modal-footer">

                    <button
                        type="button"
                        class="btn btn-light"
                        data-bs-dismiss="modal"
                    >
                        Cancel
                    </button>

                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-1"></i> Save Product
                    </button>

                </div>

            </form>

        </div>
    </div>

</div>


<!-- EDIT PRODUCT MODALS -->
<?php foreach ($products as $product): ?>

    <div
        class="modal fade"
        id="editProduct<?= (int) $product["id"] ?>"
        tabindex="-1"
    >

        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">

                <form method="POST" action="index.php">

                    <div class="modal-header">
                        <h5 class="modal-title fw-bold">Edit Product</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body">

                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= escape($_SESSION["csrf_token"]) ?>"
                        >

                        <input type="hidden" name="action" value="edit">

                        <input
                            type="hidden"
                            name="product_id"
                            value="<?= (int) $product["id"] ?>"
                        >

                        <div class="row g-3">

                            <div class="col-md-6">
                                <label class="form-label">Product Name *</label>
                                <input
                                    type="text"
                                    name="product_name"
                                    class="form-control"
                                    maxlength="150"
                                    value="<?= escape($product["product_name"]) ?>"
                                    required
                                >
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">SKU *</label>
                                <input
                                    type="text"
                                    name="sku"
                                    class="form-control"
                                    maxlength="50"
                                    value="<?= escape($product["sku"]) ?>"
                                    required
                                >
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Category</label>

                                <select name="category_id" class="form-select">

                                    <option value="">Select Category</option>

                                    <?php foreach ($categories as $category): ?>

                                        <option
                                            value="<?= escape($category["id"]) ?>"
                                            <?= (string) $product["category_id"] === (string) $category["id"] ? "selected" : "" ?>
                                        >
                                            <?= escape($category["category_name"]) ?>
                                        </option>

                                    <?php endforeach; ?>

                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Description</label>
                                <input
                                    type="text"
                                    name="description"
                                    class="form-control"
                                    value="<?= escape($product["description"]) ?>"
                                >
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Cost Price (₱) *</label>
                                <input
                                    type="number"
                                    name="cost_price"
                                    class="form-control"
                                    min="0"
                                    step="0.01"
                                    value="<?= escape($product["cost_price"]) ?>"
                                    required
                                >
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Selling Price (₱) *</label>
                                <input
                                    type="number"
                                    name="selling_price"
                                    class="form-control"
                                    min="0"
                                    step="0.01"
                                    value="<?= escape($product["selling_price"]) ?>"
                                    required
                                >
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Current Stock Quantity</label>
                                <input
                                    type="number"
                                    class="form-control"
                                    value="<?= escape($product["stock_quantity"]) ?>"
                                    disabled
                                >
                                <small class="text-muted">
                                    Change stock through the Inventory module.
                                </small>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Reorder Level *</label>
                                <input
                                    type="number"
                                    name="reorder_level"
                                    class="form-control"
                                    min="0"
                                    step="1"
                                    value="<?= escape($product["reorder_level"]) ?>"
                                    required
                                >
                            </div>

                        </div>

                    </div>

                    <div class="modal-footer">

                        <button
                            type="button"
                            class="btn btn-light"
                            data-bs-dismiss="modal"
                        >
                            Cancel
                        </button>

                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg me-1"></i> Update Product
                        </button>

                    </div>

                </form>

            </div>
        </div>

    </div>

<?php endforeach; ?>


<?php
// Close the shared layout.
require_once "../includes/footer.php";
?>
