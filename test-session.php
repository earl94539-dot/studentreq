<?php
session_start();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Session Test</title>
</head>
<body>
    <h1>Session Test</h1>
    <pre>
<?php
var_dump($_SESSION);
?>
    </pre>
    <p><a href="login.php">Go to Login</a></p>
    <p><a href="admin/dashboard.php">Go to Admin Dashboard</a></p>
</body>
</html>
