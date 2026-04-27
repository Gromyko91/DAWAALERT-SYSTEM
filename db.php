<?php
$config = [];
$config_path = __DIR__ . '/app_config.php';

if (file_exists($config_path)) {
    $loaded_config = require $config_path;
    if (is_array($loaded_config)) {
        $config = $loaded_config;
    }
}

$db_config = $config['database'] ?? [];

$host = $db_config['host'] ?? "localhost";
$user = $db_config['user'] ?? "root";
$pass = $db_config['password'] ?? "";
$db = $db_config['name'] ?? "dawa_alert";

$conn = new mysqli($host, $user, $pass);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");

if (!$conn->query("CREATE DATABASE IF NOT EXISTS `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) {
    die("Database setup failed: " . $conn->error);
}

if (!$conn->select_db($db)) {
    die("Database selection failed: " . $conn->error);
}

function dawaAlertColumnExists(mysqli $conn, string $table, string $column): bool
{
    $table = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");

    return $result instanceof mysqli_result && $result->num_rows > 0;
}

function seedMedicineCatalog(mysqli $conn, bool $force = false): void
{
    static $seeded = false;

    if ($seeded && !$force) {
        return;
    }

    $count_result = $conn->query("SELECT COUNT(*) AS total FROM medicines");
    $total = $count_result ? (int)($count_result->fetch_assoc()['total'] ?? 0) : 0;

    if ($total > 0 && !$force) {
        $seeded = true;
        return;
    }

    $catalog = require __DIR__ . '/medicine_catalog.php';
    $stmt = $conn->prepare("
        INSERT INTO medicines (name, category) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE category = VALUES(category)
    ");

    if (!$stmt) {
        die("Medicine catalog setup failed: " . $conn->error);
    }

    foreach ($catalog as $category => $items) {
        foreach ($items as $medicine) {
            $stmt->bind_param("ss", $medicine, $category);
            if (!$stmt->execute()) {
                $stmt->close();
                die("Medicine catalog seed failed: " . $stmt->error);
            }
        }
    }

    $stmt->close();
    $seeded = true;
}

function runDatabaseMigrations(mysqli $conn): void
{
    static $schemaReady = false;

    if ($schemaReady) {
        return;
    }

    if (!$conn->query("
        CREATE TABLE IF NOT EXISTS schema_migrations (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            migration_name VARCHAR(190) NOT NULL,
            applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_migration_name (migration_name)
        )
    ")) {
        die("Migration table setup failed: " . $conn->error);
    }

    $migrations = [
        '001_create_core_tables' => function (mysqli $conn): void {
            $schema = [
                "CREATE TABLE IF NOT EXISTS doctors (
                    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(255) NOT NULL,
                    email VARCHAR(255) NOT NULL,
                    password VARCHAR(255) NOT NULL,
                    reset_token_hash VARCHAR(64) DEFAULT NULL,
                    reset_token_expires_at DATETIME DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_doctors_email (email)
                )",
                "CREATE TABLE IF NOT EXISTS patients (
                    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    doctor_id INT(11) NOT NULL,
                    name VARCHAR(255) NOT NULL,
                    phone VARCHAR(30) NOT NULL,
                    caregiver_phone VARCHAR(30) DEFAULT NULL,
                    condition_name VARCHAR(255) DEFAULT NULL,
                    age INT DEFAULT NULL,
                    gender VARCHAR(10) DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    KEY (doctor_id)
                )",
                "CREATE TABLE IF NOT EXISTS medications (
                    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    patient_id INT(11) NOT NULL,
                    medicine_name VARCHAR(255) NOT NULL,
                    dosage VARCHAR(255) NOT NULL,
                    time TIME NOT NULL,
                    status VARCHAR(50) DEFAULT 'pending',
                    status_date DATE DEFAULT NULL,
                    monitoring_start DATE DEFAULT NULL,
                    monitoring_end DATE DEFAULT NULL,
                    schedule_id BIGINT DEFAULT NULL,
                    sms_code VARCHAR(20) DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    KEY (patient_id),
                    KEY (status),
                    KEY (status_date)
                )",
                "CREATE TABLE IF NOT EXISTS medicines (
                    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(255) NOT NULL,
                    category VARCHAR(100) DEFAULT 'General',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_medicines_name (name)
                )",
                "CREATE TABLE IF NOT EXISTS reminder_logs (
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
                )"
            ];

            foreach ($schema as $sql) {
                if (!$conn->query($sql)) {
                    throw new RuntimeException("Database schema setup failed: " . $conn->error);
                }
            }
        },
        '002_backfill_missing_columns' => function (mysqli $conn): void {
            $columnMigrations = [
                "ALTER TABLE doctors ADD COLUMN reset_token_hash VARCHAR(64) DEFAULT NULL" => ["doctors", "reset_token_hash"],
                "ALTER TABLE doctors ADD COLUMN reset_token_expires_at DATETIME DEFAULT NULL" => ["doctors", "reset_token_expires_at"],
                "ALTER TABLE patients ADD COLUMN caregiver_phone VARCHAR(30) DEFAULT NULL" => ["patients", "caregiver_phone"],
                "ALTER TABLE patients ADD COLUMN condition_name VARCHAR(255) DEFAULT NULL" => ["patients", "condition_name"],
                "ALTER TABLE patients ADD COLUMN age INT DEFAULT NULL" => ["patients", "age"],
                "ALTER TABLE patients ADD COLUMN gender VARCHAR(10) DEFAULT NULL" => ["patients", "gender"],
                "ALTER TABLE medications ADD COLUMN status VARCHAR(50) DEFAULT 'pending'" => ["medications", "status"],
                "ALTER TABLE medications ADD COLUMN status_date DATE DEFAULT NULL" => ["medications", "status_date"],
                "ALTER TABLE medications ADD COLUMN monitoring_start DATE DEFAULT NULL" => ["medications", "monitoring_start"],
                "ALTER TABLE medications ADD COLUMN monitoring_end DATE DEFAULT NULL" => ["medications", "monitoring_end"],
                "ALTER TABLE medications ADD COLUMN schedule_id BIGINT DEFAULT NULL" => ["medications", "schedule_id"],
                "ALTER TABLE medications ADD COLUMN sms_code VARCHAR(20) DEFAULT NULL" => ["medications", "sms_code"],
                "ALTER TABLE medicines ADD COLUMN category VARCHAR(100) DEFAULT 'General'" => ["medicines", "category"],
                "ALTER TABLE reminder_logs ADD COLUMN log_date DATE DEFAULT NULL" => ["reminder_logs", "log_date"],
                "ALTER TABLE reminder_logs ADD COLUMN alert_sent TINYINT(1) DEFAULT 0" => ["reminder_logs", "alert_sent"],
                "ALTER TABLE reminder_logs ADD COLUMN status VARCHAR(50)" => ["reminder_logs", "status"]
            ];

            foreach ($columnMigrations as $sql => [$table, $column]) {
                if (!dawaAlertColumnExists($conn, $table, $column) && !$conn->query($sql)) {
                    throw new RuntimeException("Database migration failed for {$table}.{$column}: " . $conn->error);
                }
            }
        },
        '003_seed_catalog_and_defaults' => function (mysqli $conn): void {
            $today = date('Y-m-d');
            $conn->query("INSERT IGNORE INTO medicines (name) SELECT DISTINCT medicine_name FROM medications WHERE medicine_name IS NOT NULL AND medicine_name <> ''");
            seedMedicineCatalog($conn);
            $conn->query("UPDATE medications SET monitoring_start = '{$today}' WHERE monitoring_start IS NULL");
            $conn->query("UPDATE medications SET monitoring_end = monitoring_start WHERE monitoring_end IS NULL");
            $conn->query("UPDATE medications SET status = 'pending' WHERE status IS NULL OR status = ''");
        }
    ];

    foreach ($migrations as $migrationName => $migrationCallback) {
        $safeMigrationName = $conn->real_escape_string($migrationName);
        $alreadyApplied = $conn->query("SELECT id FROM schema_migrations WHERE migration_name = '{$safeMigrationName}' LIMIT 1");

        if ($alreadyApplied instanceof mysqli_result && $alreadyApplied->num_rows > 0) {
            continue;
        }

        $conn->begin_transaction();

        try {
            $migrationCallback($conn);
            $insert = $conn->prepare("INSERT INTO schema_migrations (migration_name) VALUES (?)");
            $insert->bind_param("s", $migrationName);
            $insert->execute();
            $insert->close();
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            die($e->getMessage());
        }
    }

    $schemaReady = true;
}

runDatabaseMigrations($conn);
?>
