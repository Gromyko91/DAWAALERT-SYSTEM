<?php
require 'db.php';
require 'reminder_helpers.php';
$config_path = __DIR__ . '/app_config.php';

if(!file_exists($config_path)){
    echo "Missing app_config.php. Copy app_config.example.php to app_config.php and add Africa's Talking sandbox credentials.\n";
    $conn->close();
    exit();
}

$config = require $config_path;

// Africa's Talking sandbox credentials
$at_username = $config['africastalking']['username'];
$at_api_key = $config['africastalking']['api_key'];
$at_sms_endpoint = $config['africastalking']['sms_endpoint'];

if(trim($at_api_key) === '' || $at_api_key === 'your-africas-talking-api-key'){
    echo "Africa's Talking sandbox API key is not configured in app_config.php.\n";
    $conn->close();
    exit();
}

function sendAfricaTalkingSms($phone, $message, $username, $api_key, $endpoint){
    $payload = http_build_query([
        'username' => $username,
        'to' => $phone,
        'message' => $message
    ]);

    $ch = curl_init($endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: application/json',
        'Content-Type: application/x-www-form-urlencoded',
        'apiKey: ' . $api_key
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    $status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if($error){
        return [
            'success' => false,
            'status_code' => $status_code,
            'error' => $error,
            'response' => null
        ];
    }

    $decoded = json_decode($response, true);

    return [
        'success' => $status_code >= 200 && $status_code < 300,
        'status_code' => $status_code,
        'error' => null,
        'response' => $decoded ?: $response
    ];
}

// Current time
$today = date('Y-m-d');
$now = date('H:i:s');
$now_datetime = date('Y-m-d H:i:s');
syncOverdueMedicationStatuses($conn);

// 2️⃣ Handle incoming SMS replies in the format 1-1234 or 2-1234
$incoming_reply = null;
$incoming_phone = null;

if(isset($_POST['text']) && isset($_POST['from'])){
    $incoming_reply = trim($_POST['text']);
    $incoming_phone = trim($_POST['from']);
} elseif(isset($_POST['sms_reply']) && isset($_POST['from'])){
    $incoming_reply = trim($_POST['sms_reply']);
    $incoming_phone = trim($_POST['from']);
}

if($incoming_reply !== null && $incoming_phone !== null){
    $reply = $incoming_reply;
    $from_phone = $incoming_phone;

    if(preg_match('/^(1|2)(?:\s*-\s*(\d{4}))?$/', $reply, $matches)){
        $reply_action = $matches[1];
        $reply_code = $matches[2] ?? null;
        $med = findMedicationForReply($conn, $from_phone, $reply_code);

        if($med){
            $status = ($reply_action === "1") ? "taken" : "missed";
            markMedicationStatus($conn, (int)$med['id'], (int)$med['patient_id'], $status, $today, $now_datetime);

            if($status == "missed"){
                echo "Caregiver ({$med['caregiver_phone']}) notified: Patient missed {$med['medicine_name']}\n";
            }

            echo "Reply processed for medication {$med['id']} with status $status\n";
        } else {
            echo "No pending reminded medication found for phone $from_phone";
            if($reply_code !== null){
                echo " using code $reply_code";
            }
            echo "\n";
        }
    } else {
        echo "Invalid reply format. Use 1-1234 for Taken or 2-1234 for Missed.\n";
    }

    $conn->close();
    exit();
}

// 1️⃣ Send reminders for medications due now (or in last 60 minutes)
$stmt = $conn->prepare("
    SELECT m.id AS med_id, m.patient_id, m.medicine_name, m.time, m.status,
           p.name AS patient_name, p.phone AS patient_phone, p.caregiver_phone
    FROM medications m
    JOIN patients p ON m.patient_id = p.id
    WHERE ? BETWEEN m.monitoring_start AND m.monitoring_end
      AND (m.status_date IS NULL OR m.status_date <> ? OR m.status='pending')
      AND TIME_TO_SEC(TIMEDIFF(?, m.time)) BETWEEN 0 AND 3600
");
$stmt->bind_param("sss", $today, $today, $now);
$stmt->execute();
$result = $stmt->get_result();

while($row = $result->fetch_assoc()){
    $med_id = $row['med_id'];
    $patient_name = $row['patient_name'];
    $patient_phone = $row['patient_phone'];
    $medicine_name = $row['medicine_name'];

    $sms_code = str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
    $sms_message = "Time to take $medicine_name. Reply 1-$sms_code for Taken or 2-$sms_code for Missed.";
    $sms_result = sendAfricaTalkingSms($patient_phone, $sms_message, $at_username, $at_api_key, $at_sms_endpoint);

    if($sms_result['success']){
        markMedicationPending($conn, (int)$med_id, (int)$row['patient_id'], $today, $now_datetime, $sms_code);
        echo "SMS sent to $patient_name ($patient_phone)\n";
    } else {
        echo "SMS failed for $patient_name ($patient_phone). ";
        echo "Status: {$sms_result['status_code']}. ";
        echo "Error: " . ($sms_result['error'] ?: 'Africa\'s Talking rejected the request') . "\n";
    }
}

$stmt->close();
$conn->close();
