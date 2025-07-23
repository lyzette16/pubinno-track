<?php
// File: /Bweset/index.php
session_start();
require_once 'config/config.php';

// Force logout on load for testing/demo purposes
session_unset();
session_destroy();

header("Location: auth/login.php");
exit();
?>
