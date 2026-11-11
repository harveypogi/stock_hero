
<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pageTitle = $pageTitle ?? "StockHero";
$userName = $_SESSION["user_name"] ?? "User";
$userRole = $_SESSION["user_role"] ?? "staff";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, "UTF-8") ?> | StockHero</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <?php if (!empty($pageStylesheet)): ?>
    <link rel="stylesheet" href="<?= htmlspecialchars($pageStylesheet, ENT_QUOTES, 'UTF-8') ?>">
<?php endif; ?>

    <style>
        body {
            background: #f5f7fb;
            font-family: Arial, sans-serif;
        }

        .stockhero-layout {
            display: flex;
            min-height: 100vh;
        }

        .stockhero-main {
            flex: 1;
            min-width: 0;
        }

        .stockhero-topbar {
            height: 70px;
            background: #fff;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 25px;
        }

        .stockhero-content {
            padding: 25px;
        }

        .topbar-user {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .topbar-avatar {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: #dbeafe;
            color: #1d4ed8;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
        }

        @media (max-width: 768px) {
            .stockhero-layout {
                flex-direction: column;
            }

            .stockhero-sidebar {
                width: 100%;
                min-height: auto;
            }

            .stockhero-content {
                padding: 15px;
            }

            .stockhero-topbar {
                padding: 0 15px;
            }
        }
    </style>
</head>

<body>

<div class="stockhero-layout">

    <?php require __DIR__ . "/sidebar.php"; ?>

    <div class="stockhero-main">

        <header class="stockhero-topbar">

            <div>
                <span class="fw-semibold text-dark">
                    <?= htmlspecialchars($pageTitle, ENT_QUOTES, "UTF-8") ?>
                </span>
            </div>

            <div class="dropdown">

                <button
                    class="btn btn-light dropdown-toggle topbar-user"
                    type="button"
                    data-bs-toggle="dropdown"
                    aria-expanded="false"
                >

                    <span class="topbar-avatar">
                        <?= htmlspecialchars(strtoupper(substr($userName, 0, 1)), ENT_QUOTES, "UTF-8") ?>
                    </span>

                    <span class="text-start">
                        <span class="d-block fw-semibold small">
                            <?= htmlspecialchars($userName, ENT_QUOTES, "UTF-8") ?>
                        </span>

                        <span class="d-block text-muted small">
                            <?= htmlspecialchars(ucfirst($userRole), ENT_QUOTES, "UTF-8") ?>
                        </span>
                    </span>

                </button>

                <ul class="dropdown-menu dropdown-menu-end">

                    <li>
                        <a class="dropdown-item" href="../dashboard/dashboard.php">
                            <i class="bi bi-grid me-2"></i> Dashboard
                        </a>
                    </li>

                    <li><hr class="dropdown-divider"></li>

                    <li>
                        <a class="dropdown-item text-danger" href="../login/logout.php">
                            <i class="bi bi-box-arrow-right me-2"></i> Logout
                        </a>
                    </li>

                </ul>

            </div>

        </header>

        <main class="stockhero-content">
