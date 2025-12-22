<?php
// === НАСТРОЙКИ БД ===
$host = 'localhost';
$db   = 'bottled_water_control';
$user = 'root'; // ← ЗАМЕНИ НА СВОЙ
$pass = '';     // ← ЗАМЕНИ НА СВОЙ
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

// === ЗАГРУЗКА ДАННЫХ ===
$ready_batches = $pdo->query("
    SELECT b.id, b.batch_number, b.remaining_bottles, wb.name AS brand, 
           bt.volume_l, bt.material, b.bottling_datetime
    FROM batches b
    JOIN water_brands wb ON b.brand_id = wb.id
    JOIN bottle_types bt ON b.bottle_type_id = bt.id
    WHERE b.status = 'Годна к реализации'
    ORDER BY b.bottling_datetime DESC
")->fetchAll();

$clients = $pdo->query("SELECT id, name FROM clients ORDER BY name")->fetchAll();
$shippers = $pdo->query("SELECT id, full_name FROM operators WHERE role = 'shipper' ORDER BY full_name")->fetchAll();

// === ОБРАБОТКА ФОРМЫ ===
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $batch_id = (int)$_POST['batch_id'];
        $client_id = (int)$_POST['client_id'];
        $bottles = (int)$_POST['bottles_shipped'];
        $waybill = trim($_POST['waybill_number']);
        $shipped_by = (int)$_POST['shipped_by'];

        // Валидация
        if (!$batch_id || !$client_id || $bottles <= 0 || !$waybill || !$shipped_by) {
            throw new Exception('Заполните все поля.');
        }

        // Проверка остатка
        $batch = $pdo->prepare("SELECT remaining_bottles FROM batches WHERE id = ?");
        $batch->execute([$batch_id]);
        $remaining = $batch->fetchColumn();
        if ($bottles > $remaining) {
            throw new Exception('Нельзя отгрузить больше, чем осталось в партии.');
        }

        // Имя ответственного
        $shipper_name = $pdo->prepare("SELECT full_name FROM operators WHERE id = ? AND role = 'shipper'");
        $shipper_name->execute([$shipped_by]);
        $shipped_by_name = $shipper_name->fetchColumn();
        if (!$shipped_by_name) {
            throw new Exception('Некорректный ответственный за отгрузку.');
        }

        // Сохранение отгрузки
        $pdo->prepare("
            INSERT INTO shipments (batch_id, client_id, shipment_date, bottles_shipped, waybill_number, shipped_by)
            VALUES (?, ?, CURDATE(), ?, ?, ?)
        ")->execute([$batch_id, $client_id, $bottles, $waybill, $shipped_by_name]);

        // Обновление партии
        $pdo->prepare("UPDATE batches SET remaining_bottles = remaining_bottles - ? WHERE id = ?")->execute([$bottles, $batch_id]);
        if ($remaining - $bottles == 0) {
            $pdo->prepare("UPDATE batches SET status = 'Полностью реализована' WHERE id = ?")->execute([$batch_id]);
        }

        $message = "✅ Отгрузка оформлена. Партия: " . $_POST['batch_number'];
        $message_type = 'success';

        // Обновляем список (чтобы убрать полностью отгруженные)
        $ready_batches = $pdo->query("
            SELECT b.id, b.batch_number, b.remaining_bottles, wb.name AS brand, 
                   bt.volume_l, bt.material, b.bottling_datetime
            FROM batches b
            JOIN water_brands wb ON b.brand_id = wb.id
            JOIN bottle_types bt ON b.bottle_type_id = bt.id
            WHERE b.status = 'Годна к реализации'
            ORDER BY b.bottling_datetime DESC
        ")->fetchAll();

    } catch (Exception $e) {
        $message = "❌ " . $e->getMessage();
        $message_type = 'error';
    }
}

// === ПОСЛЕДНИЕ ОТГРУЗКИ ===
$recent_shipments = $pdo->query("
    SELECT s.waybill_number, c.name AS client, s.bottles_shipped, s.shipment_date, b.batch_number
    FROM shipments s
    JOIN batches b ON s.batch_id = b.id
    JOIN clients c ON s.client_id = c.id
    ORDER BY s.shipment_date DESC
    LIMIT 10
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Отгрузки | AquaTrack</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        :root {
            --bg: #0c1a2d;
            --card-bg: #1a3a5a;
            --header-bg: rgba(12, 26, 45, 0.95);
            --text: #e0f0ff;
            --text-secondary: #a0c4e0;
            --accent: #4fc3f7;
            --accent-dark: #0288d1;
            --success: #81c784;
            --danger: #f44336;
            --border: #2a4a6d;
        }
        * { margin:0; padding:0; box-sizing:border-box; font-family: 'Segoe UI', system-ui, sans-serif; }
        body { background: var(--bg); color: var(--text); line-height: 1.6; }
        .container { max-width: 1000px; margin: 0 auto; padding: 20px; }
        nav { background: var(--header-bg); padding: 14px 20px; position: sticky; top: 0; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; }
        .logo { font-size: 22px; font-weight: 700; color: var(--accent); display: flex; align-items: center; gap: 10px; }
        .nav-links { display: flex; list-style: none; gap: 16px; }
        .nav-links a { color: var(--text-secondary); text-decoration: none; padding: 6px 12px; border-radius: 6px; }
        .nav-links a:hover, .nav-links a.active { background: rgba(79,195,247,0.15); color: var(--accent); }

        header { text-align: center; padding: 30px 0 20px; }
        h1 { font-size: 28px; margin-bottom: 8px; color: var(--accent); }
        .subtitle { color: var(--text-secondary); }

        .alert { padding: 16px; border-radius: 8px; margin: 20px 0; }
        .alert-success { background: rgba(129,199,132,0.2); border-left: 4px solid var(--success); }
        .alert-error { background: rgba(244,67,80,0.2); border-left: 4px solid var(--danger); }
        .alert-success, .alert-error { color: var(--text); }

        .form-container, .section {
            background: var(--card-bg); padding: 24px; border-radius: 16px; margin: 24px 0; border: 1px solid var(--border);
        }
        .section-title { font-size: 20px; margin-bottom: 20px; color: var(--accent); display: flex; align-items: center; gap: 10px; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 6px; font-weight: 600; }
        input, select, textarea { width: 100%; padding: 10px 12px; border: 1px solid var(--border); border-radius: 6px; background: #142c45; color: var(--text); }
        .btn { background: var(--accent-dark); color: white; border: none; padding: 12px 28px; border-radius: 8px; font-weight: 600; font-size: 16px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; }
        .btn:hover { opacity: 0.9; }

        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        th, td { padding: 12px 10px; text-align: left; border-bottom: 1px solid var(--border); }
        th { color: var(--text-secondary); font-weight: 600; }
        tr:last-child td { border-bottom: none; }
        .empty { text-align: center; color: var(--text-secondary); padding: 20px; font-style: italic; }

        footer { text-align: center; color: var(--text-secondary); padding: 20px 0; border-top: 1px solid var(--border); margin-top: 20px; }
    </style>
</head>
<body>
    <nav>
        <div class="logo"><i class="fas fa-droplet"></i> AquaTrack</div>
        <ul class="nav-links">
            <li><a href="index.php"><i class="fas fa-home"></i> Главная</a></li>
            <li><a href="production.php"><i class="fas fa-industry"></i> Производство</a></li>
            <li><a href="shipments.php" class="active"><i class="fas fa-truck"></i> Отгрузки</a></li>
            <li><a href="archive.php"><i class="fas fa-archive"></i> Архив</a></li>
            <li><a href="reports.php"><i class="fas fa-file-pdf"></i> Отчёты</a></li>
        </ul>
    </nav>

    <div class="container">
        <header>
            <h1>Отгрузка продукции</h1>
            <div class="subtitle">Оформление отгрузки готовых партий клиентам</div>
        </header>

        <?php if ($message): ?>
            <div class="alert alert-<?= $message_type ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <!-- Форма отгрузки -->
        <div class="form-container">
            <h2 class="section-title"><i class="fas fa-truck"></i> Новая отгрузка</h2>
            <?php if (empty($ready_batches)): ?>
                <p class="empty">Нет партий, готовых к отгрузке. Сначала завершите производственный цикл.</p>
            <?php else: ?>
                <form method="POST">
                    <div class="form-group">
                        <label for="batch_id">Партия *</label>
                        <select name="batch_id" id="batch_id" required onchange="updateBatchInfo(this)">
                            <option value="">Выберите партию</option>
                            <?php foreach ($ready_batches as $b): ?>
                                <option value="<?= $b['id'] ?>" data-number="<?= htmlspecialchars($b['batch_number']) ?>" data-remaining="<?= $b['remaining_bottles'] ?>">
                                    <?= htmlspecialchars($b['batch_number']) ?> — <?= htmlspecialchars($b['brand']) ?>, <?= $b['volume_l'] ?> л (остаток: <?= number_format($b['remaining_bottles'], 0, ' ', ' ') ?> бут.)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="client_id">Контрагент *</label>
                        <select name="client_id" id="client_id" required>
                            <option value="">Выберите клиента</option>
                            <?php foreach ($clients as $c): ?>
                                <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="bottles_shipped">Количество бутылок *</label>
                        <input type="number" name="bottles_shipped" id="bottles_shipped" required min="1" placeholder="Введите количество">
                        <div id="remaining-hint" style="font-size:13px; color:var(--text-secondary); margin-top:4px;"></div>
                    </div>

                    <div class="form-group">
                        <label for="waybill_number">Номер ТТН *</label>
                        <input type="text" name="waybill_number" id="waybill_number" required placeholder="Например: ТТН-2025-0001">
                    </div>

                    <div class="form-group">
                        <label for="shipped_by">ФИО ответственного *</label>
                        <select name="shipped_by" id="shipped_by" required>
                            <option value="">Выберите ответственного</option>
                            <?php foreach ($shippers as $s): ?>
                                <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <input type="hidden" name="batch_number" id="hidden_batch_number">
                    <button type="submit" class="btn"><i class="fas fa-truck"></i> Оформить отгрузку</button>
                </form>
            <?php endif; ?>
        </div>

        <!-- Последние отгрузки -->
        <div class="section">
            <h2 class="section-title"><i class="fas fa-history"></i> История отгрузок</h2>
            <?php if ($recent_shipments): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Партия</th>
                            <th>Клиент</th>
                            <th>Бутылок</th>
                            <th>ТТН</th>
                            <th>Дата</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_shipments as $s): ?>
                            <tr>
                                <td><?= htmlspecialchars($s['batch_number']) ?></td>
                                <td><?= htmlspecialchars($s['client']) ?></td>
                                <td><?= number_format($s['bottles_shipped'], 0, ' ', ' ') ?></td>
                                <td><?= htmlspecialchars($s['waybill_number']) ?></td>
                                <td><?= date('d.m.Y', strtotime($s['shipment_date'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p class="empty">Отгрузок пока нет.</p>
            <?php endif; ?>
        </div>

        <footer>
            Все отгрузки фиксируются с указанием ТТН, клиента и ответственного лица. Данные хранятся в архиве.
        </footer>
    </div>

    <script>
        function updateBatchInfo(select) {
            const option = select.options[select.selectedIndex];
            const batchNumber = option.getAttribute('data-number');
            const remaining = option.getAttribute('data-remaining');
            const bottlesInput = document.getElementById('bottles_shipped');
            const hint = document.getElementById('remaining-hint');
            const hiddenBatch = document.getElementById('hidden_batch_number');

            if (batchNumber) {
                bottlesInput.max = remaining;
                bottlesInput.placeholder = `Максимум: ${remaining}`;
                hint.textContent = `Максимальное количество для отгрузки: ${remaining} бутылок`;
                hiddenBatch.value = batchNumber;
            } else {
                hint.textContent = '';
                bottlesInput.max = '';
                bottlesInput.placeholder = 'Введите количество';
                hiddenBatch.value = '';
            }
        }
    </script>
</body>
</html>