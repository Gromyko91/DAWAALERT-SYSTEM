<?php
require 'db.php';

mysqli_report(MYSQLI_REPORT_OFF);

$conn->query("
CREATE TABLE IF NOT EXISTS reminder_logs (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    medication_id INT(11) NOT NULL,
    patient_id INT(11) NOT NULL,
    reminder_sent_at DATETIME NOT NULL,
    log_date DATE DEFAULT NULL,
    alert_sent TINYINT(1) DEFAULT 0,
    status VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY (reminder_sent_at),
    KEY (patient_id)
)");

$checks = [
    "ALTER TABLE patients ADD COLUMN age INT" => "patients.age",
    "ALTER TABLE patients ADD COLUMN gender VARCHAR(10)" => "patients.gender",
    "ALTER TABLE medications ADD COLUMN monitoring_start DATE" => "medications.monitoring_start",
    "ALTER TABLE medications ADD COLUMN monitoring_end DATE" => "medications.monitoring_end",
    "ALTER TABLE medications ADD COLUMN status_date DATE DEFAULT NULL" => "medications.status_date",
    "ALTER TABLE reminder_logs ADD COLUMN log_date DATE DEFAULT NULL" => "reminder_logs.log_date"
];

foreach($checks as $sql => $column_name){
    [$table_name, $field_name] = explode('.', $column_name);
    $result = $conn->query("SHOW COLUMNS FROM {$table_name} LIKE '{$field_name}'");
    if($result && $result->num_rows === 0){
        $conn->query($sql);
    }
}

$today = date('Y-m-d');
$conn->query("UPDATE medications SET monitoring_start = '{$today}' WHERE monitoring_start IS NULL");
$conn->query("UPDATE medications SET monitoring_end = monitoring_start WHERE monitoring_end IS NULL");

echo "Database schema updated.\n";
$conn->close();
?>
