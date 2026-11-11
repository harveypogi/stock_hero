
<?php
session_start();

$_SESSION = [];

session_destroy();

// go to login page
header("Location: login.php");
exit;
