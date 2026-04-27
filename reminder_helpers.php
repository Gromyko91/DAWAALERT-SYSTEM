<?php

function upsertDailyReminderLog(mysqli $conn, int $medication_id, int $patient_id, string $log_date, string $status, int $alert_sent, string $timestamp): void
{
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

    if ($existing) {
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

function markMedicationStatus(mysqli $conn, int $medication_id, int $patient_id, string $status, string $log_date, string $timestamp): void
{
    $alert_sent = $status === 'missed' ? 1 : 0;
    $conn->begin_transaction();

    try {
        $update = $conn->prepare("
            UPDATE medications
            SET status = ?, status_date = ?, sms_code = NULL
            WHERE id = ?
        ");
        $update->bind_param("ssi", $status, $log_date, $medication_id);
        $update->execute();
        $update->close();

        upsertDailyReminderLog($conn, $medication_id, $patient_id, $log_date, $status, $alert_sent, $timestamp);
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

function markMedicationPending(mysqli $conn, int $medication_id, int $patient_id, string $log_date, string $timestamp, string $sms_code): void
{
    $status = 'pending';
    $conn->begin_transaction();

    try {
        $update = $conn->prepare("
            UPDATE medications
            SET status = ?, status_date = ?, sms_code = ?
            WHERE id = ?
        ");
        $update->bind_param("sssi", $status, $log_date, $sms_code, $medication_id);
        $update->execute();
        $update->close();

        upsertDailyReminderLog($conn, $medication_id, $patient_id, $log_date, $status, 0, $timestamp);
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

function syncOverdueMedicationStatuses(mysqli $conn, ?int $doctor_id = null, int $missed_grace_seconds = 900): array
{
    $today = date('Y-m-d');
    $now = date('H:i:s');
    $now_datetime = date('Y-m-d H:i:s');
    $sql = "
        SELECT m.id, m.patient_id
        FROM medications m
        JOIN patients p ON m.patient_id = p.id
        WHERE ? BETWEEN m.monitoring_start AND m.monitoring_end
          AND TIME_TO_SEC(TIMEDIFF(?, m.time)) > ?
          AND (m.status_date IS NULL OR m.status_date <> ? OR m.status = 'pending')
    ";

    if ($doctor_id !== null) {
        $sql .= " AND p.doctor_id = ?";
    }

    $sync = $conn->prepare($sql);

    if ($doctor_id !== null) {
        $sync->bind_param("ssisi", $today, $now, $missed_grace_seconds, $today, $doctor_id);
    } else {
        $sync->bind_param("ssis", $today, $now, $missed_grace_seconds, $today);
    }

    $sync->execute();
    $rows = $sync->get_result();
    $updated = [];

    while ($row = $rows->fetch_assoc()) {
        markMedicationStatus($conn, (int)$row['id'], (int)$row['patient_id'], 'missed', $today, $now_datetime);
        $updated[] = (int)$row['id'];
    }

    $sync->close();

    return $updated;
}

function findMedicationForReply(mysqli $conn, string $phone, ?string $sms_code = null): ?array
{
    $today = date('Y-m-d');

    if ($sms_code !== null && $sms_code !== '') {
        $by_code = $conn->prepare("
            SELECT m.id, m.patient_id, m.medicine_name, p.caregiver_phone, m.sms_code
            FROM medications m
            JOIN patients p ON m.patient_id = p.id
            WHERE p.phone = ?
              AND m.status = 'pending'
              AND m.status_date = ?
              AND m.sms_code = ?
            ORDER BY m.time DESC
            LIMIT 1
        ");
        $by_code->bind_param("sss", $phone, $today, $sms_code);
        $by_code->execute();
        $row = $by_code->get_result()->fetch_assoc();
        $by_code->close();

        if ($row) {
            return $row;
        }
    }

    $latest = $conn->prepare("
        SELECT m.id, m.patient_id, m.medicine_name, p.caregiver_phone, m.sms_code,
               MAX(rl.reminder_sent_at) AS last_reminder_sent_at
        FROM medications m
        JOIN patients p ON m.patient_id = p.id
        LEFT JOIN reminder_logs rl ON rl.medication_id = m.id AND rl.log_date = ?
        WHERE p.phone = ?
          AND m.status = 'pending'
          AND m.status_date = ?
        GROUP BY m.id, m.patient_id, m.medicine_name, p.caregiver_phone, m.sms_code
        ORDER BY last_reminder_sent_at DESC, m.time DESC
        LIMIT 1
    ");
    $latest->bind_param("sss", $today, $phone, $today);
    $latest->execute();
    $row = $latest->get_result()->fetch_assoc();
    $latest->close();

    return $row ?: null;
}
