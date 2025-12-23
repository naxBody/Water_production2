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

// === CSRF ТОКЕН ===
session_start();
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// === ЗАГРУЗКА ДАННЫХ ===
$ready_batches = $pdo->query("
    SELECT b.id, b.batch_number, b.remaining_bottles, b.bottling_datetime, 
           DATE_ADD(b.bottling_datetime, INTERVAL b.shelf_life_months MONTH) AS expiry_date,
           wb.name AS brand, bt.volume_l, bt.material, b.total_bottles AS bottles_produced
    FROM batches b
    JOIN water_brands wb ON b.brand_id = wb.id
    JOIN bottle_types bt ON b.bottle_type_id = bt.id
    WHERE b.status = 'Годна к реализации'
    ORDER BY b.bottling_datetime DESC
")->fetchAll();

$clients = $pdo->query("SELECT id, name, contact_person FROM clients ORDER BY name")->fetchAll();
$shippers = $pdo->query("SELECT id, full_name FROM operators WHERE role = 'shipper' ORDER BY full_name")->fetchAll();

// === ОБРАБОТКА ФОРМЫ ===
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Проверка CSRF токена
        if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
            throw new Exception('Неверный CSRF токен. Попробуйте отправить форму снова.');
        }
        
        $batch_id = (int)$_POST['batch_id'];
        $client_id = (int)$_POST['client_id'];
        $bottles = (int)$_POST['bottles_shipped'];
        $waybill = trim($_POST['waybill_number']);
        $shipped_by = (int)$_POST['shipped_by'];
        $notes = trim($_POST['notes'] ?? '');

        // Валидация
        if (!$batch_id || !$client_id || $bottles <= 0 || !$waybill || !$shipped_by) {
            throw new Exception('Заполните все поля.');
        }

        // Проверка остатка
        $batch = $pdo->prepare("SELECT remaining_bottles, batch_number FROM batches WHERE id = ?");
        $batch->execute([$batch_id]);
        $batch_data = $batch->fetch();
        $remaining = $batch_data['remaining_bottles'];
        $batch_number = $batch_data['batch_number'];
        
        if ($bottles > $remaining) {
            throw new Exception('Нельзя отгрузить больше, чем осталось в партии (' . $remaining . ' бут.).');
        }

        // Имя ответственного
        $shipper_name = $pdo->prepare("SELECT full_name FROM operators WHERE id = ? AND role = 'shipper'");
        $shipper_name->execute([$shipped_by]);
        $shipped_by_name = $shipper_name->fetchColumn();
        if (!$shipped_by_name) {
            throw new Exception('Некорректный ответственный за отгрузку.');
        }

        // Начинаем транзакцию для обеспечения целостности данных
        $pdo->beginTransaction();

        // Сохранение отгрузки
        $pdo->prepare("
            INSERT INTO shipments (batch_id, client_id, shipment_date, bottles_shipped, waybill_number, shipped_by, notes)
            VALUES (?, ?, CURDATE(), ?, ?, ?, ?)
        ")->execute([$batch_id, $client_id, $bottles, $waybill, $shipped_by_name, $notes]);

        // Обновление партии
        $pdo->prepare("UPDATE batches SET remaining_bottles = remaining_bottles - ? WHERE id = ?")->execute([$bottles, $batch_id]);
        if ($remaining - $bottles == 0) {
            $pdo->prepare("UPDATE batches SET status = 'Полностью реализована' WHERE id = ?")->execute([$batch_id]);
        }

        $pdo->commit();

        // Find client name safely to avoid errors if client is not found
        $client_key = array_search($client_id, array_column($clients, 'id'));
        $client_name = 'Клиент';
        if ($client_key !== false && isset($clients[$client_key]['name'])) {
            $client_name = $clients[$client_key]['name'];
        }
        $message = "✅ Отгрузка оформлена. Партия: " . $batch_number . ", Клиент: " . htmlspecialchars($client_name);
        $message_type = 'success';

        // Обновляем список (чтобы убрать полностью отгруженные)
        $ready_batches = $pdo->query("
            SELECT b.id, b.batch_number, b.remaining_bottles, b.bottling_datetime, 
                   DATE_ADD(b.bottling_datetime, INTERVAL b.shelf_life_months MONTH) AS expiry_date,
                   wb.name AS brand, bt.volume_l, bt.material, b.total_bottles AS bottles_produced
            FROM batches b
            JOIN water_brands wb ON b.brand_id = wb.id
            JOIN bottle_types bt ON b.bottle_type_id = bt.id
            WHERE b.status = 'Годна к реализации'
            ORDER BY b.bottling_datetime DESC
        ")->fetchAll();

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollback();
        }
        $message = "❌ " . $e->getMessage();
        $message_type = 'error';
    }
}

// === ПОСЛЕДНИЕ ОТГРУЗКИ ===
$recent_shipments = $pdo->query("
    SELECT s.waybill_number, s.shipment_date, s.bottles_shipped, s.shipped_by, s.notes,
           c.name AS client, b.batch_number, b.bottling_datetime, 
           DATE_ADD(b.bottling_datetime, INTERVAL b.shelf_life_months MONTH) AS expiry_date,
           wb.name AS brand, bt.volume_l
    FROM shipments s
    JOIN batches b ON s.batch_id = b.id
    JOIN clients c ON s.client_id = c.id
    JOIN water_brands wb ON b.brand_id = wb.id
    JOIN bottle_types bt ON b.bottle_type_id = bt.id
    ORDER BY s.shipment_date DESC
    LIMIT 20
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
        .form-row { display: flex; gap: 20px; margin-bottom: 20px; }
        .form-row .form-group { flex: 1; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 6px; font-weight: 600; }
        input, select, textarea { width: 100%; padding: 10px 12px; border: 1px solid var(--border); border-radius: 6px; background: #142c45; color: var(--text); }
        .btn { background: var(--accent-dark); color: white; border: none; padding: 12px 28px; border-radius: 8px; font-weight: 600; font-size: 16px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; }
        .btn:hover { opacity: 0.9; }
        .info-row { display: flex; justify-content: space-between; margin-bottom: 8px; flex-wrap: wrap; }
        .info-row > div { flex: 1; min-width: 200px; }

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
                    <div class="form-row">
                        <div class="form-group">
                            <label for="batch_id">Партия *</label>
                            <select name="batch_id" id="batch_id" required onchange="updateBatchInfo(this)">
                                <option value="">Выберите партию</option>
                                <?php foreach ($ready_batches as $b): ?>
                                    <option value="<?= $b['id'] ?>" 
                                        data-number="<?= htmlspecialchars($b['batch_number']) ?>" 
                                        data-remaining="<?= $b['remaining_bottles'] ?>"
                                        data-brand="<?= htmlspecialchars($b['brand']) ?>"
                                        data-volume="<?= $b['volume_l'] ?>"
                                        data-material="<?= htmlspecialchars($b['material']) ?>"
                                        data-production="<?= date('d.m.Y', strtotime($b['bottling_datetime'])) ?>"
                                        data-expiry="<?= date('d.m.Y', strtotime($b['expiry_date'])) ?>"
                                        data-total="<?= $b['bottles_produced'] ?>">
                                        <?= htmlspecialchars($b['batch_number']) ?> — <?= htmlspecialchars($b['brand']) ?>, <?= $b['volume_l'] ?> л (остаток: <?= number_format($b['remaining_bottles'], 0, ' ', ' ') ?> бут.)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="client_id">Контрагент *</label>
                            <select name="client_id" id="client_id" required onchange="updateClientInfo(this)">
                                <option value="">Выберите клиента</option>
                                <?php foreach ($clients as $c): ?>
                                    <option value="<?= $c['id'] ?>" 
                                        data-contact="<?= htmlspecialchars($c['contact_person'] ?? '') ?>" 
                                        data-phone="">
                                        <?= htmlspecialchars($c['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div id="client-info" style="margin-top: 10px; font-size: 14px; color: var(--text-secondary); display: none;">
                                <div><strong>Контактное лицо:</strong> <span id="client-contact"></span></div>
                                <div><strong>Телефон:</strong> <span id="client-phone"></span></div>
                            </div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="bottles_shipped">Количество бутылок *</label>
                            <input type="number" name="bottles_shipped" id="bottles_shipped" required min="1" placeholder="Введите количество" oninput="calculateVolume(this)">
                            <div id="remaining-hint" style="font-size:13px; color:var(--text-secondary); margin-top:4px;"></div>
                            <div id="volume-calculation" style="font-size:13px; color:var(--accent); margin-top:4px; display:none;"></div>
                        </div>

                        <div class="form-group">
                            <label for="waybill_number">Номер ТТН *</label>
                            <input type="text" name="waybill_number" id="waybill_number" required placeholder="Например: ТТН-2025-0001">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="shipped_by">ФИО ответственного *</label>
                        <select name="shipped_by" id="shipped_by" required>
                            <option value="">Выберите ответственного</option>
                            <?php foreach ($shippers as $s): ?>
                                <option value="<?= $s['id'] ?>" data-position=""><?= htmlspecialchars($s['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Дополнительная информация о выбранной партии -->
                    <div id="batch-info" class="info-box" style="display: none; background: rgba(26, 58, 90, 0.5); padding: 15px; border-radius: 8px; margin-top: 15px; border-left: 3px solid var(--accent);">
                        <h3 style="margin-top: 0; color: var(--accent);"><i class="fas fa-info-circle"></i> Информация о партии</h3>
                        <div class="info-row">
                            <div><strong>Марка воды:</strong> <span id="info-brand"></span></div>
                            <div><strong>Объем бутылки:</strong> <span id="info-volume"></span> л</div>
                        </div>
                        <div class="info-row">
                            <div><strong>Материал бутылки:</strong> <span id="info-material"></span></div>
                            <div><strong>Произведено:</strong> <span id="info-production"></span></div>
                        </div>
                        <div class="info-row">
                            <div><strong>Годен до:</strong> <span id="info-expiry"></span></div>
                            <div><strong>Всего произведено:</strong> <span id="info-total"></span> бут.</div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="notes">Примечания</label>
                        <textarea name="notes" id="notes" placeholder="Дополнительная информация об отгрузке" rows="2"></textarea>
                    </div>

                    <input type="hidden" name="batch_number" id="hidden_batch_number">
                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                    <button type="submit" class="btn"><i class="fas fa-truck"></i> Оформить отгрузку</button>
                    <button type="button" class="btn" onclick="clearForm()" style="background: #f44336; margin-left: 10px;"><i class="fas fa-eraser"></i> Очистить форму</button>
                </form>
            <?php endif; ?>
        </div>

        <!-- Последние отгрузки -->
        <div class="section">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h2 class="section-title"><i class="fas fa-history"></i> История отгрузок</h2>
                <div style="display: flex; gap: 15px; align-items: center;">
                    <div class="stats-box" style="background: rgba(26, 58, 90, 0.5); padding: 10px 15px; border-radius: 8px; border-left: 3px solid var(--accent);">
                        <strong>Всего отгружено:</strong> 
                        <span style="color: var(--accent);">
                            <?= number_format(array_sum(array_column($recent_shipments, 'bottles_shipped')), 0, ' ', ' ') ?> бут.
                        </span>
                    </div>
                    <button class="btn" onclick="exportShipments()" style="background: #4caf50;"><i class="fas fa-file-export"></i> Экспорт в CSV</button>
                </div>
            </div>
            
            <?php if ($recent_shipments): ?>
                <!-- Фильтры -->
                <div class="filters" style="display: flex; gap: 15px; margin-bottom: 20px; flex-wrap: wrap;">
                    <div>
                        <label for="filter_client" style="display: block; margin-bottom: 5px; font-size: 14px;">Клиент</label>
                        <select id="filter_client" style="width: 200px; padding: 8px; border: 1px solid var(--border); border-radius: 6px; background: #142c45; color: var(--text);">
                            <option value="">Все клиенты</option>
                            <?php 
                            $unique_clients = array_unique(array_column($recent_shipments, 'client'));
                            foreach ($unique_clients as $client): 
                            ?>
                                <option value="<?= htmlspecialchars($client) ?>"><?= htmlspecialchars($client) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label for="filter_date_from" style="display: block; margin-bottom: 5px; font-size: 14px;">Дата от</label>
                        <input type="date" id="filter_date_from" style="width: 150px; padding: 8px; border: 1px solid var(--border); border-radius: 6px; background: #142c45; color: var(--text);">
                    </div>
                    
                    <div>
                        <label for="filter_date_to" style="display: block; margin-bottom: 5px; font-size: 14px;">Дата до</label>
                        <input type="date" id="filter_date_to" style="width: 150px; padding: 8px; border: 1px solid var(--border); border-radius: 6px; background: #142c45; color: var(--text);">
                    </div>
                    
                    <div>
                        <label for="filter_min_bottles" style="display: block; margin-bottom: 5px; font-size: 14px;">Мин. бутылок</label>
                        <input type="number" id="filter_min_bottles" placeholder="0" min="0" style="width: 120px; padding: 8px; border: 1px solid var(--border); border-radius: 6px; background: #142c45; color: var(--text);">
                    </div>
                </div>
                
                <table id="shipments-table">
                    <thead>
                        <tr>
                            <th onclick="sortTable(0)" style="cursor: pointer;">Партия <i class="fas fa-sort"></i></th>
                            <th onclick="sortTable(1)" style="cursor: pointer;">Клиент <i class="fas fa-sort"></i></th>
                            <th onclick="sortTable(2)" style="cursor: pointer;">Марка воды <i class="fas fa-sort"></i></th>
                            <th onclick="sortTable(3)" style="cursor: pointer;">Объем <i class="fas fa-sort"></i></th>
                            <th onclick="sortTable(4)" style="cursor: pointer;">Бутылок <i class="fas fa-sort"></i></th>
                            <th onclick="sortTable(5)" style="cursor: pointer;">ТТН <i class="fas fa-sort"></i></th>
                            <th onclick="sortTable(6)" style="cursor: pointer;">Дата <i class="fas fa-sort"></i></th>
                            <th onclick="sortTable(7)" style="cursor: pointer;">Ответственный <i class="fas fa-sort"></i></th>
                            <th onclick="sortTable(8)" style="cursor: pointer;">Примечания <i class="fas fa-sort"></i></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_shipments as $s): ?>
                            <tr data-client="<?= htmlspecialchars($s['client']) ?>" 
                                data-date="<?= $s['shipment_date'] ?>" 
                                data-bottles="<?= $s['bottles_shipped'] ?>">
                                <td>
                                    <div><strong><?= htmlspecialchars($s['batch_number']) ?></strong></div>
                                    <small style="color: var(--text-secondary);">Произведено: <?= date('d.m.Y', strtotime($s['bottling_datetime'])) ?></small><br>
                                    <small style="color: var(--text-secondary);">Годен до: <?= date('d.m.Y', strtotime($s['expiry_date'])) ?></small>
                                </td>
                                <td><?= htmlspecialchars($s['client']) ?></td>
                                <td><?= htmlspecialchars($s['brand']) ?></td>
                                <td><?= $s['volume_l'] ?> л</td>
                                <td><?= number_format($s['bottles_shipped'], 0, ' ', ' ') ?></td>
                                <td><?= htmlspecialchars($s['waybill_number']) ?></td>
                                <td><?= date('d.m.Y', strtotime($s['shipment_date'])) ?></td>
                                <td><?= htmlspecialchars($s['shipped_by']) ?></td>
                                <td><?= htmlspecialchars($s['notes'] ?? '') ?></td>
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
            const brand = option.getAttribute('data-brand');
            const volume = option.getAttribute('data-volume');
            const material = option.getAttribute('data-material');
            const production = option.getAttribute('data-production');
            const expiry = option.getAttribute('data-expiry');
            const total = option.getAttribute('data-total');
            
            const bottlesInput = document.getElementById('bottles_shipped');
            const hint = document.getElementById('remaining-hint');
            const hiddenBatch = document.getElementById('hidden_batch_number');
            
            // Обновляем информацию о партии
            if (batchNumber) {
                bottlesInput.max = remaining;
                bottlesInput.placeholder = `Максимум: ${remaining}`;
                hint.textContent = `Максимальное количество для отгрузки: ${remaining} бутылок`;
                hiddenBatch.value = batchNumber;
                
                // Показываем дополнительную информацию о партии
                document.getElementById('info-brand').textContent = brand;
                document.getElementById('info-volume').textContent = volume;
                document.getElementById('info-material').textContent = material;
                document.getElementById('info-production').textContent = production;
                document.getElementById('info-expiry').textContent = expiry;
                document.getElementById('info-total').textContent = total;
                
                document.getElementById('batch-info').style.display = 'block';
            } else {
                hint.textContent = '';
                bottlesInput.max = '';
                bottlesInput.placeholder = 'Введите количество';
                hiddenBatch.value = '';
                
                document.getElementById('batch-info').style.display = 'none';
            }
        }
        
        // Функция обновления информации о клиенте
        function updateClientInfo(select) {
            const option = select.options[select.selectedIndex];
            const contact = option.getAttribute('data-contact');
            const phone = option.getAttribute('data-phone');
            
            const clientInfo = document.getElementById('client-info');
            const contactSpan = document.getElementById('client-contact');
            const phoneSpan = document.getElementById('client-phone');
            
            if (contact) {
                contactSpan.textContent = contact;
                clientInfo.style.display = 'block';
            } else {
                contactSpan.textContent = '';
            }
            
            if (phone && phone.trim() !== '') {
                phoneSpan.textContent = phone;
            } else {
                phoneSpan.textContent = 'Телефон не указан';
            }
        }
        
        // Функция расчета объема по количеству бутылок
        function calculateVolume(input) {
            const bottles = parseInt(input.value) || 0;
            const batchSelect = document.getElementById('batch_id');
            const volumeCalcDiv = document.getElementById('volume-calculation');
            
            if (batchSelect.value === '' || bottles <= 0) {
                volumeCalcDiv.style.display = 'none';
                return;
            }
            
            const volume = batchSelect.options[batchSelect.selectedIndex].getAttribute('data-volume');
            const totalVolume = (bottles * parseFloat(volume)).toFixed(2);
            
            volumeCalcDiv.innerHTML = `Объем: ${totalVolume} л`;
            volumeCalcDiv.style.display = 'block';
        }
        
        // Функция очистки формы
        function clearForm() {
            if (confirm('Вы уверены, что хотите очистить форму?')) {
                document.querySelector('form').reset();
                document.getElementById('batch-info').style.display = 'none';
                document.getElementById('client-info').style.display = 'none';
                document.getElementById('volume-calculation').style.display = 'none';
                document.getElementById('remaining-hint').textContent = '';
                document.getElementById('bottles_shipped').placeholder = 'Введите количество';
                document.getElementById('bottles_shipped').style.borderColor = '';
                document.getElementById('bottles_shipped').title = '';
            }
        }
        
        // Добавляем валидацию формы
        function validateForm() {
            const bottlesInput = document.getElementById('bottles_shipped');
            const batchSelect = document.getElementById('batch_id');
            
            if (batchSelect.value === '') {
                alert('Пожалуйста, выберите партию');
                return false;
            }
            
            const remaining = parseInt(batchSelect.options[batchSelect.selectedIndex].getAttribute('data-remaining'));
            const bottlesValue = parseInt(bottlesInput.value) || 0;
            
            if (bottlesValue <= 0) {
                alert('Количество бутылок должно быть больше 0');
                return false;
            }
            
            if (bottlesValue > remaining) {
                alert('Количество бутылок для отгрузки не может превышать остаток в партии (' + remaining + ' бут.)');
                return false;
            }
            
            return true;
        }
        
        // Валидация при отправке формы
        document.querySelector('form').addEventListener('submit', function(e) {
            if (!validateForm()) {
                e.preventDefault();
                return false;
            }
        });
        
        // Валидация в реальном времени при изменении количества бутылок
        document.getElementById('bottles_shipped').addEventListener('input', function() {
            const bottlesInput = this;
            const batchSelect = document.getElementById('batch_id');
            
            if (batchSelect.value === '') return;
            
            const remaining = parseInt(batchSelect.options[batchSelect.selectedIndex].getAttribute('data-remaining'));
            const bottlesValue = parseInt(bottlesInput.value) || 0;
            
            if (bottlesValue > remaining) {
                bottlesInput.style.borderColor = '#f44336';
                bottlesInput.title = 'Превышено количество доступных бутылок (' + remaining + ')';
            } else if (bottlesValue > 0) {
                bottlesInput.style.borderColor = '#81c784';
                bottlesInput.title = '';
            } else {
                bottlesInput.style.borderColor = '';
                bottlesInput.title = '';
            }
        });
        
        // Функция сортировки таблицы
        function sortTable(columnIndex) {
            const table = document.getElementById('shipments-table');
            const tbody = table.querySelector('tbody');
            const rows = Array.from(tbody.querySelectorAll('tr'));
            
            // Определяем тип данных для сортировки (0-номер строки, 4-бутылки, 6-дата)
            const isNumeric = [4].includes(columnIndex); // столбцы с числовыми значениями
            const isDate = columnIndex === 6; // столбец с датой
            
            rows.sort((a, b) => {
                const aValue = a.cells[columnIndex].textContent.trim();
                const bValue = b.cells[columnIndex].textContent.trim();
                
                if (isNumeric) {
                    return parseInt(aValue.replace(/\s/g, '')) - parseInt(bValue.replace(/\s/g, ''));
                } else if (isDate) {
                    // Преобразуем дату из формата DD.MM.YYYY в YYYY-MM-DD для корректной сортировки
                    const aDate = aValue.split('.').reverse().join('-');
                    const bDate = bValue.split('.').reverse().join('-');
                    return new Date(aDate) - new Date(bDate);
                } else {
                    return aValue.localeCompare(bValue, 'ru');
                }
            });
            
            // Перестраиваем таблицу с отсортированными строками
            rows.forEach(row => tbody.appendChild(row));
        }
        
        // Функция фильтрации таблицы
        function filterTable() {
            const clientFilter = document.getElementById('filter_client').value;
            const dateFrom = document.getElementById('filter_date_from').value;
            const dateTo = document.getElementById('filter_date_to').value;
            const minBottles = document.getElementById('filter_min_bottles').value;
            
            const rows = document.querySelectorAll('#shipments-table tbody tr');
            
            rows.forEach(row => {
                const client = row.getAttribute('data-client');
                const date = row.getAttribute('data-date');
                const bottles = parseInt(row.getAttribute('data-bottles'));
                
                let showRow = true;
                
                // Фильтр по клиенту
                if (clientFilter && client !== clientFilter) {
                    showRow = false;
                }
                
                // Фильтр по дате "от"
                if (dateFrom && date < dateFrom) {
                    showRow = false;
                }
                
                // Фильтр по дате "до"
                if (dateTo && date > dateTo) {
                    showRow = false;
                }
                
                // Фильтр по минимальному количеству бутылок
                if (minBottles && bottles < parseInt(minBottles)) {
                    showRow = false;
                }
                
                row.style.display = showRow ? '' : 'none';
            });
        }
        
        // Добавляем обработчики событий для фильтров
        document.getElementById('filter_client').addEventListener('change', filterTable);
        document.getElementById('filter_date_from').addEventListener('change', filterTable);
        document.getElementById('filter_date_to').addEventListener('change', filterTable);
        document.getElementById('filter_min_bottles').addEventListener('input', filterTable);
        
        // Функция экспорта отгрузок в CSV
        function exportShipments() {
            const table = document.getElementById('shipments-table');
            const rows = table.querySelectorAll('tbody tr');
            let csv = 'Партия;Клиент;Марка;Объем;Количество;ТТН;Дата;Ответственный;Примечания\n';
            
            rows.forEach(row => {
                if (row.style.display !== 'none') { // Только видимые строки (после фильтрации)
                    const cells = row.querySelectorAll('td');
                    let rowData = [];
                    cells.forEach(cell => {
                        // Извлекаем текст из ячеек, убирая HTML-теги и лишние пробелы
                        let cellText = cell.textContent.trim();
                        // Убираем форматирование чисел (пробелы как разделители тысяч)
                        cellText = cellText.replace(/\s/g, '');
                        // Экранируем кавычки и добавляем кавычки к значению, если оно содержит точку с запятой
                        if (cellText.includes(';') || cellText.includes('"') || cellText.includes('\n')) {
                            cellText = '"' + cellText.replace(/"/g, '""') + '"';
                        }
                        rowData.push(cellText);
                    });
                    csv += rowData.join(';') + '\n';
                }
            });
            
            // Создаем и скачиваем CSV файл
            const blob = new Blob(['\\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);
            link.setAttribute('href', url);
            link.setAttribute('download', 'отгрузки_' + new Date().toISOString().slice(0, 10) + '.csv');
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }
    </script>
</body>
</html>