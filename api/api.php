<?php
// api/api.php - Endpoint Pengelolaan Ujian Pemrograman C# & Database MySQL

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/db.php';

$action = $_GET['action'] ?? '';

// Helper response JSON
function sendResponse($success, $message, $data = null, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Format ISO date string ke format MySQL DATETIME (YYYY-MM-DD HH:MM:SS)
function formatToMysqlDateTime($isoStr) {
    if (!$isoStr) return null;
    $time = strtotime($isoStr);
    return $time ? date('Y-m-d H:i:s', $time) : date('Y-m-d H:i:s');
}

try {
    switch ($action) {
        // -------------------------------------------------------------
        // 1. SIMPAN HASIL UJIAN (POST)
        // -------------------------------------------------------------
        case 'save_result':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                sendResponse(false, 'Metode request harus POST', null, 405);
            }

            $rawInput = file_get_contents('php://input');
            $payload = json_decode($rawInput, true);

            if (!$payload) {
                sendResponse(false, 'Data JSON tidak valid atau kosong', null, 400);
            }

            $attemptId = trim($payload['attemptId'] ?? '');
            $nama = trim($payload['nama'] ?? '');
            $nim = trim($payload['nim'] ?? '');
            $kelas = trim($payload['kelas'] ?? '');

            // Validasi kelas harus TI-A, TI-B, atau TI-C
            $allowedClasses = ['TI-A', 'TI-B', 'TI-C'];
            if (!in_array($kelas, $allowedClasses)) {
                sendResponse(false, 'Pilihan kelas tidak valid. Harus TI-A, TI-B, atau TI-C', null, 422);
            }

            if (!$attemptId || !$nama || !$nim) {
                sendResponse(false, 'Kolom identitas wajib diisi dengan lengkap', null, 422);
            }

            $score = intval($payload['score'] ?? 0);
            $correctCount = intval($payload['correctCount'] ?? 0);
            $wrongCount = intval($payload['wrongCount'] ?? 0);
            $timeoutCount = intval($payload['timeoutCount'] ?? 0);
            $unansweredCount = intval($payload['unansweredCount'] ?? 0);
            $accuracy = intval($payload['accuracy'] ?? 0);
            $durationSec = intval($payload['durationSec'] ?? 0);
            $startedAt = formatToMysqlDateTime($payload['startedAt'] ?? null);
            $completedAt = formatToMysqlDateTime($payload['completedAt'] ?? date('Y-m-d H:i:s'));
            $answers = is_array($payload['answers'] ?? null) ? $payload['answers'] : [];

            $pdo->beginTransaction();

            // Simpan atau update tabel hasil_ujian
            $stmt = $pdo->prepare("
                INSERT INTO `hasil_ujian` (
                    `attempt_id`, `nama`, `nim`, `kelas`, `score`, 
                    `correct_count`, `wrong_count`, `timeout_count`, 
                    `unanswered_count`, `accuracy`, `duration_sec`, 
                    `started_at`, `completed_at`
                ) VALUES (
                    :attempt_id, :nama, :nim, :kelas, :score, 
                    :correct_count, :wrong_count, :timeout_count, 
                    :unanswered_count, :accuracy, :duration_sec, 
                    :started_at, :completed_at
                ) ON DUPLICATE KEY UPDATE
                    `nama` = VALUES(`nama`),
                    `nim` = VALUES(`nim`),
                    `kelas` = VALUES(`kelas`),
                    `score` = VALUES(`score`),
                    `correct_count` = VALUES(`correct_count`),
                    `wrong_count` = VALUES(`wrong_count`),
                    `timeout_count` = VALUES(`timeout_count`),
                    `unanswered_count` = VALUES(`unanswered_count`),
                    `accuracy` = VALUES(`accuracy`),
                    `duration_sec` = VALUES(`duration_sec`),
                    `completed_at` = VALUES(`completed_at`)
            ");

            $stmt->execute([
                ':attempt_id' => $attemptId,
                ':nama' => $nama,
                ':nim' => $nim,
                ':kelas' => $kelas,
                ':score' => $score,
                ':correct_count' => $correctCount,
                ':wrong_count' => $wrongCount,
                ':timeout_count' => $timeoutCount,
                ':unanswered_count' => $unansweredCount,
                ':accuracy' => $accuracy,
                ':duration_sec' => $durationSec,
                ':started_at' => $startedAt,
                ':completed_at' => $completedAt
            ]);

            // Ambil id hasil_ujian
            $stmtId = $pdo->prepare("SELECT id FROM `hasil_ujian` WHERE `attempt_id` = :attempt_id LIMIT 1");
            $stmtId->execute([':attempt_id' => $attemptId]);
            $hasilUjianId = $stmtId->fetchColumn();

            // Bersihkan data jawaban lama jika pernah ada untuk attempt ini
            $stmtDel = $pdo->prepare("DELETE FROM `detail_jawaban` WHERE `hasil_ujian_id` = :hid");
            $stmtDel->execute([':hid' => $hasilUjianId]);

            // Insert detail butir jawaban
            if (!empty($answers)) {
                $stmtAns = $pdo->prepare("
                    INSERT INTO `detail_jawaban` (
                        `hasil_ujian_id`, `attempt_id`, `question_id`, 
                        `question_title`, `category`, `submitted_code`, 
                        `reference_answer`, `status`, `points`, `submitted_at`
                    ) VALUES (
                        :hasil_ujian_id, :attempt_id, :question_id, 
                        :question_title, :category, :submitted_code, 
                        :reference_answer, :status, :points, :submitted_at
                    )
                ");

                foreach ($answers as $ans) {
                    $stmtAns->execute([
                        ':hasil_ujian_id' => $hasilUjianId,
                        ':attempt_id' => $attemptId,
                        ':question_id' => intval($ans['questionId'] ?? 0),
                        ':question_title' => $ans['questionTitle'] ?? '',
                        ':category' => $ans['category'] ?? '',
                        ':submitted_code' => $ans['submittedCode'] ?? '',
                        ':reference_answer' => $ans['referenceAnswer'] ?? '',
                        ':status' => $ans['status'] ?? 'UNANSWERED',
                        ':points' => intval($ans['points'] ?? 0),
                        ':submitted_at' => formatToMysqlDateTime($ans['submittedAt'] ?? null)
                    ]);
                }
            }

            $pdo->commit();

            sendResponse(true, 'Hasil ujian dan identitas berhasil disimpan ke MySQL!', [
                'attemptId' => $attemptId,
                'hasilUjianId' => $hasilUjianId
            ]);
            break;

        // -------------------------------------------------------------
        // 2. AMBIL DATA LEADERBOARD (GET)
        // -------------------------------------------------------------
        case 'get_leaderboard':
            $kelas = trim($_GET['kelas'] ?? '');
            $search = trim($_GET['search'] ?? '');
            $includeAnswers = !empty($_GET['include_answers']);

            $whereClauses = [];
            $params = [];

            if ($kelas !== '' && in_array($kelas, ['TI-A', 'TI-B', 'TI-C'])) {
                $whereClauses[] = "`kelas` = :kelas";
                $params[':kelas'] = $kelas;
            }

            if ($search !== '') {
                $whereClauses[] = "(`nama` LIKE :search OR `nim` LIKE :search)";
                $params[':search'] = '%' . $search . '%';
            }

            $whereSql = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

            $sql = "
                SELECT 
                    `id`, `attempt_id` AS attemptId, `nama`, `nim`, `kelas`, 
                    `score`, `correct_count` AS correctCount, `wrong_count` AS wrongCount, 
                    `timeout_count` AS timeoutCount, `unanswered_count` AS unansweredCount, 
                    `accuracy`, `duration_sec` AS durationSec, 
                    `started_at` AS startedAt, `completed_at` AS completedAt
                FROM `hasil_ujian`
                {$whereSql}
                ORDER BY `score` DESC, `duration_sec` ASC, `completed_at` ASC
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $results = $stmt->fetchAll();

            // Jika include_answers diminta (misal untuk ekspor excel lengkap)
            if ($includeAnswers && !empty($results)) {
                $attemptIds = array_column($results, 'attemptId');
                $inQuery = implode(',', array_fill(0, count($attemptIds), '?'));
                $stmtAns = $pdo->prepare("
                    SELECT 
                        `attempt_id`, `question_id` AS questionId, `question_title` AS questionTitle, 
                        `category`, `submitted_code` AS submittedCode, `reference_answer` AS referenceAnswer, 
                        `status`, `points`, `submitted_at` AS submittedAt
                    FROM `detail_jawaban`
                    WHERE `attempt_id` IN ({$inQuery})
                    ORDER BY `question_id` ASC
                ");
                $stmtAns->execute($attemptIds);
                $allAnswers = $stmtAns->fetchAll();

                // Kelompokkan jawaban berdasarkan attempt_id
                $groupedAnswers = [];
                foreach ($allAnswers as $ans) {
                    $groupedAnswers[$ans['attempt_id']][] = $ans;
                }

                foreach ($results as &$row) {
                    $row['answers'] = $groupedAnswers[$row['attemptId']] ?? [];
                }
                unset($row);
            }

            // Hitung statistik
            $totalCount = count($results);
            $scores = array_column($results, 'score');
            $highScore = $totalCount > 0 ? max($scores) : 0;
            $lowScore = $totalCount > 0 ? min($scores) : 0;
            $avgScore = $totalCount > 0 ? round(array_sum($scores) / $totalCount, 1) : 0;

            sendResponse(true, 'Data leaderboard berhasil dimuat', [
                'list' => $results,
                'stats' => [
                    'totalCount' => $totalCount,
                    'highScore' => $highScore,
                    'lowScore' => $lowScore,
                    'avgScore' => $avgScore
                ]
            ]);
            break;

        // -------------------------------------------------------------
        // 3. CEK NIM APAKAH SUDAH UJIAN (GET)
        // -------------------------------------------------------------
        case 'check_nim':
            $nim = trim($_GET['nim'] ?? '');
            if (!$nim) {
                sendResponse(false, 'Parameter nim tidak boleh kosong', null, 400);
            }

            $stmt = $pdo->prepare("SELECT `id`, `nama`, `kelas`, `score`, `completed_at` FROM `hasil_ujian` WHERE `nim` = :nim LIMIT 1");
            $stmt->execute([':nim' => $nim]);
            $existing = $stmt->fetch();

            sendResponse(true, 'Pemeriksaan NIM selesai', [
                'exists' => (bool)$existing,
                'record' => $existing ?: null
            ]);
            break;

        // -------------------------------------------------------------
        // 4. RESET SELURUH DATA UJIAN (POST - Admin Only)
        // -------------------------------------------------------------
        case 'reset_data':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                sendResponse(false, 'Metode request harus POST', null, 405);
            }

            $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
            $pdo->exec("TRUNCATE TABLE `detail_jawaban`;");
            $pdo->exec("TRUNCATE TABLE `hasil_ujian`;");
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");

            sendResponse(true, 'Seluruh riwayat ujian di database MySQL telah dikosongkan!');
            break;

        // -------------------------------------------------------------
        // 5. SEED DATA DUMMY / SAMPEL (POST - Admin Only)
        // -------------------------------------------------------------
        case 'seed_data':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                sendResponse(false, 'Metode request harus POST', null, 405);
            }

            $samples = [
                [
                    'attemptId' => 'att_seed_01',
                    'nama' => 'Ahmad Rizky Pratama',
                    'nim' => '230101001',
                    'kelas' => 'TI-A',
                    'score' => 100,
                    'correctCount' => 20,
                    'wrongCount' => 0,
                    'timeoutCount' => 0,
                    'unansweredCount' => 0,
                    'accuracy' => 100,
                    'durationSec' => 480,
                    'startedAt' => date('Y-m-d H:i:s', time() - 3600),
                    'completedAt' => date('Y-m-d H:i:s', time() - 3120)
                ],
                [
                    'attemptId' => 'att_seed_02',
                    'nama' => 'Siti Nurhaliza',
                    'nim' => '230101015',
                    'kelas' => 'TI-B',
                    'score' => 90,
                    'correctCount' => 18,
                    'wrongCount' => 2,
                    'timeoutCount' => 0,
                    'unansweredCount' => 0,
                    'accuracy' => 90,
                    'durationSec' => 540,
                    'startedAt' => date('Y-m-d H:i:s', time() - 7200),
                    'completedAt' => date('Y-m-d H:i:s', time() - 6660)
                ],
                [
                    'attemptId' => 'att_seed_03',
                    'nama' => 'Dimas Anggara',
                    'nim' => '230101028',
                    'kelas' => 'TI-C',
                    'score' => 85,
                    'correctCount' => 17,
                    'wrongCount' => 3,
                    'timeoutCount' => 0,
                    'unansweredCount' => 0,
                    'accuracy' => 85,
                    'durationSec' => 590,
                    'startedAt' => date('Y-m-d H:i:s', time() - 10800),
                    'completedAt' => date('Y-m-d H:i:s', time() - 10210)
                ],
                [
                    'attemptId' => 'att_seed_04',
                    'nama' => 'Dewi Lestari',
                    'nim' => '230101034',
                    'kelas' => 'TI-A',
                    'score' => 75,
                    'correctCount' => 15,
                    'wrongCount' => 3,
                    'timeoutCount' => 2,
                    'unansweredCount' => 0,
                    'accuracy' => 75,
                    'durationSec' => 610,
                    'startedAt' => date('Y-m-d H:i:s', time() - 14400),
                    'completedAt' => date('Y-m-d H:i:s', time() - 13790)
                ],
                [
                    'attemptId' => 'att_seed_05',
                    'nama' => 'Fajar Hidayat',
                    'nim' => '230101042',
                    'kelas' => 'TI-B',
                    'score' => 65,
                    'correctCount' => 13,
                    'wrongCount' => 5,
                    'timeoutCount' => 2,
                    'unansweredCount' => 0,
                    'accuracy' => 65,
                    'durationSec' => 680,
                    'startedAt' => date('Y-m-d H:i:s', time() - 18000),
                    'completedAt' => date('Y-m-d H:i:s', time() - 17320)
                ],
                [
                    'attemptId' => 'att_seed_06',
                    'nama' => 'Rina Wijaya',
                    'nim' => '230101056',
                    'kelas' => 'TI-C',
                    'score' => 95,
                    'correctCount' => 19,
                    'wrongCount' => 1,
                    'timeoutCount' => 0,
                    'unansweredCount' => 0,
                    'accuracy' => 95,
                    'durationSec' => 450,
                    'startedAt' => date('Y-m-d H:i:s', time() - 21600),
                    'completedAt' => date('Y-m-d H:i:s', time() - 21150)
                ]
            ];

            $stmt = $pdo->prepare("
                INSERT INTO `hasil_ujian` (
                    `attempt_id`, `nama`, `nim`, `kelas`, `score`, 
                    `correct_count`, `wrong_count`, `timeout_count`, 
                    `unanswered_count`, `accuracy`, `duration_sec`, 
                    `started_at`, `completed_at`
                ) VALUES (
                    :attempt_id, :nama, :nim, :kelas, :score, 
                    :correct_count, :wrong_count, :timeout_count, 
                    :unanswered_count, :accuracy, :duration_sec, 
                    :started_at, :completed_at
                ) ON DUPLICATE KEY UPDATE `score` = VALUES(`score`)
            ");

            foreach ($samples as $s) {
                $stmt->execute([
                    ':attempt_id' => $s['attemptId'],
                    ':nama' => $s['nama'],
                    ':nim' => $s['nim'],
                    ':kelas' => $s['kelas'],
                    ':score' => $s['score'],
                    ':correct_count' => $s['correctCount'],
                    ':wrong_count' => $s['wrongCount'],
                    ':timeout_count' => $s['timeoutCount'],
                    ':unanswered_count' => $s['unansweredCount'],
                    ':accuracy' => $s['accuracy'],
                    ':duration_sec' => $s['durationSec'],
                    ':started_at' => $s['startedAt'],
                    ':completed_at' => $s['completedAt']
                ]);
            }

            sendResponse(true, 'Data sampel kelas TI-A, TI-B, dan TI-C berhasil dimasukkan ke MySQL!');
            break;

        default:
            sendResponse(false, 'Action tidak dikenal: ' . htmlspecialchars($action), null, 400);
            break;
    }
} catch (Exception $e) {
    sendResponse(false, 'Terjadi kesalahan server: ' . $e->getMessage(), null, 500);
}

