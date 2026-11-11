
<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login/login.php");
    exit;
}

require_once "../connection/connect.php";

// Page settings
$pageTitle = "Suppliers";

// Generate CSRF token
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

// Safe HTML output
function escape($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

// Redirect with flash message
function redirectWithMessage($message, $type = "success") {
    $_SESSION["flash_message"] = $message;
    $_SESSION["flash_type"] = $type;

    header("Location: index.php");
    exit;
}

// Retrieve flash message
$flashMessage = $_SESSION["flash_message"] ?? "";
$flashType = $_SESSION["flash_type"] ?? "success";

unset($_SESSION["flash_message"], $_SESSION["flash_type"]);

// Handle form submissions
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // Validate CSRF token
    if (
        !isset($_POST["csrf_token"]) ||
        !hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"])
    ) {
        redirectWithMessage("Invalid request. Please try again.", "danger");
    }

    $action = $_POST["action"] ?? "";

    // ADD SUPPLIER
    if ($action === "add") {

        $supplierName = trim($_POST["supplier_name"] ?? "");
        $contactPerson = trim($_POST["contact_person"] ?? "");
        $email = trim($_POST["email"] ?? "");
        $phone = trim($_POST["phone"] ?? "");
        $address = trim($_POST["address"] ?? "");
        $notes = trim($_POST["notes"] ?? "");

        if ($supplierName === "") {
            redirectWithMessage("Supplier name is required.", "danger");
        }

        if ($email !== "" && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            redirectWithMessage("Please enter a valid email address.", "danger");
        }

        try {
            $sql = "INSERT INTO suppliers
                    (supplier_name, contact_person, email, phone, address, notes)
                    VALUES
                    (:supplier_name, :contact_person, :email, :phone, :address, :notes)";

            $stmt = $pdo->prepare($sql);

            $stmt->execute([
                "supplier_name" => $supplierName,
                "contact_person" => $contactPerson ?: null,
                "email" => $email ?: null,
                "phone" => $phone ?: null,
                "address" => $address ?: null,
                "notes" => $notes ?: null
            ]);

            redirectWithMessage("Supplier added successfully.");

        } catch (PDOException $e) {
            redirectWithMessage("Unable to add supplier. Please try again.", "danger");
        }
    }

    // EDIT SUPPLIER
    if ($action === "edit") {

        $id = filter_var($_POST["id"] ?? null, FILTER_VALIDATE_INT);
        $supplierName = trim($_POST["supplier_name"] ?? "");
        $contactPerson = trim($_POST["contact_person"] ?? "");
        $email = trim($_POST["email"] ?? "");
        $phone = trim($_POST["phone"] ?? "");
        $address = trim($_POST["address"] ?? "");
        $notes = trim($_POST["notes"] ?? "");

        if (!$id || $supplierName === "") {
            redirectWithMessage("Invalid supplier information.", "danger");
        }

        if ($email !== "" && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            redirectWithMessage("Please enter a valid email address.", "danger");
        }

        try {
            $sql = "UPDATE suppliers SET
                    supplier_name = :supplier_name,
                    contact_person = :contact_person,
                    email = :email,
                    phone = :phone,
                    address = :address,
                    notes = :notes
                    WHERE id = :id";

            $stmt = $pdo->prepare($sql);

            $stmt->execute([
                "supplier_name" => $supplierName,
                "contact_person" => $contactPerson ?: null,
                "email" => $email ?: null,
                "phone" => $phone ?: null,
                "address" => $address ?: null,
                "notes" => $notes ?: null,
                "id" => $id
            ]);

            redirectWithMessage("Supplier updated successfully.");

        } catch (PDOException $e) {
            redirectWithMessage("Unable to update supplier. Please try again.", "danger");
        }
    }

    // DELETE SUPPLIER
    if ($action === "delete") {

        $id = filter_var($_POST["id"] ?? null, FILTER_VALIDATE_INT);

        if (!$id) {
            redirectWithMessage("Invalid supplier ID.", "danger");
        }

        try {
            $stmt = $pdo->prepare("DELETE FROM suppliers WHERE id = :id");
            $stmt->execute(["id" => $id]);

            if ($stmt->rowCount() > 0) {
                redirectWithMessage("Supplier deleted successfully.");
            }

            redirectWithMessage("Supplier not found.", "danger");

        } catch (PDOException $e) {
            redirectWithMessage("Unable to delete supplier.", "danger");
        }
    }

    redirectWithMessage("Invalid action.", "danger");
}

// Retrieve suppliers
$stmt = $pdo->query("SELECT * FROM suppliers ORDER BY created_at DESC");
$suppliers = $stmt->fetchAll();

$totalSuppliers = count($suppliers);

// Shared layout
require_once "../includes/header.php";
?>

<style>
    .suppliers-page {
        padding: 30px;
    }

    .page-title {
        font-weight: 700;
        color: #172554;
    }

    .supplier-card {
        border: none;
        border-radius: 12px;
        box-shadow: 0 3px 15px rgba(0, 0, 0, 0.05);
    }

    .stat-icon {
        width: 48px;
        height: 48px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 10px;
        background: #e0e7ff;
        color: #1e40af;
        font-size: 22px;
    }

    .suppliers-page .table th {
        color: #64748b;
        font-size: 13px;
        white-space: nowrap;
    }

    .suppliers-page .table td {
        vertical-align: middle;
    }

    .supplier-name {
        font-weight: 600;
        color: #172554;
    }

    .search-box {
        max-width: 350px;
    }

    .empty-state {
        padding: 45px 15px;
        text-align: center;
        color: #64748b;
    }

    @media (max-width: 768px) {
        .suppliers-page {
            padding: 20px 12px;
        }
    }
</style>

<div class="suppliers-page">

    <!-- PAGE HEADER -->
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">

        <div>
            <h2 class="page-title mb-1">Suppliers</h2>
            <p class="text-secondary mb-0">
                Manage your product suppliers and contact information.
            </p>
        </div>

        <button
            class="btn btn-primary"
            data-bs-toggle="modal"
            data-bs-target="#addSupplierModal">

            <i class="bi bi-plus-lg me-1"></i>
            Add Supplier

        </button>

    </div>

    <!-- FLASH MESSAGE -->
    <?php if ($flashMessage !== ""): ?>

        <div class="alert alert-<?= escape($flashType) ?> alert-dismissible fade show">

            <?= escape($flashMessage) ?>

            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert">
            </button>

        </div>

    <?php endif; ?>

    <!-- SUMMARY CARD -->
    <div class="row mb-4">

        <div class="col-md-4 col-sm-6">

            <div class="card supplier-card">

                <div class="card-body d-flex align-items-center gap-3">

                    <div class="stat-icon">
                        <i class="bi bi-truck"></i>
                    </div>

                    <div>
                        <p class="text-secondary mb-1">Total Suppliers</p>

                        <h3 class="fw-bold mb-0">
                            <?= number_format($totalSuppliers) ?>
                        </h3>
                    </div>

                </div>

            </div>

        </div>

    </div>

    <!-- SUPPLIER TABLE -->
    <div class="card supplier-card">

        <div class="card-body">

            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">

                <h5 class="fw-bold mb-0">Supplier List</h5>

                <!-- SEARCH -->
                <div class="input-group search-box">

                    <span class="input-group-text bg-white">
                        <i class="bi bi-search"></i>
                    </span>

                    <input
                        type="text"
                        id="searchSupplier"
                        class="form-control"
                        placeholder="Search suppliers...">

                </div>

            </div>

            <div class="table-responsive">

                <table class="table table-hover align-middle" id="supplierTable">

                    <thead>
                        <tr>
                            <th>SUPPLIER</th>
                            <th>CONTACT PERSON</th>
                            <th>EMAIL</th>
                            <th>PHONE</th>
                            <th>ADDRESS</th>
                            <th class="text-center">ACTIONS</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php if (count($suppliers) > 0): ?>

                            <?php foreach ($suppliers as $supplier): ?>

                                <tr>

                                    <td>
                                        <span class="supplier-name">
                                            <?= escape($supplier["supplier_name"]) ?>
                                        </span>
                                    </td>

                                    <td>
                                        <?= escape($supplier["contact_person"] ?: "—") ?>
                                    </td>

                                    <td>
                                        <?= escape($supplier["email"] ?: "—") ?>
                                    </td>

                                    <td>
                                        <?= escape($supplier["phone"] ?: "—") ?>
                                    </td>

                                    <td>
                                        <?= escape($supplier["address"] ?: "—") ?>
                                    </td>

                                    <td class="text-center">

                                        <!-- EDIT BUTTON -->
                                        <button
                                            type="button"
                                            class="btn btn-sm btn-outline-primary me-1"
                                            data-bs-toggle="modal"
                                            data-bs-target="#editSupplierModal<?= (int)$supplier["id"] ?>"
                                            title="Edit">

                                            <i class="bi bi-pencil"></i>

                                        </button>

                                        <!-- DELETE BUTTON -->
                                        <form method="POST"
                                              class="d-inline"
                                              onsubmit="return confirm('Are you sure you want to delete this supplier?');">

                                            <input
                                                type="hidden"
                                                name="csrf_token"
                                                value="<?= escape($_SESSION["csrf_token"]) ?>">

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="delete">

                                            <input
                                                type="hidden"
                                                name="id"
                                                value="<?= (int)$supplier["id"] ?>">

                                            <button
                                                type="submit"
                                                class="btn btn-sm btn-outline-danger"
                                                title="Delete">

                                                <i class="bi bi-trash"></i>

                                            </button>

                                        </form>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        <?php else: ?>

                            <tr id="emptySupplierRow">

                                <td colspan="6">

                                    <div class="empty-state">

                                        <i class="bi bi-truck fs-1 d-block mb-2"></i>

                                        <h6 class="fw-bold">No suppliers found</h6>

                                        <p class="mb-0">
                                            Add your first supplier to get started.
                                        </p>

                                    </div>

                                </td>

                            </tr>

                        <?php endif; ?>

                    </tbody>

                </table>

                <p id="noSearchResults"
                   class="text-center text-secondary py-3 d-none">

                    No matching suppliers found.

                </p>

            </div>

        </div>

    </div>

</div>

<!-- ADD SUPPLIER MODAL -->
<div class="modal fade" id="addSupplierModal" tabindex="-1">

    <div class="modal-dialog modal-lg modal-dialog-centered">

        <div class="modal-content">

            <form method="POST">

                <div class="modal-header">

                    <h5 class="modal-title fw-bold">
                        Add New Supplier
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

                    <input
                        type="hidden"
                        name="action"
                        value="add">

                    <div class="row">

                        <div class="col-md-6 mb-3">
                            <label class="form-label">Supplier Name *</label>

                            <input
                                type="text"
                                name="supplier_name"
                                class="form-control"
                                required
                                maxlength="150"
                                placeholder="Enter supplier name">
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="form-label">Contact Person</label>

                            <input
                                type="text"
                                name="contact_person"
                                class="form-control"
                                maxlength="100"
                                placeholder="Enter contact person">
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="form-label">Email Address</label>

                            <input
                                type="email"
                                name="email"
                                class="form-control"
                                maxlength="100"
                                placeholder="example@email.com">
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="form-label">Phone Number</label>

                            <input
                                type="text"
                                name="phone"
                                class="form-control"
                                maxlength="30"
                                placeholder="Enter phone number">
                        </div>

                        <div class="col-12 mb-3">
                            <label class="form-label">Address</label>

                            <textarea
                                name="address"
                                class="form-control"
                                rows="2"
                                placeholder="Enter supplier address"></textarea>
                        </div>

                        <div class="col-12 mb-3">
                            <label class="form-label">Notes</label>

                            <textarea
                                name="notes"
                                class="form-control"
                                rows="2"
                                placeholder="Additional information (optional)"></textarea>
                        </div>

                    </div>

                </div>

                <div class="modal-footer">

                    <button
                        type="button"
                        class="btn btn-light"
                        data-bs-dismiss="modal">

                        Cancel

                    </button>

                    <button type="submit" class="btn btn-primary">

                        <i class="bi bi-plus-lg me-1"></i>
                        Add Supplier

                    </button>

                </div>

            </form>

        </div>

    </div>

</div>

<!-- EDIT SUPPLIER MODALS -->
<?php foreach ($suppliers as $supplier): ?>

    <div class="modal fade"
         id="editSupplierModal<?= (int)$supplier["id"] ?>"
         tabindex="-1">

        <div class="modal-dialog modal-lg modal-dialog-centered">

            <div class="modal-content">

                <form method="POST">

                    <div class="modal-header">

                        <h5 class="modal-title fw-bold">
                            Edit Supplier
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

                        <input
                            type="hidden"
                            name="action"
                            value="edit">

                        <input
                            type="hidden"
                            name="id"
                            value="<?= (int)$supplier["id"] ?>">

                        <div class="row">

                            <div class="col-md-6 mb-3">
                                <label class="form-label">Supplier Name *</label>

                                <input
                                    type="text"
                                    name="supplier_name"
                                    class="form-control"
                                    required
                                    maxlength="150"
                                    value="<?= escape($supplier["supplier_name"]) ?>">
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label">Contact Person</label>

                                <input
                                    type="text"
                                    name="contact_person"
                                    class="form-control"
                                    maxlength="100"
                                    value="<?= escape($supplier["contact_person"]) ?>">
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label">Email Address</label>

                                <input
                                    type="email"
                                    name="email"
                                    class="form-control"
                                    maxlength="100"
                                    value="<?= escape($supplier["email"]) ?>">
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label">Phone Number</label>

                                <input
                                    type="text"
                                    name="phone"
                                    class="form-control"
                                    maxlength="30"
                                    value="<?= escape($supplier["phone"]) ?>">
                            </div>

                            <div class="col-12 mb-3">
                                <label class="form-label">Address</label>

                                <textarea
                                    name="address"
                                    class="form-control"
                                    rows="2"><?= escape($supplier["address"]) ?></textarea>
                            </div>

                            <div class="col-12 mb-3">
                                <label class="form-label">Notes</label>

                                <textarea
                                    name="notes"
                                    class="form-control"
                                    rows="2"><?= escape($supplier["notes"]) ?></textarea>
                            </div>

                        </div>

                    </div>

                    <div class="modal-footer">

                        <button
                            type="button"
                            class="btn btn-light"
                            data-bs-dismiss="modal">

                            Cancel

                        </button>

                        <button type="submit" class="btn btn-primary">
                            Save Changes
                        </button>

                    </div>

                </form>

            </div>

        </div>

    </div>

<?php endforeach; ?>

<!-- SUPPLIER SEARCH -->
<script>
document.addEventListener("DOMContentLoaded", function () {

    const searchInput = document.getElementById("searchSupplier");
    const table = document.getElementById("supplierTable");
    const noResults = document.getElementById("noSearchResults");

    searchInput.addEventListener("input", function () {

        const searchValue = this.value.toLowerCase().trim();

        const rows = table.querySelectorAll("tbody tr:not(#emptySupplierRow)");

        let visibleCount = 0;

        rows.forEach(function (row) {

            const rowText = row.textContent.toLowerCase();
            const matches = rowText.includes(searchValue);

            row.style.display = matches ? "" : "none";

            if (matches) {
                visibleCount++;
            }

        });

        noResults.classList.toggle("d-none", visibleCount !== 0);

    });

});
</script>

<?php require_once "../includes/footer.php"; ?>
