<?php
require 'db.php';

$checks = [
    'patients.age',
    'patients.gender',
    'medications.monitoring_start',
    'medications.monitoring_end',
    'medications.status_date',
    'reminder_logs.log_date'
];

foreach($checks as $check_name){
    [$table_name, $field_name] = explode('.', $check_name);
    $result = $conn->query("SHOW COLUMNS FROM {$table_name} LIKE '{$field_name}'");
    echo $check_name . ': ' . (($result && $result->num_rows > 0) ? 'exists' : 'missing') . PHP_EOL;
}

$conn->close();
?>
