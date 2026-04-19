<?php
session_start();
require 'db.php';
$error = "";

if(isset($_SESSION['login_error'])){
    $error = $_SESSION['login_error'];
    unset($_SESSION['login_error']);
}

if(isset($_POST['login'])){
    $email = $_POST['email'];
    $password = $_POST['password'];

    $stmt = $conn->prepare("SELECT * FROM doctors WHERE email=?");
    $stmt->bind_param("s",$email);
    $stmt->execute();
    $result = $stmt->get_result();

    if($result->num_rows === 1){
        $doctor = $result->fetch_assoc();
        if(password_verify($password,$doctor['password'])){
            $_SESSION['doctor_id'] = $doctor['id'];
            $_SESSION['doctor_name'] = $doctor['name'];
            header("Location: dashboard.php");
            exit();
        } else {
            $_SESSION['login_error'] = "Invalid password.";
            header("Location: login.php");
            exit();
        }
    } else {
        $_SESSION['login_error'] = "Doctor not found.";
        header("Location: login.php");
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dawa Alert - Doctor Login</title>
<style>
body {font-family: Arial,sans-serif; background:#f4f8fb; display:flex; justify-content:center; align-items:center; height:100vh;}
form {background:white; padding:30px; border-radius:10px; box-shadow:0 4px 10px rgba(0,0,0,0.1);}
input {width:100%; padding:10px; margin-bottom:15px; border-radius:5px; border:1px solid #ccc;}
button {width:100%; padding:10px; background:#2C7BE5; color:white; border:none; border-radius:5px; cursor:pointer;}
.error {color:red; margin-bottom:10px;}
</style>
</head>
<body>

<form method="POST">
    <h2>Doctor Login</h2>
    <?php if($error) echo "<div class='error'>$error</div>"; ?>
    <input type="email" name="email" placeholder="Email" required>
    <input type="password" name="password" placeholder="Password" required>
    <button type="submit" name="login">Login</button>
    <p>Don't have an account? <a href="register.php">Register here</a></p>
    <a href="forgotpassword.php">Forgot Password?</a>
</form>
</body>
</html>
