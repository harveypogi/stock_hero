<?php
session_start();


$error = $_SESSION["login_error"] ?? "";
unset($_SESSION["login_error"]);

?>

<!DOCTYPE html>
<html lang = "en" >
<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title> Stock Hero </title>
    
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <link rel="stylesheet" href= "login.css">

</head>
<body>

<div class="login-container" >
    <div class = "login-card">

    <h2>StockHero</h2>
    <p class="subtitle">Inventory Management System</p>

    <form action="authenticate.php" method="POST">

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger">
            <?php echo htmlspecialchars($error); ?>
    </div> 
    <?php endif; ?>

    <div class="mb-3">
        <label for="email" class="form-label">Email Address</label>
        <input

            type= "email"
            class= "form-control"
            id="email"
            name="email"
            placeholder="Enter your email"
            required

            >
</div>

<div class="mb-3">
    <label for="password" class="form-label">Password</label>
    <div class="input-group">
    <input
      type="password"
      class="form-control"
      id="password"
      name="password"
      placeholder="Enter you password"
      required
      >
     
    </button>
      
</div>

<button type="submit" class="btn btn-primary w-100">
    SIGN IN 
</button>

</form>

<p class="footer-text">
    StockHero Inventory Management System
</p>

</div>
</div>
<script src="login.js"></script>
</body>
</html>

