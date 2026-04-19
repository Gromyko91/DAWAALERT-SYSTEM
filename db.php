<?php
// db.php - Database connection
$host = "localhost";
$user = "root"; // your MySQL username
$pass = "";     // your MySQL password
$db   = "dawa_alert";

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>