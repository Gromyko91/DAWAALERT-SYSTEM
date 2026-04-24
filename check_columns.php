<?php
require 'db.php';

$checks = [
    'doctors.reset_token_hash',
    'doctors.reset_token_expires_at',
    'patients.caregiver_phone',
    'patients.condition_name',
    'patients.age',
    'patients.gender',
    'medications.status',
    'medications.monitoring_start',
    'medications.monitoring_end',
    'medications.status_date',
    'medications.schedule_id',
    'medications.sms_code',
    'medicines.category',
    'reminder_logs.alert_sent',
    'reminder_logs.status',
    'reminder_logs.log_date'
];

foreach($checks as $check_name){
    [$table_name, $field_name] = explode('.', $check_name);
    echo $check_name . ': ' . (dawaAlertColumnExists($conn, $table_name, $field_name) ? 'exists' : 'missing') . PHP_EOL;
}

$conn->close();
?>
