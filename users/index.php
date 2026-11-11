
<?php
session_start();

require_once "../connection/connect.php";

// Only logged-in administrators can access this page.
if (!isset($_SESSION["user_id"])) {
    header("Location: ../login/login.php");
    exit;
}

if (($_SESSION["user_role"] ?? "") !== "admin") {
    http_response_code(403);
    exit("Access denied. Admins only.");
}

// Prevent browser caching of this protected page.
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

$pageTitle = "User Management";

$currentUserId = (int) $_SESSION["user_id"];

// Generate CSRF token.
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION["csrf_token"];

// Helper function for safe HTML output.
function e($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

// Flash messages.
$successMessage = $_SESSION["user_success"] ?? "";
$errorMessage = $_SESSION["user_error"] ?? "";

unset($_SESSION["user_success"], $_SESSION["user_error"]);

// Handle form submissions.
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $submittedToken = $_POST["csrf_token"] ?? "";

    if (!hash_equals($csrfToken, $submittedToken)) {
        $_SESSION["user_error"] = "Invalid request. Please try again.";
        header("Location: index.php");
        exit;
    }

    $action = $_POST["action"] ?? "";

    try {

        // ADD USER
        if ($action === "add") {

            $name = trim($_POST["name"] ?? "");
            $email = trim($_POST["email"] ?? "");
            $password = $_POST["password"] ?? "";
            $role = $_POST["role"] ?? "staff";

            if ($name === "" || $email === "" || $password === "") {
                throw new Exception("Please complete all required fields.");
            }

            if (strlen($name) > 100 || strlen($email) > 100) {
                throw new Exception("Name or email address is too long.");
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception("Please enter a valid email address.");
            }

            if (strlen($password) < 8) {
                throw new Exception("Password must contain at least 8 characters.");
            }

            if (!in_array($role, ["admin", "staff"], true)) {
                throw new Exception("Invalid user role.");
            }

            $checkEmail = $pdo->prepare("
                SELECT id
                FROM users
                WHERE email = :email
                LIMIT 1
            ");

            $checkEmail->execute(["email" => $email]);

            if ($checkEmail->fetch()) {
                throw new Exception("That email address is already registered.");
            }

            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $pdo->prepare("
                INSERT INTO users (name, email, password, role, status)
                VALUES (:name, :email, :password, :role, 'active')
            ");

            $stmt->execute([
                "name" => $name,
                "email" => $email,
                "password" => $hashedPassword,
                "role" => $role
            ]);

            $_SESSION["user_success"] = "User account created successfully.";
        }

        // UPDATE USER DETAILS
        elseif ($action === "update") {

            $userId = (int)($_POST["user_id"] ?? 0);
            $name = trim($_POST["name"] ?? "");
            $email = trim($_POST["email"] ?? "");
            $role = $_POST["role"] ?? "";

            if ($userId <= 0 || $name === "" || $email === "") {
                throw new Exception("Please complete all required fields.");
            }

            if (strlen($name) > 100 || strlen($email) > 100) {
                throw new Exception("Name or email address is too long.");
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception("Please enter a valid email address.");
            }

            if (!in_array($role, ["admin", "staff"], true)) {
                throw new Exception("Invalid user role.");
            }

            if ($userId === $currentUserId && $role !== "admin") {
                throw new Exception("You cannot change your own administrator role.");
            }

            $checkEmail = $pdo->prepare("
                SELECT id
                FROM users
                WHERE email = :email AND id != :id
                LIMIT 1
            ");

            $checkEmail->execute([
                "email" => $email,
                "id" => $userId
            ]);

            if ($checkEmail->fetch()) {
                throw new Exception("That email address is already used by another account.");
            }

            // Prevent removing the last active administrator.
            $targetStmt = $pdo->prepare("
                SELECT id, role, status
                FROM users
                WHERE id = :id
                LIMIT 1
            ");

            $targetStmt->execute(["id" => $userId]);
            $targetUser = $targetStmt->fetch(PDO::FETCH_ASSOC);

            if (!$targetUser) {
                throw new Exception("User account not found.");
            }

            if (
                $targetUser["role"] === "admin" &&
                $targetUser["status"] === "active" &&
                $role === "staff"
            ) {
                $adminCount = $pdo->query("
                    SELECT COUNT(*)
                    FROM users
                    WHERE role = 'admin' AND status = 'active'
                ")->fetchColumn();

                if ((int)$adminCount <= 1) {
                    throw new Exception(
                        "You cannot remove the role of the last active administrator."
                    );
                }
            }

            $stmt = $pdo->prepare("
                UPDATE users
                SET name = :name, email = :email, role = :role
                WHERE id = :id
            ");

            $stmt->execute([
                "name" => $name,
                "email" => $email,
                "role" => $role,
                "id" => $userId
            ]);

            // Update current session information if editing yourself.
            if ($userId === $currentUserId) {
                $_SESSION["user_name"] = $name;
                $_SESSION["user_email"] = $email;
            }

            $_SESSION["user_success"] = "User information updated successfully.";
        }

        // ACTIVATE / DEACTIVATE USER
        elseif ($action === "toggle_status") {

            $userId = (int)($_POST["user_id"] ?? 0);

            if ($userId <= 0) {
                throw new Exception("Invalid user account.");
            }

            if ($userId === $currentUserId) {
                throw new Exception("You cannot deactivate your own account.");
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                SELECT id, role, status
                FROM users
                WHERE id = :id
                FOR UPDATE
            ");

            $stmt->execute(["id" => $userId]);
            $targetUser = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$targetUser) {
                throw new Exception("User account not found.");
            }

            $newStatus = $targetUser["status"] === "active"
                ? "inactive"
                : "active";

            // Do not deactivate the last active administrator.
            if (
                $targetUser["role"] === "admin" &&
                $targetUser["status"] === "active" &&
                $newStatus === "inactive"
            ) {
                $adminCheck = $pdo->query("
                    SELECT COUNT(*)
                    FROM users
                    WHERE role = 'admin' AND status = 'active'
                ");

                if ((int)$adminCheck->fetchColumn() <= 1) {
                    throw new Exception(
                        "You cannot deactivate the last active administrator."
                    );
                }
            }

            $updateStatus = $pdo->prepare("
                UPDATE users
                SET status = :status
                WHERE id = :id
            ");

            $updateStatus->execute([
                "status" => $newStatus,
                "id" => $userId
            ]);

            $pdo->commit();

            $_SESSION["user_success"] = "User status updated successfully.";
        }

        // RESET PASSWORD
        elseif ($action === "reset_password") {

            $userId = (int)($_POST["user_id"] ?? 0);
            $newPassword = $_POST["new_password"] ?? "";

            if ($userId <= 0 || $newPassword === "") {
                throw new Exception("Please enter a new password.");
            }

            if (strlen($newPassword) < 8) {
                throw new Exception("Password must contain at least 8 characters.");
            }

            $checkUser = $pdo->prepare("
                SELECT id
                FROM users
                WHERE id = :id
                LIMIT 1
            ");

            $checkUser->execute(["id" => $userId]);

            if (!$checkUser->fetch()) {
                throw new Exception("User account not found.");
            }

            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

            $stmt = $pdo->prepare("
                UPDATE users
                SET password = :password
                WHERE id = :id
            ");

            $stmt->execute([
                "password" => $hashedPassword,
                "id" => $userId
            ]);

            $_SESSION["user_success"] = "User password reset successfully.";
        }

        else {
            throw new Exception("Invalid action.");
        }

    } catch (PDOException $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $_SESSION["user_error"] = "A database error occurred. Please try again.";

    } catch (Exception $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $_SESSION["user_error"] = $e->getMessage();
    }

    header("Location: index.php");
    exit;
}

// Retrieve users.
$stmt = $pdo->query("
    SELECT id, name, email, role, status, created_at
    FROM users
    ORDER BY created_at DESC, id DESC
");

$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Summary counts.
$totalUsers = count($users);
$activeUsers = 0;
$adminUsers = 0;
$staffUsers = 0;

foreach ($users as $user) {

    if ($user["status"] === "active") {
        $activeUsers++;
    }

    if ($user["role"] === "admin") {
        $adminUsers++;
    } else {
        $staffUsers++;
    }
}

// Load shared header.
require_once "../includes/header.php";
?>

<style>
    .users-page {
        padding-bottom: 25px;
    }

    .page-header {
        margin-bottom: 25px;
    }

    .stat-card,
    .content-card {
        border: none;
        border-radius: 12px;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.04);
    }

    .stat-icon {
        width: 45px;
        height: 45px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 10px;
        font-size: 20px;
        flex-shrink: 0;
    }

    .users-table th {
        color: #64748b;
        font-size: 13px;
        font-weight: 600;
        white-space: nowrap;
    }

    .users-table td {
        vertical-align: middle;
    }

    .user-avatar {
        width: 38px;
        height: 38px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        background: #e8efff;
        color: #3157c8;
        font-weight: bold;
        flex-shrink: 0;
    }

    .action-btn {
        width: 34px;
        height: 34px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }

    #userSearch {
        max-width: 280px;
    }

    @media (max-width: 576px) {
        #userSearch {
            max-width: 100%;
            width: 100%;
        }
    }
</style>

<div class="users-page">

    <!-- PAGE HEADER -->
    <div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-3">

        <div>
            <h3 class="fw-bold mb-1">User Management</h3>
            <p class="text-muted mb-0">
                Manage StockHero administrator and staff accounts.
            </p>
        </div>

        <button
            class="btn btn-primary"
            data-bs-toggle="modal"
            data-bs-target="#addUserModal"
        >
            <i class="bi bi-person-plus me-1"></i>
            Add User
        </button>

    </div>

    <!-- FLASH MESSAGES -->
    <?php if ($successMessage): ?>

        <div class="alert alert-success alert-dismissible fade show">
            <i class="bi bi-check-circle me-2"></i>
            <?= e($successMessage); ?>

            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert"
            ></button>
        </div>

    <?php endif; ?>

    <?php if ($errorMessage): ?>

        <div class="alert alert-danger alert-dismissible fade show">
            <i class="bi bi-exclamation-circle me-2"></i>
            <?= e($errorMessage); ?>

            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert"
            ></button>
        </div>

    <?php endif; ?>

    <!-- SUMMARY CARDS -->
    <div class="row g-3 mb-4">

        <div class="col-12 col-sm-6 col-xl-3">

            <div class="card stat-card p-3 h-100">

                <div class="d-flex align-items-center gap-3">

                    <div class="stat-icon bg-primary-subtle text-primary">
                        <i class="bi bi-people"></i>
                    </div>

                    <div>
                        <p class="text-muted mb-1 small">Total Users</p>
                        <h4 class="fw-bold mb-0">
                            <?= number_format($totalUsers); ?>
                        </h4>
                    </div>

                </div>

            </div>

        </div>

        <div class="col-12 col-sm-6 col-xl-3">

            <div class="card stat-card p-3 h-100">

                <div class="d-flex align-items-center gap-3">

                    <div class="stat-icon bg-success-subtle text-success">
                        <i class="bi bi-person-check"></i>
                    </div>

                    <div>
                        <p class="text-muted mb-1 small">Active Users</p>
                        <h4 class="fw-bold mb-0">
                            <?= number_format($activeUsers); ?>
                        </h4>
                    </div>

                </div>

            </div>

        </div>

        <div class="col-12 col-sm-6 col-xl-3">

            <div class="card stat-card p-3 h-100">

                <div class="d-flex align-items-center gap-3">

                    <div class="stat-icon bg-warning-subtle text-warning">
                        <i class="bi bi-shield-lock"></i>
                    </div>

                    <div>
                        <p class="text-muted mb-1 small">Administrators</p>
                        <h4 class="fw-bold mb-0">
                            <?= number_format($adminUsers); ?>
                        </h4>
                    </div>

                </div>

            </div>

        </div>

        <div class="col-12 col-sm-6 col-xl-3">

            <div class="card stat-card p-3 h-100">

                <div class="d-flex align-items-center gap-3">

                    <div class="stat-icon bg-info-subtle text-info">
                        <i class="bi bi-person-badge"></i>
                    </div>

                    <div>
                        <p class="text-muted mb-1 small">Staff Accounts</p>
                        <h4 class="fw-bold mb-0">
                            <?= number_format($staffUsers); ?>
                        </h4>
                    </div>

                </div>

            </div>

        </div>

    </div>

    <!-- USER TABLE -->
    <div class="card content-card">

        <div class="card-header bg-white border-0 p-3">

            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">

                <h5 class="fw-bold mb-0">Registered Users</h5>

                <input
                    type="search"
                    id="userSearch"
                    class="form-control"
                    placeholder="Search users..."
                    aria-label="Search users"
                >

            </div>

        </div>

        <div class="card-body p-0">

            <div class="table-responsive">

                <table class="table table-hover mb-0 users-table" id="usersTable">

                    <thead class="table-light">

                        <tr>
                            <th class="ps-3">USER</th>
                            <th>EMAIL</th>
                            <th>ROLE</th>
                            <th>STATUS</th>
                            <th>DATE CREATED</th>
                            <th class="text-center">ACTIONS</th>
                        </tr>

                    </thead>

                    <tbody>

                        <?php if (empty($users)): ?>

                            <tr>
                                <td colspan="6" class="text-center py-5 text-muted">
                                    No users found.
                                </td>
                            </tr>

                        <?php else: ?>

                            <?php foreach ($users as $user): ?>

                                <tr>

                                    <td class="ps-3">

                                        <div class="d-flex align-items-center gap-2">

                                            <div class="user-avatar">
                                                <?= e(strtoupper(substr($user["name"], 0, 1))); ?>
                                            </div>

                                            <div>

                                                <div class="fw-semibold">
                                                    <?= e($user["name"]); ?>
                                                </div>

                                                <?php if ((int)$user["id"] === $currentUserId): ?>
                                                    <small class="text-primary">You</small>
                                                <?php endif; ?>

                                            </div>

                                        </div>

                                    </td>

                                    <td><?= e($user["email"]); ?></td>

                                    <td>

                                        <?php if ($user["role"] === "admin"): ?>

                                            <span class="badge text-bg-primary">
                                                Admin
                                            </span>

                                        <?php else: ?>

                                            <span class="badge text-bg-secondary">
                                                Staff
                                            </span>

                                        <?php endif; ?>

                                    </td>

                                    <td>

                                        <?php if ($user["status"] === "active"): ?>

                                            <span class="badge text-bg-success">
                                                Active
                                            </span>

                                        <?php else: ?>

                                            <span class="badge text-bg-danger">
                                                Inactive
                                            </span>

                                        <?php endif; ?>

                                    </td>

                                    <td>
                                        <?= e(date("M d, Y", strtotime($user["created_at"]))); ?>
                                    </td>

                                    <td class="text-center">

                                        <!-- EDIT USER -->
                                        <button
                                            type="button"
                                            class="btn btn-outline-primary btn-sm action-btn"
                                            data-bs-toggle="modal"
                                            data-bs-target="#editUser<?= (int)$user["id"]; ?>"
                                            title="Edit user"
                                        >
                                            <i class="bi bi-pencil"></i>
                                        </button>

                                        <!-- RESET PASSWORD -->
                                        <button
                                            type="button"
                                            class="btn btn-outline-warning btn-sm action-btn"
                                            data-bs-toggle="modal"
                                            data-bs-target="#resetPassword<?= (int)$user["id"]; ?>"
                                            title="Reset password"
                                        >
                                            <i class="bi bi-key"></i>
                                        </button>

                                        <!-- ACTIVATE / DEACTIVATE -->
                                        <?php if ((int)$user["id"] !== $currentUserId): ?>

                                            <form
                                                method="POST"
                                                class="d-inline"
                                                onsubmit="return confirm('Are you sure you want to <?= $user["status"] === "active" ? "deactivate" : "activate"; ?> this account?');"
                                            >

                                                <input
                                                    type="hidden"
                                                    name="csrf_token"
                                                    value="<?= e($csrfToken); ?>"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="action"
                                                    value="toggle_status"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="user_id"
                                                    value="<?= (int)$user["id"]; ?>"
                                                >

                                                <?php if ($user["status"] === "active"): ?>

                                                    <button
                                                        type="submit"
                                                        class="btn btn-outline-danger btn-sm action-btn"
                                                        title="Deactivate user"
                                                    >
                                                        <i class="bi bi-person-x"></i>
                                                    </button>

                                                <?php else: ?>

                                                    <button
                                                        type="submit"
                                                        class="btn btn-outline-success btn-sm action-btn"
                                                        title="Activate user"
                                                    >
                                                        <i class="bi bi-person-check"></i>
                                                    </button>

                                                <?php endif; ?>

                                            </form>

                                        <?php endif; ?>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </div>

    </div>

</div>

<!-- EDIT AND RESET PASSWORD MODALS -->
<?php foreach ($users as $user): ?>

    <!-- EDIT USER MODAL -->
    <div
        class="modal fade"
        id="editUser<?= (int)$user["id"]; ?>"
        tabindex="-1"
        aria-hidden="true"
    >

        <div class="modal-dialog">

            <div class="modal-content">

                <form method="POST">

                    <div class="modal-header">

                        <h5 class="modal-title">Edit User</h5>

                        <button
                            type="button"
                            class="btn-close"
                            data-bs-dismiss="modal"
                        ></button>

                    </div>

                    <div class="modal-body">

                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= e($csrfToken); ?>"
                        >

                        <input
                            type="hidden"
                            name="action"
                            value="update"
                        >

                        <input
                            type="hidden"
                            name="user_id"
                            value="<?= (int)$user["id"]; ?>"
                        >

                        <div class="mb-3">

                            <label class="form-label">Full Name</label>

                            <input
                                type="text"
                                name="name"
                                class="form-control"
                                maxlength="100"
                                required
                                value="<?= e($user["name"]); ?>"
                            >

                        </div>

                        <div class="mb-3">

                            <label class="form-label">Email Address</label>

                            <input
                                type="email"
                                name="email"
                                class="form-control"
                                maxlength="100"
                                required
                                value="<?= e($user["email"]); ?>"
                            >

                        </div>

                        <div class="mb-3">

                            <label class="form-label">Role</label>

                            <select
                                name="role"
                                class="form-select"
                                <?= (int)$user["id"] === $currentUserId ? "disabled" : ""; ?>
                            >

                                <option
                                    value="admin"
                                    <?= $user["role"] === "admin" ? "selected" : ""; ?>
                                >
                                    Administrator
                                </option>

                                <option
                                    value="staff"
                                    <?= $user["role"] === "staff" ? "selected" : ""; ?>
                                >
                                    Staff
                                </option>

                            </select>

                            <?php if ((int)$user["id"] === $currentUserId): ?>

                                <input
                                    type="hidden"
                                    name="role"
                                    value="admin"
                                >

                                <small class="text-muted">
                                    You cannot change your own administrator role.
                                </small>

                            <?php endif; ?>

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
                            Save Changes
                        </button>

                    </div>

                </form>

            </div>

        </div>

    </div>

    <!-- RESET PASSWORD MODAL -->
    <div
        class="modal fade"
        id="resetPassword<?= (int)$user["id"]; ?>"
        tabindex="-1"
        aria-hidden="true"
    >

        <div class="modal-dialog">

            <div class="modal-content">

                <form method="POST">

                    <div class="modal-header">

                        <h5 class="modal-title">Reset User Password</h5>

                        <button
                            type="button"
                            class="btn-close"
                            data-bs-dismiss="modal"
                        ></button>

                    </div>

                    <div class="modal-body">

                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= e($csrfToken); ?>"
                        >

                        <input
                            type="hidden"
                            name="action"
                            value="reset_password"
                        >

                        <input
                            type="hidden"
                            name="user_id"
                            value="<?= (int)$user["id"]; ?>"
                        >

                        <p class="text-muted">
                            Set a new password for
                            <strong><?= e($user["name"]); ?></strong>.
                        </p>

                        <div class="mb-3">

                            <label class="form-label">New Password</label>

                            <input
                                type="password"
                                name="new_password"
                                class="form-control"
                                minlength="8"
                                required
                            >

                            <small class="text-muted">
                                Password must contain at least 8 characters.
                            </small>

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

                        <button type="submit" class="btn btn-warning">
                            Reset Password
                        </button>

                    </div>

                </form>

            </div>

        </div>

    </div>

<?php endforeach; ?>

<!-- ADD USER MODAL -->
<div class="modal fade" id="addUserModal" tabindex="-1" aria-hidden="true">

    <div class="modal-dialog">

        <div class="modal-content">

            <form method="POST">

                <div class="modal-header">

                    <h5 class="modal-title">Add New User</h5>

                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"
                    ></button>

                </div>

                <div class="modal-body">

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= e($csrfToken); ?>"
                    >

                    <input
                        type="hidden"
                        name="action"
                        value="add"
                    >

                    <div class="mb-3">

                        <label class="form-label">Full Name</label>

                        <input
                            type="text"
                            name="name"
                            class="form-control"
                            maxlength="100"
                            required
                        >

                    </div>

                    <div class="mb-3">

                        <label class="form-label">Email Address</label>

                        <input
                            type="email"
                            name="email"
                            class="form-control"
                            maxlength="100"
                            required
                        >

                    </div>

                    <div class="mb-3">

                        <label class="form-label">Password</label>

                        <input
                            type="password"
                            name="password"
                            class="form-control"
                            minlength="8"
                            required
                        >

                        <small class="text-muted">
                            Password must contain at least 8 characters.
                        </small>

                    </div>

                    <div class="mb-3">

                        <label class="form-label">User Role</label>

                        <select name="role" class="form-select" required>

                            <option value="staff" selected>Staff</option>
                            <option value="admin">Administrator</option>

                        </select>

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
                        <i class="bi bi-person-plus me-1"></i>
                        Create Account
                    </button>

                </div>

            </form>

        </div>

    </div>

</div>

<!-- SEARCH USERS -->
<script>
document.addEventListener("DOMContentLoaded", function () {

    const searchInput = document.getElementById("userSearch");
    const tableRows = document.querySelectorAll("#usersTable tbody tr");

    searchInput.addEventListener("input", function () {

        const searchValue = this.value.toLowerCase().trim();

        tableRows.forEach(function (row) {

            const rowText = row.textContent.toLowerCase();

            row.style.display = rowText.includes(searchValue) ? "" : "none";

        });

    });

});
</script>

<?php
// Load shared footer.
require_once "../includes/footer.php";
?>
