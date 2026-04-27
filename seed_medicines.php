<?php
require 'db.php';
seedMedicineCatalog($conn, true);
$conn->close();

echo "Medicine catalog seeded or updated." . PHP_EOL;
