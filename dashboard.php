<?php
session_start();
require 'db.php';
require 'reminder_helpers.php';

if(!isset($_SESSION['doctor_id'])){
    header("Location: login.php");
    exit();
}

$doctor_id = $_SESSION['doctor_id'];
$doctor_name = $_SESSION['doctor_name'];
$flash_message = $_SESSION['flash_message'] ?? '';
$flash_type = $_SESSION['flash_type'] ?? 'success';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);

function fetchAdherenceData($conn, $doctor_id){
    $adherence = [];
    $today = date('Y-m-d');
    $result = $conn->query("SELECT rl.status, p.name AS patient_name
    FROM reminder_logs rl
    JOIN medications m ON rl.medication_id = m.id
    JOIN patients p ON rl.patient_id = p.id
    WHERE p.doctor_id=$doctor_id
      AND rl.log_date IS NOT NULL
      AND rl.log_date BETWEEN m.monitoring_start AND m.monitoring_end
      AND rl.log_date <= '$today'
      AND rl.status IN ('taken', 'missed')");

    if($result){
        while($row = $result->fetch_assoc()){
            $patient_name = $row['patient_name'];

            if(!isset($adherence[$patient_name])){
                $adherence[$patient_name] = [
                    'patient' => $patient_name,
                    'total' => 0,
                    'taken' => 0,
                    'missed' => 0,
                    'percentage' => 0
                ];
            }

            if($row['status'] === 'taken'){
                $adherence[$patient_name]['taken']++;
            }

            if($row['status'] === 'missed'){
                $adherence[$patient_name]['missed']++;
            }

            $adherence[$patient_name]['total'] = $adherence[$patient_name]['taken'] + $adherence[$patient_name]['missed'];
        }
    }

    foreach($adherence as $patient_name => $data){
        $adherence[$patient_name]['percentage'] = $data['total'] > 0
            ? round(($data['taken'] / $data['total']) * 100)
            : 0;
    }

    return array_values($adherence);
}

function fetchTimelineData($conn, $doctor_id){
    $timeline = [];
    $today = date('Y-m-d');
    $result = $conn->query("SELECT m.*,
        CASE
            WHEN m.status_date = '$today' THEN m.status
            ELSE 'pending'
        END AS daily_status,
        p.name AS patient_name
    FROM medications m
    JOIN patients p ON m.patient_id=p.id
    WHERE p.doctor_id=$doctor_id
      AND '$today' BETWEEN m.monitoring_start AND m.monitoring_end
    ORDER BY p.name ASC, m.time ASC");

    if($result){
        while($row = $result->fetch_assoc()){
            $hour = (int)substr($row['time'], 0, 2);

            if($hour < 12){
                $slot = 'Morning';
            } elseif($hour < 17){
                $slot = 'Afternoon';
            } elseif($hour < 22){
                $slot = 'Evening';
            } else {
                $slot = 'Night';
            }

            if(!isset($timeline[$row['patient_name']])){
                $timeline[$row['patient_name']] = [];
            }

            if(!isset($timeline[$row['patient_name']][$slot])){
                $timeline[$row['patient_name']][$slot] = [];
            }

            $timeline[$row['patient_name']][$slot][] = [
                'medicine_name' => $row['medicine_name'],
                'dosage' => $row['dosage'],
                'time' => $row['time'],
                'status' => $row['daily_status'],
                'monitoring_start' => $row['monitoring_start'],
                'monitoring_end' => $row['monitoring_end']
            ];
        }
    }

    return $timeline;
}

function fetchMissedAlertsData($conn, $doctor_id){
    $today = date('Y-m-d');
    $missed = $conn->query("SELECT COUNT(*) AS m
    FROM medications m
    JOIN patients p ON m.patient_id=p.id
    WHERE p.doctor_id=$doctor_id AND m.status='missed'
      AND m.status_date='$today'
      AND '$today' BETWEEN m.monitoring_start AND m.monitoring_end");
    $missed_count = $missed ? (int)($missed->fetch_assoc()['m'] ?? 0) : 0;

    $missed_medications = [];
    $result = $conn->query("SELECT m.*, p.name AS patient_name, p.phone AS patient_phone, p.caregiver_phone
    FROM medications m
    JOIN patients p ON m.patient_id=p.id
    WHERE p.doctor_id=$doctor_id AND m.status='missed'
      AND m.status_date='$today'
      AND '$today' BETWEEN m.monitoring_start AND m.monitoring_end
    ORDER BY m.time ASC");

    if($result){
        while($row = $result->fetch_assoc()){
            $missed_medications[] = $row;
        }
    }

    return [
        'count' => $missed_count,
        'rows' => $missed_medications
    ];
}

function searchLocalMedicines(mysqli $conn, string $query, int $limit = 12): array
{
    $search = '%' . $query . '%';
    $rows = [];
    $stmt = $conn->prepare("
        SELECT id, name, category
        FROM medicines
        WHERE name LIKE ? OR category LIKE ?
        ORDER BY
            CASE WHEN name LIKE ? THEN 0 ELSE 1 END,
            name ASC
        LIMIT ?
    ");
    $starts_with = $query . '%';
    $stmt->bind_param("sssi", $search, $search, $starts_with, $limit);
    $stmt->execute();
    $result = $stmt->get_result();

    while($row = $result->fetch_assoc()){
        $rows[] = [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'category' => $row['category'] ?: 'Local Catalog',
            'source' => 'local'
        ];
    }

    $stmt->close();
    return $rows;
}

if(isset($_GET['ajax']) && $_GET['ajax'] === 'missed_alerts'){
    syncOverdueMedicationStatuses($conn, $doctor_id);
    header('Content-Type: application/json');
    echo json_encode(fetchMissedAlertsData($conn, $doctor_id));
    exit();
}

if(isset($_GET['ajax']) && $_GET['ajax'] === 'reports'){
    syncOverdueMedicationStatuses($conn, $doctor_id);
    header('Content-Type: application/json');
    echo json_encode([
        'rows' => fetchAdherenceData($conn, $doctor_id)
    ]);
    exit();
}

if(isset($_GET['ajax']) && $_GET['ajax'] === 'timeline'){
    syncOverdueMedicationStatuses($conn, $doctor_id);
    header('Content-Type: application/json');
    echo json_encode([
        'rows' => fetchTimelineData($conn, $doctor_id)
    ]);
    exit();
}

if(isset($_GET['ajax']) && $_GET['ajax'] === 'medicine_search'){
    header('Content-Type: application/json');

    $query = trim($_GET['q'] ?? '');
    if($query === ''){
        echo json_encode([
            'rows' => []
        ]);
        exit();
    }

    echo json_encode([
        'rows' => searchLocalMedicines($conn, $query, 16)
    ]);
    exit();
}

if(isset($_POST['add_catalog_medicine'])){
    $medicine_name = trim($_POST['catalog_medicine_name'] ?? '');
    $medicine_category = trim($_POST['catalog_medicine_category'] ?? '');

    if($medicine_name === ''){
        $_SESSION['flash_message'] = 'Medicine name is required.';
        $_SESSION['flash_type'] = 'error';
        header("Location: dashboard.php");
        exit();
    }

    if($medicine_category === ''){
        $medicine_category = 'General';
    }

    $stmt = $conn->prepare("
        INSERT INTO medicines (name, category) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE category = VALUES(category)
    ");
    $stmt->bind_param("ss", $medicine_name, $medicine_category);
    $stmt->execute();
    $stmt->close();

    $_SESSION['flash_message'] = 'Medicine catalog updated successfully.';
    $_SESSION['flash_type'] = 'success';
    header("Location: dashboard.php");
    exit();
}

if(isset($_POST['delete_catalog_medicine'])){
    $medicine_id = (int)($_POST['catalog_medicine_id'] ?? 0);

    $usage_check = $conn->prepare("SELECT COUNT(*) AS total FROM medications WHERE medicine_name = (SELECT name FROM medicines WHERE id = ?)");
    $usage_check->bind_param("i", $medicine_id);
    $usage_check->execute();
    $usage_row = $usage_check->get_result()->fetch_assoc();
    $usage_check->close();

    if((int)($usage_row['total'] ?? 0) > 0){
        $_SESSION['flash_message'] = 'This medicine is already used in medication schedules and cannot be deleted.';
        $_SESSION['flash_type'] = 'error';
        header("Location: dashboard.php");
        exit();
    }

    $delete = $conn->prepare("DELETE FROM medicines WHERE id = ?");
    $delete->bind_param("i", $medicine_id);
    $delete->execute();
    $deleted_rows = $delete->affected_rows;
    $delete->close();

    $_SESSION['flash_message'] = $deleted_rows > 0
        ? 'Medicine removed from the catalog.'
        : 'Medicine was not found in the catalog.';
    $_SESSION['flash_type'] = $deleted_rows > 0 ? 'success' : 'error';
    header("Location: dashboard.php");
    exit();
}

// Add patient
if(isset($_POST['add_patient'])){
    $age = (int)$_POST['age'];
    $gender = trim($_POST['gender']);
    $stmt = $conn->prepare("INSERT INTO patients (doctor_id,name,phone,caregiver_phone,condition_name,age,gender) VALUES (?,?,?,?,?,?,?)");
    $stmt->bind_param("issssis",$doctor_id,$_POST['patient_name'],$_POST['phone'],$_POST['caregiver_phone'],$_POST['condition_name'],$age,$gender);
    $stmt->execute();
    $new_patient_id = $stmt->insert_id;
    $stmt->close();
    $_SESSION['newly_added_patient_id'] = $new_patient_id;
    header("Location: dashboard.php");
    exit();
}

// Add medication
if(isset($_POST['add_medication'])){
    $medicine_id = (int)($_POST['medicine_id'] ?? 0);
    $selected_medicine_name = '';

    if($medicine_id > 0){
        $medicine_stmt = $conn->prepare("SELECT name FROM medicines WHERE id=? LIMIT 1");
        $medicine_stmt->bind_param("i", $medicine_id);
        $medicine_stmt->execute();
        $medicine_result = $medicine_stmt->get_result();
        $medicine = $medicine_result->fetch_assoc();
        $medicine_stmt->close();

        if($medicine){
            $selected_medicine_name = $medicine['name'];
        }
    }

    if($selected_medicine_name === ''){
        $_SESSION['flash_message'] = 'Select a valid medicine from your database list first.';
        $_SESSION['flash_type'] = 'error';
        header("Location: dashboard.php");
        exit();
    }

    $monitoring_start = $_POST['monitoring_start'];
    $monitoring_end = $_POST['monitoring_end'];

    if($monitoring_end < $monitoring_start){
        $_SESSION['flash_message'] = 'Monitoring end date must be on or after the monitoring start date.';
        $_SESSION['flash_type'] = 'error';
        header("Location: dashboard.php");
        exit();
    }

    $schedule_id = time();
    $stmt = $conn->prepare("INSERT INTO medications (patient_id,medicine_name,dosage,time,monitoring_start,monitoring_end,schedule_id) VALUES (?,?,?,?,?,?,?)");
    $stmt->bind_param("isssssi",$_POST['patient_id'],$selected_medicine_name,$_POST['dosage'],$_POST['time'],$monitoring_start,$monitoring_end,$schedule_id);
    $stmt->execute();
    $stmt->close();
    header("Location: dashboard.php");
    exit();
}

if(isset($_POST['send_reminders'])){
    $config_path = __DIR__ . '/app_config.php';

    if(!file_exists($config_path)){
        $_SESSION['flash_message'] = 'Reminder config missing. Create app_config.php and add your Africa\'s Talking sandbox API key first.';
        $_SESSION['flash_type'] = 'error';
        header("Location: dashboard.php");
        exit();
    }

    $config = require $config_path;
    $api_key = trim($config['africastalking']['api_key'] ?? '');
    $base_url = rtrim($config['app']['base_url'] ?? 'http://localhost/dawa_alert', '/');
    $reminder_url = $base_url . '/reminder.php';

    if($api_key === '' || $api_key === 'your-africas-talking-api-key'){
        $_SESSION['flash_message'] = 'Africa\'s Talking sandbox API key is not set in app_config.php.';
        $_SESSION['flash_type'] = 'error';
        header("Location: dashboard.php");
        exit();
    }

    $response_body = false;
    $curl_error = '';

    if(function_exists('curl_init')){
        $ch = curl_init($reminder_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $response_body = curl_exec($ch);
        $curl_error = curl_error($ch);
        $http_code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if($response_body === false || $http_code >= 400){
            $response_body = false;
        }
    }

    if($response_body === false){
        $response_body = @file_get_contents($reminder_url);
    }

    if($response_body === false){
        $_SESSION['flash_message'] = 'Reminder trigger failed. Confirm Apache is running, app_config.php exists, and the sandbox API key is valid.' . ($curl_error !== '' ? ' Error: ' . $curl_error : '');
        $_SESSION['flash_type'] = 'error';
    } else {
        $_SESSION['flash_message'] = 'Reminder send successful';
        $_SESSION['flash_type'] = 'success';
    }

    header("Location: dashboard.php");
    exit();
}

// Fetch data
syncOverdueMedicationStatuses($conn, $doctor_id);

$patients = $conn->query("SELECT * FROM patients WHERE doctor_id=$doctor_id");
$medicine_catalog = $conn->query("SELECT id, name, category FROM medicines ORDER BY category ASC, name ASC");
$medicine_management = $conn->query("SELECT id, name, category, created_at FROM medicines ORDER BY category ASC, name ASC");

$medications = $conn->query("SELECT m.*,p.name AS patient_name 
FROM medications m 
JOIN patients p ON m.patient_id=p.id 
WHERE p.doctor_id=$doctor_id");

$missed_alerts = fetchMissedAlertsData($conn, $doctor_id);
$missed_count = $missed_alerts['count'];
$missed_medications = $missed_alerts['rows'];

$timeline = fetchTimelineData($conn, $doctor_id);

$adherence = fetchAdherenceData($conn, $doctor_id);
?>

<!DOCTYPE html>
<html>
<head>
<title>Dawa Alert</title>

<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">

<style>
body {
    margin: 0;
    font-family: 'Inter', sans-serif;
    background: linear-gradient(180deg, #eef5ff 0%, #f8fbff 100%);
    color: #16324f;
}

.container {
    display: flex;
}

.sidebar {
    width: 250px;
    background: linear-gradient(180deg, #1f5fbf 0%, #2C7BE5 100%);
    min-height: 100vh;
    color: white;
    padding: 20px;
    box-shadow: 8px 0 30px rgba(18, 57, 110, 0.12);
}

.sidebar a {
    display: block;
    color: white;
    padding: 12px 14px;
    text-decoration: none;
    border-radius: 10px;
    transition: background 0.2s ease;
}

.sidebar a:hover {
    background: rgba(255,255,255,0.12);
}

.main {
    flex: 1;
    padding: 30px;
}

.topbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
}

.topbar-right {
    display: flex;
    align-items: center;
    gap: 15px;
}

.live-clock {
    min-width: 280px;
    background: linear-gradient(135deg, #ffffff 0%, #f1f7ff 100%);
    color: #16324f;
    padding: 14px 16px;
    border-radius: 16px;
    border: 1px solid #d8e6fb;
    box-shadow: 0px 12px 30px rgba(44,123,229,0.12);
}

.live-clock-label {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.12em;
    color: #5f7fa3;
    margin-bottom: 6px;
}

.live-clock-time {
    font-size: 28px;
    font-weight: 700;
    color: #15406f;
    line-height: 1.1;
}

.live-clock-meta {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    margin-top: 8px;
    font-size: 13px;
    color: #5b7594;
}

.clock-status {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-weight: 600;
}

.clock-status::before {
    content: '';
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #18a957;
    box-shadow: 0 0 0 4px rgba(24,169,87,0.12);
}

.cards {
    display: flex;
    gap: 20px;
    margin-bottom: 40px;
}

.card {
    background: white;
    padding: 20px;
    flex: 1;
    border-radius: 16px;
    border: 1px solid #e4eefb;
    box-shadow: 0px 10px 24px rgba(22,50,79,0.06);
}

.card p {
    font-size: 28px;
    font-weight: 700;
    color: #2C7BE5;
    margin: 8px 0 0;
}

table {
    width: 100%;
    border-collapse: collapse;
    background: white;
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0px 10px 24px rgba(22,50,79,0.06);
    border: 1px solid #e4eefb;
}

table th, table td {
    padding: 15px;
    text-align: left;
}

table th {
    background: #e9f2ff;
    color: #35577d;
    font-size: 13px;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}

table tr:not(:last-child) td {
    border-bottom: 1px solid #eef4fb;
}

.form-section {
    margin-top: 40px;
    background: white;
    padding: 25px;
    border-radius: 16px;
    border: 1px solid #e4eefb;
    box-shadow: 0px 10px 24px rgba(22,50,79,0.06);
}

.flash-message {
    margin-bottom: 20px;
    padding: 14px 16px;
    border-radius: 14px;
    border: 1px solid;
    font-weight: 600;
}

.flash-message.success {
    background: #e8f8ee;
    border-color: #b7e4c7;
    color: #177245;
}

.flash-message.error {
    background: #ffe7e7;
    border-color: #f2b8b5;
    color: #b42318;
}

.toolbar-card {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
}

.toolbar-card p {
    margin: 6px 0 0;
    font-size: 14px;
    color: #6681a3;
    font-weight: 500;
}

.inline-form {
    display: inline;
}

.catalog-grid {
    display: grid;
    grid-template-columns: minmax(260px, 380px) 1fr;
    gap: 24px;
    align-items: start;
}

.catalog-meta {
    color: #6681a3;
    font-size: 13px;
}

.danger-button {
    background: #c94d4d;
}

.form-hint {
    display: block;
    margin: -6px 0 14px;
    color: #6681a3;
    font-size: 13px;
}

input, select {
    width: 100%;
    padding: 10px;
    margin-bottom: 15px;
}

button {
    background: #2C7BE5;
    color: white;
    padding: 10px 16px;
    border: none;
    border-radius: 10px;
    font-weight: 600;
    cursor: pointer;
}

.countdown {
    display: inline-flex;
    align-items: center;
    margin-left: 10px;
    padding: 6px 12px;
    border-radius: 999px;
    background: #e8f3ff;
    color: #1558ad;
    font-size: 12px;
    font-weight: 600;
    letter-spacing: 0.01em;
}

.countdown.overdue {
    background: #ffe7e7;
    color: #b42318;
}

.timeline-list {
    list-style: none;
    padding: 0;
    margin: 12px 0 24px;
}

.timeline-list li {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    padding: 14px 16px;
    margin-bottom: 10px;
    background: white;
    border: 1px solid #e4eefb;
    border-radius: 14px;
    box-shadow: 0px 8px 18px rgba(22,50,79,0.05);
}

.timeline-medication {
    font-weight: 600;
    color: #1f3956;
}

.timeline-meta {
    font-size: 13px;
    color: #6681a3;
    margin-top: 4px;
}

.timeline-monitoring {
    font-size: 12px;
    color: #999;
    margin-top: 4px;
    font-style: italic;
}

.section-title {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}

.section-title small {
    color: #6681a3;
    font-weight: 500;
}

.status-pill {
    display: inline-flex;
    align-items: center;
    padding: 5px 10px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 700;
    text-transform: capitalize;
}

.status-pill.taken {
    background: #e8f8ee;
    color: #177245;
}

.status-pill.pending {
    background: #fff4dd;
    color: #9a6700;
}

.status-pill.missed {
    background: #ffe7e7;
    color: #b42318;
}
</style>

<script>
function showTab(id){
['dashboardTab','patientsTab','medicationsTab','catalogTab','timelineTab','reportsTab','missedTab']
.forEach(t=>document.getElementById(t).style.display=(t===id?'block':'none'));
}

function formatDuration(totalSeconds){
    const seconds = Math.abs(totalSeconds);
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const secs = seconds % 60;

    return [hours, minutes, secs]
        .map(v => String(v).padStart(2, '0'))
        .join(':');
}

function getTargetDate(timeString){
    const [hours, minutes, seconds = '00'] = timeString.split(':');
    const now = new Date();
    return new Date(
        now.getFullYear(),
        now.getMonth(),
        now.getDate(),
        Number(hours),
        Number(minutes),
        Number(seconds)
    );
}

let missedAlertsRefreshInFlight = false;
let lastMissedAlertsRefreshAt = 0;
let reportsRefreshInFlight = false;
let lastReportsRefreshAt = 0;
let timelineRefreshInFlight = false;
let lastTimelineRefreshAt = 0;

function requestMissedAlertsRefresh(force = false){
    const now = Date.now();

    if(missedAlertsRefreshInFlight){
        return;
    }

    if(!force && now - lastMissedAlertsRefreshAt < 3000){
        return;
    }

    lastMissedAlertsRefreshAt = now;
    refreshMissedAlerts();
}

function requestReportsRefresh(force = false){
    const now = Date.now();

    if(reportsRefreshInFlight){
        return;
    }

    if(!force && now - lastReportsRefreshAt < 3000){
        return;
    }

    lastReportsRefreshAt = now;
    refreshReports();
}

function requestImmediateStatusRefresh(){
    requestMissedAlertsRefresh(true);
    requestTimelineRefresh(true);
    requestReportsRefresh(true);
}

function requestTimelineRefresh(force = false){
    const now = Date.now();

    if(timelineRefreshInFlight){
        return;
    }

    if(!force && now - lastTimelineRefreshAt < 3000){
        return;
    }

    lastTimelineRefreshAt = now;
    refreshTimeline();
}

function updateLiveSchedule(){
    const now = new Date();
    const liveClock = document.getElementById('liveClock');
    const liveClockTime = document.getElementById('liveClockTime');
    const liveClockDate = document.getElementById('liveClockDate');
    let crossedToOverdue = false;

    if(liveClock){
        liveClock.dataset.syncedAt = now.toISOString();
    }

    if(liveClockTime){
        liveClockTime.textContent = now.toLocaleTimeString([], {
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit'
        });
    }

    if(liveClockDate){
        liveClockDate.textContent = now.toLocaleDateString([], {
            weekday: 'short',
            year: 'numeric',
            month: 'short',
            day: 'numeric'
        });
    }

    document.querySelectorAll('[data-med-time]').forEach(item => {
        const target = getTargetDate(item.dataset.medTime);
        const diffSeconds = Math.floor((target.getTime() - now.getTime()) / 1000);
        const countdown = item.querySelector('.countdown');

        if(!countdown){
            return;
        }

        if(diffSeconds >= 0){
            countdown.textContent = 'Due in ' + formatDuration(diffSeconds);
            countdown.classList.remove('overdue');
            item.dataset.countdownState = 'due';
        } else {
            countdown.textContent = 'Overdue by ' + formatDuration(diffSeconds);
            countdown.classList.add('overdue');
            if(item.dataset.countdownState === 'due'){
                crossedToOverdue = true;
            }
            item.dataset.countdownState = 'overdue';
        }
    });

    if(crossedToOverdue){
        requestImmediateStatusRefresh();
    }
}

function renderMissedAlerts(rows){
    const tbody = document.getElementById('missedAlertsBody');

    if(!tbody){
        return;
    }

    if(!rows.length){
        tbody.innerHTML = '<tr><td colspan="7">No missed medication alerts right now.</td></tr>';
        return;
    }

    tbody.innerHTML = rows.map(row => `
        <tr data-med-time="${row.time}">
            <td>${row.patient_name}</td>
            <td>${row.medicine_name}</td>
            <td>${row.dosage}</td>
            <td>${row.time} <span class="countdown overdue"></span></td>
            <td>${row.patient_phone}</td>
            <td>${row.caregiver_phone}</td>
            <td>${String(row.status).charAt(0).toUpperCase() + String(row.status).slice(1)}</td>
        </tr>
    `).join('');

    updateLiveSchedule();
}

function renderReports(rows){
    const tbody = document.getElementById('reportsTableBody');

    if(!tbody){
        return;
    }

    if(!rows.length){
        tbody.innerHTML = '<tr><td colspan="5">No report data available right now.</td></tr>';
        return;
    }

    tbody.innerHTML = rows.map(row => `
        <tr>
            <td>${row.patient}</td>
            <td>${row.total}</td>
            <td>${row.taken}</td>
            <td>${row.missed}</td>
            <td>${row.percentage}%</td>
        </tr>
    `).join('');
}

function renderTimeline(data){
    const container = document.getElementById('timelineContent');

    if(!container){
        return;
    }

    const patients = Object.keys(data || {});

    if(!patients.length){
        container.innerHTML = '<p>No medication timeline available right now.</p>';
        return;
    }

    container.innerHTML = patients.map(patient => {
        const slots = data[patient] || {};
        const slotNames = Object.keys(slots);

        const slotMarkup = slotNames.map(slot => {
            const meds = slots[slot] || [];

            const medMarkup = meds.map(med => `
                <li data-med-time="${med.time}">
                    <div>
                        <div class="timeline-medication">${med.medicine_name} ${med.dosage}</div>
                        <div class="timeline-meta">Scheduled at ${med.time} <span class="status-pill ${med.status}">${med.status}</span></div>
                        <div class="timeline-monitoring">Monitoring duration: ${med.monitoring_start} to ${med.monitoring_end}</div>
                    </div>
                    <span class="countdown"></span>
                </li>
            `).join('');

            return `
                <div class="timeline-slot">
                    <b>${slot}</b>
                    <ul class="timeline-list">${medMarkup}</ul>
                </div>
            `;
        }).join('');

        return `<div class="timeline-patient"><h3>${patient}</h3>${slotMarkup}</div>`;
    }).join('');

    updateLiveSchedule();
}

function updateMissedAlertSummary(count){
    const sidebarLink = document.getElementById('missedAlertsLink');
    const dashboardMissedCount = document.getElementById('dashboardMissedCount');
    const dashboardAlertBox = document.getElementById('missedAlertBox');
    const dashboardAlertText = document.getElementById('missedAlertText');

    if(sidebarLink){
        sidebarLink.textContent = count > 0 ? `Missed Alerts (${count})` : 'Missed Alerts';
    }

    if(dashboardMissedCount){
        dashboardMissedCount.textContent = count;
    }

    if(dashboardAlertBox && dashboardAlertText){
        if(count > 0){
            dashboardAlertBox.style.display = 'block';
            dashboardAlertText.textContent = `${count} missed medication alert${count > 1 ? 's are' : ' is'} active. Open the Missed Alerts tab to review them.`;
        } else {
            dashboardAlertBox.style.display = 'none';
        }
    }
}

async function refreshMissedAlerts(){
    if(missedAlertsRefreshInFlight){
        return;
    }

    missedAlertsRefreshInFlight = true;

    try {
        const response = await fetch('dashboard.php?ajax=missed_alerts', { cache: 'no-store' });

        if(!response.ok){
            return;
        }

        const data = await response.json();
        updateMissedAlertSummary(Number(data.count || 0));
        renderMissedAlerts(Array.isArray(data.rows) ? data.rows : []);
        requestReportsRefresh(true);
    } catch (error) {
        console.error('Missed alerts refresh failed.', error);
    } finally {
        missedAlertsRefreshInFlight = false;
    }
}

async function refreshReports(){
    if(reportsRefreshInFlight){
        return;
    }

    reportsRefreshInFlight = true;

    try {
        const response = await fetch('dashboard.php?ajax=reports', { cache: 'no-store' });

        if(!response.ok){
            return;
        }

        const data = await response.json();
        renderReports(Array.isArray(data.rows) ? data.rows : []);
    } catch (error) {
        console.error('Reports refresh failed.', error);
    } finally {
        reportsRefreshInFlight = false;
    }
}

async function refreshTimeline(){
    if(timelineRefreshInFlight){
        return;
    }

    timelineRefreshInFlight = true;

    try {
        const response = await fetch('dashboard.php?ajax=timeline', { cache: 'no-store' });

        if(!response.ok){
            return;
        }

        const data = await response.json();
        renderTimeline(data.rows || {});
    } catch (error) {
        console.error('Timeline refresh failed.', error);
    } finally {
        timelineRefreshInFlight = false;
    }
}

window.addEventListener('load', () => {
    updateLiveSchedule();
    requestImmediateStatusRefresh();
    setInterval(updateLiveSchedule, 1000);
    setInterval(() => requestMissedAlertsRefresh(true), 15000);
    setInterval(() => requestTimelineRefresh(true), 15000);
    setInterval(() => requestReportsRefresh(true), 15000);
});
</script>

</head>

<body onload="showTab('<?php echo $missed_count > 0 ? 'missedTab' : 'dashboardTab'; ?>')">

<div class="container">

<div class="sidebar">
<h2>Dawa Alert</h2>
<a onclick="showTab('dashboardTab')">Dashboard</a>
<a onclick="showTab('patientsTab')">Patients</a>
<a onclick="showTab('medicationsTab')">Medications</a>
<a onclick="showTab('catalogTab')">Medicine Catalog</a>
<a onclick="showTab('timelineTab')">Timeline</a>
<a onclick="showTab('reportsTab')">Reports</a>
<a id="missedAlertsLink" onclick="showTab('missedTab')">Missed Alerts<?php echo $missed_count > 0 ? " ($missed_count)" : ""; ?></a>
</div>

<div class="main">

<?php if($flash_message): ?>
<div class="flash-message <?php echo $flash_type === 'error' ? 'error' : 'success'; ?>">
<?php echo htmlspecialchars($flash_message); ?>
</div>
<?php endif; ?>

<div class="topbar">
<h1>Welcome, <?php echo $doctor_name; ?></h1>
<div class="topbar-right">
<div class="live-clock" id="liveClock">
<div class="live-clock-label">Live Schedule Monitor</div>
<div class="live-clock-time" id="liveClockTime">--:--:--</div>
<div class="live-clock-meta">
<span id="liveClockDate">Syncing date...</span>
<span class="clock-status">Machine synced</span>
</div>
</div>
<button onclick="window.location='logout.php'">Logout</button>
</div>
</div>

<!-- DASHBOARD -->
<div id="dashboardTab">
<div class="cards">
<div class="card"><h3>Total Patients</h3><p><?php echo $patients->num_rows; ?></p></div>
<div class="card"><h3>Missed</h3><p id="dashboardMissedCount"><?php echo $missed_count; ?></p></div>
<div class="card"><h3>Medications</h3><p><?php echo $medications->num_rows; ?></p></div>
</div>

<div class="form-section toolbar-card">
<div>
<h2>Reminder Center</h2>
<p>Send due medication reminders now.</p>
</div>
<form method="POST">
<button type="submit" name="send_reminders">Send Reminders Now</button>
</form>
</div>

<div class="form-section" id="missedAlertBox" style="<?php echo $missed_count > 0 ? '' : 'display:none;'; ?>">
<h2>Missed Medication Alert</h2>
<p id="missedAlertText"><?php echo $missed_count; ?> missed medication alert<?php echo $missed_count > 1 ? 's are' : ' is'; ?> active. Open the Missed Alerts tab to review them.</p>
</div>
</div>

<!-- PATIENTS -->
<div id="patientsTab" style="display:none;">
<h2>Patient List</h2>
<table>
<tr><th>Name</th><th>Phone</th><th>Condition</th><th>Age</th><th>Gender</th></tr>
<?php if($patients->num_rows>0): ?>
<?php $patients->data_seek(0); while($p=$patients->fetch_assoc()): ?>
<tr>
<td><?php echo $p['name']; ?></td>
<td><?php echo $p['phone']; ?></td>
<td><?php echo $p['condition_name']; ?></td>
<td><?php echo $p['age'] !== null ? (int)$p['age'] : '-'; ?></td>
<td><?php echo $p['gender'] ? htmlspecialchars($p['gender']) : '-'; ?></td>
</tr>
<?php endwhile; ?>
<?php endif; ?>
</table>

<div class="form-section">
<h2>Add Patient</h2>
<form method="POST">
<input name="patient_name" placeholder="Name" required>
<input name="phone" placeholder="Phone" required>
<input name="caregiver_phone" placeholder="Caregiver" required>
<input name="condition_name" placeholder="Condition" required>
<input type="number" name="age" placeholder="Age" min="0" max="130" required>
<select name="gender" required>
<option value="">Select Gender</option>
<option value="Male">Male</option>
<option value="Female">Female</option>
</select>
<button name="add_patient">Add Patient</button>
</form>
</div>
</div>

<!-- MEDICATION -->
<div id="medicationsTab" style="display:none;">
<div class="form-section">
<h2>Add Medication</h2>
<form method="POST">
<select name="patient_id" required>
<option value="">Select Patient</option>
<?php 
$newly_added_patient_id = $_SESSION['newly_added_patient_id'] ?? null;
$pl=$conn->query("SELECT * FROM patients WHERE doctor_id=$doctor_id");
while($pp=$pl->fetch_assoc()): 
    $is_selected = $newly_added_patient_id && $pp['id'] == $newly_added_patient_id ? 'selected' : '';
?>
<option value="<?php echo $pp['id']; ?>" <?php echo $is_selected; ?>><?php echo $pp['name']; ?></option>
<?php endwhile; ?>
<?php unset($_SESSION['newly_added_patient_id']); ?>
</select>

<select name="medicine_id" required>
<option value="">Select Medicine</option>
<?php if($medicine_catalog && $medicine_catalog->num_rows > 0): ?>
<?php $current_category = ''; ?>
<?php while($medicine_item = $medicine_catalog->fetch_assoc()): ?>
<?php if($current_category !== $medicine_item['category']): ?>
<?php if($current_category !== ''): ?>
</optgroup>
<?php endif; ?>
<optgroup label="<?php echo htmlspecialchars($medicine_item['category']); ?>">
<?php $current_category = $medicine_item['category']; ?>
<?php endif; ?>
<option value="<?php echo $medicine_item['id']; ?>"><?php echo htmlspecialchars($medicine_item['name']); ?></option>
<?php endwhile; ?>
<?php if($current_category !== ''): ?>
</optgroup>
<?php endif; ?>
<?php endif; ?>
</select>
<input name="dosage" placeholder="Dosage" required>
<input type="time" name="time" required>
<label>Monitoring Start Date:</label>
<input type="date" name="monitoring_start" value="<?php echo date('Y-m-d'); ?>" required>
<label>Monitoring End Date:</label>
<input type="date" name="monitoring_end" value="<?php echo date('Y-m-d'); ?>" required>
<button name="add_medication">Add Medication</button>
</form>
</div>
</div>

<!-- CATALOG -->
<div id="catalogTab" style="display:none;">
<div class="section-title">
<h2>Medicine Catalog</h2>
<small>Manage the medicines stored in your database.</small>
</div>

<div class="catalog-grid">
<div class="form-section" style="margin-top:0;">
<h3>Add Or Update Medicine</h3>
<form method="POST">
<input name="catalog_medicine_name" placeholder="Medicine Name" required>
<input name="catalog_medicine_category" placeholder="Category" value="General" required>
<button name="add_catalog_medicine">Save Medicine</button>
</form>
</div>

<div class="form-section" style="margin-top:0;">
<h3>Catalog List</h3>
<table>
<tr><th>Name</th><th>Category</th><th>Added</th><th>Action</th></tr>
<?php if($medicine_management && $medicine_management->num_rows > 0): ?>
<?php while($medicine_row = $medicine_management->fetch_assoc()): ?>
<tr>
<td><?php echo htmlspecialchars($medicine_row['name']); ?></td>
<td><?php echo htmlspecialchars($medicine_row['category']); ?></td>
<td class="catalog-meta"><?php echo htmlspecialchars($medicine_row['created_at']); ?></td>
<td>
<form method="POST" class="inline-form" onsubmit="return confirm('Delete this medicine from the catalog?');">
<input type="hidden" name="catalog_medicine_id" value="<?php echo (int)$medicine_row['id']; ?>">
<button type="submit" name="delete_catalog_medicine" class="danger-button">Delete</button>
</form>
</td>
</tr>
<?php endwhile; ?>
<?php else: ?>
<tr>
<td colspan="4">No medicines are stored in the catalog yet.</td>
</tr>
<?php endif; ?>
</table>
</div>
</div>
</div>

<!-- MISSED ALERTS -->
<div id="missedTab" style="display:none;">
<h2>Missed Medication Alerts</h2>
<table>
<tr><th>Patient</th><th>Medicine</th><th>Dosage</th><th>Time</th><th>Patient Phone</th><th>Caregiver Phone</th><th>Status</th></tr>
<tbody id="missedAlertsBody">
<?php if(!empty($missed_medications)): ?>
<?php foreach($missed_medications as $missed_med): ?>
<tr data-med-time="<?php echo $missed_med['time']; ?>">
<td><?php echo $missed_med['patient_name']; ?></td>
<td><?php echo $missed_med['medicine_name']; ?></td>
<td><?php echo $missed_med['dosage']; ?></td>
<td><?php echo $missed_med['time']; ?> <span class="countdown overdue"></span></td>
<td><?php echo $missed_med['patient_phone']; ?></td>
<td><?php echo $missed_med['caregiver_phone']; ?></td>
<td><?php echo ucfirst($missed_med['status']); ?></td>
</tr>
<?php endforeach; ?>
<?php else: ?>
<tr>
<td colspan="7">No missed medication alerts right now.</td>
</tr>
<?php endif; ?>
</tbody>
</table>
</div>

<!-- TIMELINE -->
<div id="timelineTab" style="display:none;">
<div class="section-title">
<h2>Timeline</h2>
</div>
<div id="timelineContent">
<?php foreach($timeline as $patient=>$slots): ?>
<h3><?php echo $patient; ?></h3>

<?php foreach($slots as $slot=>$meds): ?>
<b><?php echo $slot; ?></b>
<ul class="timeline-list">
<?php foreach($meds as $m): ?>
<li data-med-time="<?php echo $m['time']; ?>">
<div>
<div class="timeline-medication"><?php echo $m['medicine_name']; ?> <?php echo $m['dosage']; ?></div>
<div class="timeline-meta">Scheduled at <?php echo $m['time']; ?> <span class="status-pill <?php echo $m['status']; ?>"><?php echo $m['status']; ?></span></div>
<div class="timeline-monitoring">Monitoring duration: <?php echo $m['monitoring_start']; ?> to <?php echo $m['monitoring_end']; ?></div>
</div>
<span class="countdown"></span>
</li>
<?php endforeach; ?>
</ul>
<?php endforeach; ?>

<?php endforeach; ?>
</div>
</div>

<!-- REPORTS -->
<div id="reportsTab" style="display:none;">
<h2>Reports</h2>

<table>
<tr><th>Patient</th><th>Total</th><th>Taken</th><th>Missed</th><th>%</th></tr>
<tbody id="reportsTableBody">
<?php if(!empty($adherence)): ?>
<?php foreach($adherence as $d): ?>
<tr>
<td><?php echo $d['patient']; ?></td>
<td><?php echo $d['total']; ?></td>
<td><?php echo $d['taken']; ?></td>
<td><?php echo $d['missed']; ?></td>
<td><?php echo $d['percentage']; ?>%</td>
</tr>
<?php endforeach; ?>
<?php else: ?>
<tr>
<td colspan="5">No report data available right now.</td>
</tr>
<?php endif; ?>
</tbody>
</table>
</div>

</div>
</div>

</body>
</html>
