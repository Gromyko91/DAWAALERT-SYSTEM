<?php
require 'db.php';
$message = "";

if(isset($_POST['register'])){
    $name = $_POST['name'];
    $email = $_POST['email'];
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT); // hash password

    // Check if email already exists
    $stmt = $conn->prepare("SELECT id FROM doctors WHERE email=?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $stmt->store_result();

    if($stmt->num_rows > 0){
        $message = "Email already registered!";
    } else {
        $stmt->close();
        $stmt = $conn->prepare("INSERT INTO doctors (name,email,password) VALUES (?,?,?)");
        $stmt->bind_param("sss", $name, $email, $password);
        if($stmt->execute()){
            $message = "Doctor registered successfully! <a href='login.php'>Login here</a>";
        } else {
            $message = "Registration failed. Try again.";
        }
    }
    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dawa Alert - Doctor Registration</title>
<style>
body {font-family: Arial,sans-serif; background:#f4f8fb; display:flex; justify-content:center; align-items:center; height:100vh;}
form {background:white; padding:30px; border-radius:10px; box-shadow:0 4px 10px rgba(0,0,0,0.1);}
input {width:100%; padding:10px; margin-bottom:15px; border-radius:5px; border:1px solid #ccc;}
button {width:100%; padding:10px; background:#2C7BE5; color:white; border:none; border-radius:5px; cursor:pointer;}
.message {color:green; margin-bottom:10px;}
.error {color:red; margin-bottom:10px;}
</style>
</head>
<body>

<form method="POST">
    <h2>Doctor Registration</h2>
    <?php if($message) echo "<div class='message'>$message</div>"; ?>
    <input type="text" name="name" placeholder="Full Name" required>
    <input type="email" name="email" placeholder="Email" required>
    <input type="password" name="password" placeholder="Password" required>
    <button type="submit" name="register">Register</button>
    <p>Already have an account? <a href="login.php">Login here</a></p>
</form>

</body>
</html>