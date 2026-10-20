<?php
// api/db.php - Koneksi Database MySQL ujianbahasa_c

$db_host = 'localhost';
$db_name = 'ujianbahasa_c';
$db_user = 'root';
$db_pass = '';

try {
    $pdo = new PDO("mysql:host={$db_host};dbname={$db_name};charset=utf8mb4", $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    // Inisialisasi tabel otomatis bila belum ada
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `hasil_ujian` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `attempt_id` VARCHAR(64) NOT NULL UNIQUE,
            `nama` VARCHAR(150) NOT NULL,
            `nim` VARCHAR(50) NOT NULL,
            `kelas` VARCHAR(20) NOT NULL,
            `score` INT NOT NULL DEFAULT 0,
            `correct_count` INT NOT NULL DEFAULT 0,
            `wrong_count` INT NOT NULL DEFAULT 0,
            `timeout_count` INT NOT NULL DEFAULT 0,
            `unanswered_count` INT NOT NULL DEFAULT 0,
            `accuracy` INT NOT NULL DEFAULT 0,
            `duration_sec` INT NOT NULL DEFAULT 0,
            `started_at` DATETIME NULL,
            `completed_at` DATETIME NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_nim` (`nim`),
            INDEX `idx_kelas` (`kelas`),
            INDEX `idx_score_duration` (`score` DESC, `duration_sec` ASC)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS `detail_jawaban` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `hasil_ujian_id` INT NOT NULL,
            `attempt_id` VARCHAR(64) NOT NULL,
            `question_id` INT NOT NULL,
            `question_title` VARCHAR(255) NOT NULL,
            `category` VARCHAR(100) NOT NULL,
            `submitted_code` MEDIUMTEXT NULL,
            `reference_answer` MEDIUMTEXT NULL,
            `status` VARCHAR(20) NOT NULL,
            `points` INT NOT NULL DEFAULT 0,
            `submitted_at` DATETIME NULL,
            INDEX `idx_attempt` (`attempt_id`),
            CONSTRAINT `fk_hasil_ujian` FOREIGN KEY (`hasil_ujian_id`) 
                REFERENCES `hasil_ujian` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

} catch (PDOException $e) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Koneksi database MySQL ujianbahasa_c gagal: ' . $e->getMessage()
    ]);
    exit;
}

