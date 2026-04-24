<?php
require 'db.php';
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

function upsertDailyReminderLog($conn, $medication_id, $patient_id, $log_date, $status, $alert_sent, $timestamp){
    $check = $conn->prepare("
        SELECT id
        FROM reminder_logs
        WHERE medication_id = ? AND log_date = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $check->bind_param("is", $medication_id, $log_date);
    $check->execute();
    $existing = $check->get_result()->fetch_assoc();
    $check->close();

    if($existing){
        $update = $conn->prepare("
            UPDATE reminder_logs
            SET reminder_sent_at = ?, alert_sent = ?, status = ?
            WHERE id = ?
        ");
        $update->bind_param("sisi", $timestamp, $alert_sent, $status, $existing['id']);
        $update->execute();
        $update->close();
        return;
    }

    $insert = $conn->prepare("
        INSERT INTO reminder_logs (medication_id, patient_id, reminder_sent_at, log_date, alert_sent, status)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $insert->bind_param("iissis", $medication_id, $patient_id, $timestamp, $log_date, $alert_sent, $status);
    $insert->execute();
    $insert->close();
}

// Current time
$today = date('Y-m-d');
$now = date('H:i:s');
$now_datetime = date('Y-m-d H:i:s');
$missed_grace_seconds = 15 * 60;

$missed_update = $conn->prepare("
    UPDATE medications
    SET status='missed', status_date=?
    WHERE ? BETWEEN monitoring_start AND monitoring_end
      AND (status_date IS NULL OR status_date <> ? OR status='pending')
      AND TIME_TO_SEC(TIMEDIFF(?, time)) > ?
");
$missed_update->bind_param("ssssi", $today, $today, $today, $now, $missed_grace_seconds);
$missed_update->execute();
$missed_update->close();

$missed_result = $conn->prepare("
    SELECT id, patient_id
    FROM medications
    WHERE status='missed' AND status_date=?
");
$missed_result->bind_param("s", $today);
$missed_result->execute();
$missed_rows = $missed_result->get_result();
while($missed_row = $missed_rows->fetch_assoc()){
    upsertDailyReminderLog($conn, (int)$missed_row['id'], (int)$missed_row['patient_id'], $today, 'missed', 1, $now_datetime);
}
$missed_result->close();

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

    if(preg_match('/^(1|2)$/', $reply, $matches)){
        $reply_action = $matches[1];

        // Match the reply to the most recently reminded pending medication for this phone number.
        $med_stmt = $conn->prepare("
            SELECT m.id, m.patient_id, m.medicine_name, m.status, p.caregiver_phone,
                   MAX(rl.reminder_sent_at) AS last_reminder_sent_at
            FROM medications m
            JOIN patients p ON m.patient_id = p.id
            LEFT JOIN reminder_logs rl ON rl.medication_id = m.id
            WHERE p.phone=? AND m.status='pending'
            GROUP BY m.id, m.patient_id, m.medicine_name, m.status, p.caregiver_phone
            ORDER BY last_reminder_sent_at DESC, m.time DESC
            LIMIT 1
        ");
        $med_stmt->bind_param("s", $from_phone);
        $med_stmt->execute();
        $med_result = $med_stmt->get_result();
        $med = $med_result->fetch_assoc();
        $med_stmt->close();

        if($med){
            $status = ($reply_action === "1") ? "taken" : "missed";

            // Update medication status
            $upd = $conn->prepare("UPDATE medications SET status=?, status_date=? WHERE id=?");
            $upd->bind_param("ssi", $status, $today, $med['id']);
            $upd->execute();
            $upd->close();

            // Notify caregiver if missed
            if($status == "missed"){
                echo "Caregiver ({$med['caregiver_phone']}) notified: Patient missed {$med['medicine_name']}\n";
            }

            $alert_sent = ($status=='missed') ? 1 : 0;
            $now_datetime = date('Y-m-d H:i:s');
            upsertDailyReminderLog($conn, (int)$med['id'], (int)$med['patient_id'], $today, $status, $alert_sent, $now_datetime);

            echo "Reply processed for medication {$med['id']} with status $status\n";
        } else {
            echo "No pending reminded medication found for phone $from_phone\n";
        }
    } else {
        echo "Invalid reply format. Use 1 for Taken or 2 for Missed.\n";
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
    $caregiver_phone = $row['caregiver_phone'];
    $medicine_name = $row['medicine_name'];

    // Generate a random code for SMS confirmation
    $sms_code = rand(1000,9999);

    // Update medication with sms_code
    $upd = $conn->prepare("UPDATE medications SET sms_code=? WHERE id=?");
    $upd->bind_param("si", $sms_code, $med_id);
    $upd->execute();
    $upd->close();

    $sms_message = "Time to take $medicine_name. Reply 1 for Taken or 2 for Missed.";
    $sms_result = sendAfricaTalkingSms($patient_phone, $sms_message, $at_username, $at_api_key, $at_sms_endpoint);

    if($sms_result['success']){
        echo "SMS sent to $patient_name ($patient_phone)\n";
    } else {
        echo "SMS failed for $patient_name ($patient_phone). ";
        echo "Status: {$sms_result['status_code']}. ";
        echo "Error: " . ($sms_result['error'] ?: 'Africa\'s Talking rejected the request') . "\n";
    }

    $mark_pending = $conn->prepare("UPDATE medications SET status='pending', status_date=? WHERE id=?");
    $mark_pending->bind_param("si", $today, $med_id);
    $mark_pending->execute();
    $mark_pending->close();

    upsertDailyReminderLog($conn, (int)$med_id, (int)$row['patient_id'], $today, 'pending', 0, $now_datetime);
}

$stmt->close();
$conn->close();
