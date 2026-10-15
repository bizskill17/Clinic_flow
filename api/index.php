<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
    http_response_code(503);
    echo json_encode(['error' => 'Database configuration is missing.']);
    exit;
}

$config = require $configFile;
$collections = [
    'spa_clients', 'spa_services', 'spa_invoices', 'spa_receipts', 'spa_categories',
    'spa_cities', 'spa_states', 'spa_users', 'spa_business', 'ph_items',
    'ph_item_categories', 'ph_suppliers', 'ph_moves', 'ph_receipts',
    'ae_proc_categories', 'ae_procedures', 'ae_appointments', 'ae_consultations',
    'ae_quotations', 'ae_sessions', 'ae_invoices', 'ae_consents', 'dept_openings', 'dept_usage'
];

function respond(int $status, array $body): void {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $pdo = new PDO(
        'mysql:host=' . $config['host'] . ';dbname=' . $config['name'] . ';charset=utf8mb4',
        $config['user'],
        $config['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    $pdo->exec('CREATE TABLE IF NOT EXISTS docs (
        collection VARCHAR(64) NOT NULL,
        id VARCHAR(100) NOT NULL,
        data LONGTEXT NOT NULL,
        updated_at DATETIME(3) NOT NULL,
        PRIMARY KEY (collection, id),
        KEY updated_at (updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
} catch (Throwable $e) {
    error_log('Clinic Flow database connection failed: ' . $e->getMessage());
    respond(500, ['error' => 'Database connection failed.']);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$input = [];
if ($method !== 'GET') {
    $input = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($input)) respond(400, ['error' => 'Invalid JSON request body.']);
}
$collection = $method === 'GET' ? ($_GET['collection'] ?? '') : ($input['collection'] ?? '');
if (!is_string($collection) || !in_array($collection, $collections, true)) {
    respond(400, ['error' => 'Unknown collection.']);
}

if ($method === 'GET') {
    $stmt = $pdo->prepare('SELECT id, data FROM docs WHERE collection = ? ORDER BY updated_at ASC');
    $stmt->execute([$collection]);
    $records = [];
    foreach ($stmt as $row) {
        $record = json_decode($row['data'], true);
        if (is_array($record)) {
            $record['id'] = $row['id'];
            $records[] = $record;
        }
    }
    respond(200, ['records' => $records]);
}

if ($method === 'POST') {
    $id = $input['id'] ?? '';
    $data = $input['data'] ?? null;
    if (!is_string($id) || $id === '' || strlen($id) > 100 || !is_array($data)) {
        respond(400, ['error' => 'Invalid record.']);
    }
    $stmt = $pdo->prepare('INSERT INTO docs (collection, id, data, updated_at) VALUES (?, ?, ?, NOW(3)) ON DUPLICATE KEY UPDATE data = VALUES(data), updated_at = NOW(3)');
    $stmt->execute([$collection, $id, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    respond(200, ['ok' => true]);
}

if ($method === 'DELETE') {
    $id = $input['id'] ?? '';
    if (!is_string($id) || $id === '') respond(400, ['error' => 'Invalid record id.']);
    $stmt = $pdo->prepare('DELETE FROM docs WHERE collection = ? AND id = ?');
    $stmt->execute([$collection, $id]);
    respond(200, ['ok' => true]);
}

respond(405, ['error' => 'Method not allowed.']);
