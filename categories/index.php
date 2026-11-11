
<?php
session_start();

require_once "../connection/connect.php";

// Check login
if (!isset($_SESSION["user_id"])) {
    header("Location: ../login/login.php");
    exit;
}

// Page settings
$pageTitle = "Categories";

// Generate CSRF token
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$message = "";

// Handle category actions
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // Validate CSRF token
    if (
        !isset($_POST["csrf_token"]) ||
        !hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"])
    ) {
        die("Invalid request. Please refresh the page.");
    }

    // ADD CATEGORY
    if (isset($_POST["add_category"])) {

        $categoryName = trim($_POST["category_name"] ?? "");
        $description = trim($_POST["description"] ?? "");

        if ($categoryName === "") {
            $message = "Category name is required.";
        } else {
            try {
                $sql = "INSERT INTO categories (category_name, description)
                        VALUES (:category_name, :description)";

                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    "category_name" => $categoryName,
                    "description" => $description !== "" ? $description : null
                ]);

                $_SESSION["category_message"] = "Category added successfully!";
                header("Location: index.php");
                exit;

            } catch (PDOException $e) {
                if ($e->getCode() === "23000") {
                    $message = "That category already exists.";
                } else {
                    $message = "Something went wrong while adding the category.";
                }
            }
        }
    }

    // EDIT CATEGORY
    if (isset($_POST["edit_category"])) {

        $categoryId = filter_var(
            $_POST["category_id"] ?? "",
            FILTER_VALIDATE_INT
        );

        $categoryName = trim($_POST["category_name"] ?? "");
        $description = trim($_POST["description"] ?? "");

        if (!$categoryId || $categoryName === "") {
            $message = "Please provide a valid category and name.";
        } else {
            try {
                $sql = "UPDATE categories
                        SET category_name = :category_name,
                            description = :description
                        WHERE id = :id";

                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    "category_name" => $categoryName,
                    "description" => $description !== "" ? $description : null,
                    "id" => $categoryId
                ]);

                $_SESSION["category_message"] =
                    $stmt->rowCount() > 0
                    ? "Category updated successfully!"
                    : "No changes were made.";

                header("Location: index.php");
                exit;

            } catch (PDOException $e) {
                if ($e->getCode() === "23000") {
                    $message = "That category name already exists.";
                } else {
                    $message = "Something went wrong while updating the category.";
                }
            }
        }
    }

    // DELETE CATEGORY
    if (isset($_POST["delete_category"])) {

        $categoryId = filter_var(
            $_POST["category_id"] ?? "",
            FILTER_VALIDATE_INT
        );

        if (!$categoryId) {
            $message = "Invalid category selected.";
        } else {
            try {
                $sql = "DELETE FROM categories WHERE id = :id";
                $stmt = $pdo->prepare($sql);
                $stmt->execute(["id" => $categoryId]);

                $_SESSION["category_message"] =
                    $stmt->rowCount() > 0
                    ? "Category deleted successfully!"
                    : "Category not found.";

                header("Location: index.php");
                exit;

            } catch (PDOException $e) {
                $message = "Unable to delete this category.";
            }
        }
    }
}

// Retrieve success message
if (isset($_SESSION["category_message"])) {
    $message = $_SESSION["category_message"];
    unset($_SESSION["category_message"]);
}

// Retrieve categories
$stmt = $pdo->query("SELECT * FROM categories ORDER BY id DESC");
$categories = $stmt->fetchAll();

// Shared layout
require_once "../includes/header.php";
?>

<style>
    .categories-page {
        padding: 30px;
    }

    .page-title {
        font-weight: bold;
        color: #172554;
    }

    .content-card {
        background: white;
        padding: 25px;
        border-radius: 12px;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
    }

    .categories-page .btn-primary {
        background: #2563eb;
        border: none;
    }

    .categories-page .btn-primary:hover {
        background: #1d4ed8;
    }

    @media (max-width: 576px) {
        .categories-page {
            padding: 18px;
        }
    }
</style>

<div class="categories-page">

    <!-- PAGE HEADER -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="page-title">Categories</h2>
            <p class="text-muted mb-0">
                Manage your product categories.
            </p>
        </div>
    </div>

    <!-- MESSAGE -->
    <?php if ($message !== ""): ?>
        <div class="alert alert-info alert-dismissible fade show">
            <?= htmlspecialchars($message, ENT_QUOTES, "UTF-8") ?>

            <button type="button"
                    class="btn-close"
                    data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- CATEGORY TABLE -->
    <div class="content-card">

        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">

            <h5 class="mb-0">Category List</h5>

            <button class="btn btn-primary"
                    data-bs-toggle="modal"
                    data-bs-target="#addCategoryModal">

                <i class="bi bi-plus-lg"></i>
                Add Category

            </button>

        </div>

        <div class="table-responsive">

            <table class="table table-hover align-middle">

                <thead class="table-light">
                    <tr>
                        <th>ID</th>
                        <th>Category Name</th>
                        <th>Description</th>
                        <th>Date Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>

                <tbody>

                    <?php if (count($categories) > 0): ?>

                        <?php foreach ($categories as $category): ?>

                            <tr>

                                <td>
                                    <?= htmlspecialchars((string)$category["id"], ENT_QUOTES, "UTF-8") ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars($category["category_name"], ENT_QUOTES, "UTF-8") ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars($category["description"] ?? "—", ENT_QUOTES, "UTF-8") ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars($category["created_at"], ENT_QUOTES, "UTF-8") ?>
                                </td>

                                <td>
                                    <div class="d-flex gap-2">

                                        <!-- EDIT BUTTON -->
                                        <button
                                            type="button"
                                            class="btn btn-sm btn-warning"
                                            data-bs-toggle="modal"
                                            data-bs-target="#editCategoryModal<?= (int)$category["id"] ?>">

                                            <i class="bi bi-pencil-square"></i>
                                            Edit

                                        </button>

                                        <!-- DELETE FORM -->
                                        <form method="POST"
                                              action="index.php"
                                              onsubmit="return confirm('Are you sure you want to delete this category?');">

                                            <input type="hidden"
                                                   name="csrf_token"
                                                   value="<?= htmlspecialchars($_SESSION["csrf_token"], ENT_QUOTES, "UTF-8") ?>">

                                            <input type="hidden"
                                                   name="category_id"
                                                   value="<?= (int)$category["id"] ?>">

                                            <button type="submit"
                                                    name="delete_category"
                                                    class="btn btn-sm btn-danger">

                                                <i class="bi bi-trash"></i>
                                                Delete

                                            </button>

                                        </form>

                                    </div>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="5"
                                class="text-center text-muted py-4">

                                No categories available yet.

                            </td>
                        </tr>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

</div>

<!-- ADD CATEGORY MODAL -->
<div class="modal fade" id="addCategoryModal" tabindex="-1">

    <div class="modal-dialog">
        <div class="modal-content">

            <form method="POST" action="index.php">

                <div class="modal-header">
                    <h5 class="modal-title">Add New Category</h5>

                    <button type="button"
                            class="btn-close"
                            data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">

                    <input type="hidden"
                           name="csrf_token"
                           value="<?= htmlspecialchars($_SESSION["csrf_token"], ENT_QUOTES, "UTF-8") ?>">

                    <div class="mb-3">
                        <label class="form-label">Category Name</label>

                        <input type="text"
                               name="category_name"
                               class="form-control"
                               maxlength="100"
                               placeholder="Enter category name"
                               required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Description</label>

                        <textarea name="description"
                                  class="form-control"
                                  rows="3"
                                  placeholder="Enter category description (optional)"></textarea>
                    </div>

                </div>

                <div class="modal-footer">

                    <button type="button"
                            class="btn btn-secondary"
                            data-bs-dismiss="modal">
                        Cancel
                    </button>

                    <button type="submit"
                            name="add_category"
                            class="btn btn-primary">
                        Save Category
                    </button>

                </div>

            </form>

        </div>
    </div>

</div>

<!-- EDIT CATEGORY MODALS -->
<?php foreach ($categories as $category): ?>

    <div class="modal fade"
         id="editCategoryModal<?= (int)$category["id"] ?>"
         tabindex="-1">

        <div class="modal-dialog">
            <div class="modal-content">

                <form method="POST" action="index.php">

                    <div class="modal-header">
                        <h5 class="modal-title">Edit Category</h5>

                        <button type="button"
                                class="btn-close"
                                data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body">

                        <input type="hidden"
                               name="csrf_token"
                               value="<?= htmlspecialchars($_SESSION["csrf_token"], ENT_QUOTES, "UTF-8") ?>">

                        <input type="hidden"
                               name="category_id"
                               value="<?= (int)$category["id"] ?>">

                        <div class="mb-3">
                            <label class="form-label">Category Name</label>

                            <input type="text"
                                   name="category_name"
                                   class="form-control"
                                   maxlength="100"
                                   value="<?= htmlspecialchars($category["category_name"], ENT_QUOTES, "UTF-8") ?>"
                                   required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Description</label>

                            <textarea name="description"
                                      class="form-control"
                                      rows="3"><?= htmlspecialchars($category["description"] ?? "", ENT_QUOTES, "UTF-8") ?></textarea>
                        </div>

                    </div>

                    <div class="modal-footer">

                        <button type="button"
                                class="btn btn-secondary"
                                data-bs-dismiss="modal">
                            Cancel
                        </button>

                        <button type="submit"
                                name="edit_category"
                                class="btn btn-primary">
                            Save Changes
                        </button>

                    </div>

                </form>

            </div>
        </div>

    </div>

<?php endforeach; ?>

<?php require_once "../includes/footer.php"; ?>
