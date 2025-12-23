<?php
// === НАСТРОЙКИ БД ===
$host = $_ENV['DB_HOST'] ?? 'localhost';
$db   = $_ENV['DB_NAME'] ?? 'bottled_water_control';
$user = $_ENV['DB_USER'] ?? 'root'; // ← ЗАМЕНИ НА СВОЙ
$pass = $_ENV['DB_PASS'] ?? '';     // ← ЗАМЕНИ НА СВОЙ
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    die("❌ Ошибка БД: " . htmlspecialchars($e->getMessage()));
}

header('Content-Type: application/json');

try {
    $today = new DateTime();
    $year = $today->format('Y');
    $month = $today->format('m');
    $day = $today->format('d');
    
    // Попытка сгенерировать уникальный номер ТТН
    $maxAttempts = 10; // Максимальное количество попыток генерации уникального номера
    $attempt = 0;
    
    do {
        $randomNum = str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        $newWaybill = "ТТН-{$year}-{$month}-{$day}-{$randomNum}";
        
        $checkStmt = $pdo->prepare("SELECT COUNT(*) as count FROM shipments WHERE waybill_number = ?");
        $checkStmt->execute([$newWaybill]);
        $result = $checkStmt->fetch();
        
        $attempt++;
    } while ($result['count'] > 0 && $attempt < $maxAttempts);
    
    // Если не удалось сгенерировать уникальный номер за несколько попыток, используем более надежный метод
    if ($result['count'] > 0) {
        // Генерируем номер с использованием времени в миллисекундах
        $timestamp = microtime(true) * 10000;
        $newWaybill = "ТТН-{$year}-{$month}-{$day}-" . substr($timestamp, -4);
    }
    
    echo json_encode(['success' => true, 'waybill_number' => $newWaybill]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>