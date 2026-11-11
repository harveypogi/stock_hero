
<?php
session_start();

require_once "../connection/connect.php";

// Only allow POST requests
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: login.php");
    exit;
}

// Get form inputs
$email = trim($_POST["email"] ?? "");
$password = $_POST["password"] ?? "";

// Validate inputs
if ($email === "" || $password === "") {
    $_SESSION["login_error"] = "Please enter your email and password.";
    header("Location: login.php");
    exit;
}

// Find user by email
$sql = "SELECT * FROM users WHERE email = :email LIMIT 1";
$stmt = $pdo->prepare($sql);
$stmt->execute(["email" => $email]);

$user = $stmt->fetch();

// Verify account and password
if (
    !$user ||
    !password_verify($password, $user["password"]) ||
    $user["status"] !== "active"
) {
    $_SESSION["login_error"] = "Invalid email or password.";
    header("Location: login.php");
    exit;
}

// Login successful
session_regenerate_id(true);

$_SESSION["user_id"] = $user["id"];
$_SESSION["user_name"] = $user["name"];
$_SESSION["user_email"] = $user["email"];
$_SESSION["user_role"] = $user["role"];

header("Location: ../dashboard/dashboard.php");
exit;
