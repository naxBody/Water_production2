<?php
// === НАСТРОЙКИ БАЗЫ ДАННЫХ ===
$host = 'localhost';
$db   = 'bottled_water_control';
$user = 'root'; // ← ЗАМЕНИ
$pass = '';     // ← ЗАМЕНИ
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
    die("❌ Ошибка подключения к БД: " . htmlspecialchars($e->getMessage()));
}

// Получение ID партии из GET-параметра
$batch_id = isset($_GET['batch_id']) ? (int)$_GET['batch_id'] : 0;

if (!$batch_id) {
    header('Location: index.php');
    exit;
}

// Получение информации о партии
$stmt = $pdo->prepare("
    SELECT b.*, wb.name AS brand_name, bt.volume_l, bt.material, pl.name AS line_name,
           tw.odor, tw.taste, tw.transparency, tw.color, tw.ph, tw.hardness_mmol,
           tw.dry_residue_mg_l, tw.iron_mg_l, tw.nitrates_mg_l, tw.fluorides_mg_l,
           tw.omch_cfu_ml, tw.coliforms_detected, tw.thermotolerant_coliforms_detected,
           tw.pseudomonas_detected, tw.yeast_mold_cfu_ml, tw.tested_at, tw.tested_by
    FROM batches b
    JOIN water_brands wb ON b.brand_id = wb.id
    JOIN bottle_types bt ON b.bottle_type_id = bt.id
    JOIN production_lines pl ON b.production_line_id = pl.id
    JOIN treated_water_tests tw ON b.treated_test_id = tw.id
    WHERE b.id = ?
");
$stmt->execute([$batch_id]);
$batch = $stmt->fetch();

if (!$batch || $batch['status'] !== 'Брак') {
    header('Location: index.php');
    exit;
}

$message = '';
$message_type = '';

// Обработка формы обновления статуса партии
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $new_status = $_POST['status'];
        $notes = $_POST['notes'] ?? '';
        
        if ($new_status === 'Годна к реализации' || $new_status === 'Ожидает анализа') {
            $stmt = $pdo->prepare("UPDATE batches SET status = ?, notes = ? WHERE id = ?");
            $stmt->execute([$new_status, $notes, $batch_id]);
            
            $message = "Статус партии успешно обновлён.";
            $message_type = 'success';
            
            // Обновляем информацию о партии
            $stmt_update = $pdo->prepare("
                SELECT b.*, wb.name AS brand_name, bt.volume_l, bt.material, pl.name AS line_name,
                       tw.odor, tw.taste, tw.transparency, tw.color, tw.ph, tw.hardness_mmol,
                       tw.dry_residue_mg_l, tw.iron_mg_l, tw.nitrates_mg_l, tw.fluorides_mg_l,
                       tw.omch_cfu_ml, tw.coliforms_detected, tw.thermotolerant_coliforms_detected,
                       tw.pseudomonas_detected, tw.yeast_mold_cfu_ml, tw.tested_at, tw.tested_by
                FROM batches b
                JOIN water_brands wb ON b.brand_id = wb.id
                JOIN bottle_types bt ON b.bottle_type_id = bt.id
                JOIN production_lines pl ON b.production_line_id = pl.id
                JOIN treated_water_tests tw ON b.treated_test_id = tw.id
                WHERE b.id = ?
            ");
            $stmt_update->execute([$batch_id]);
            $batch = $stmt_update->fetch();
        } else {
            throw new Exception("Некорректный статус");
        }
    } catch (Exception $e) {
        $message = "Ошибка: " . $e->getMessage();
        $message_type = 'error';
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Редактирование партии | AquaTrack</title>
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
            --warning: #ffb300;
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

        .form-container { background: var(--card-bg); padding: 28px; border-radius: 16px; margin: 24px 0; border: 1px solid var(--border); }
        .form-title { font-size: 22px; margin-bottom: 20px; color: var(--accent); display: flex; align-items: center; gap: 10px; }
        .form-desc { color: var(--text-secondary); margin-bottom: 24px; font-size: 15px; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 6px; font-weight: 600; }
        .field-hint { font-size: 13px; color: var(--text-secondary); margin-top: 4px; }
        input, select, textarea { width: 100%; padding: 10px 12px; border: 1px solid var(--border); border-radius: 6px; background: #142c45; color: var(--text); }
        .btn { background: var(--accent-dark); color: white; border: none; padding: 12px 28px; border-radius: 8px; font-weight: 600; font-size: 16px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; }
        .btn:hover { opacity: 0.9; }
        .btn-back { background: #5c6b7a; }

        .alert { padding: 16px; border-radius: 8px; margin: 20px 0; }
        .alert-success { background: rgba(129,199,132,0.2); border-left: 4px solid var(--success); }
        .alert-error { background: rgba(244,67,80,0.2); border-left: 4px solid var(--danger); }
        .alert-success, .alert-error { color: var(--text); }

        .batch-info { background: var(--card-bg); padding: 20px; border-radius: 12px; margin-bottom: 20px; border: 1px solid var(--border); }
        .info-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid var(--border); }
        .info-row:last-child { border-bottom: none; }
        .info-label { font-weight: 600; color: var(--text-secondary); }
        .info-value { color: var(--text); }

        .status-tag { padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .status--danger { background: rgba(244,67,80,0.2); color: var(--danger); }
        
        footer { text-align: center; color: var(--text-secondary); padding: 20px 0; border-top: 1px solid var(--border); margin-top: 20px; }
    </style>
</head>
<body>
    <nav>
        <div class="logo"><i class="fas fa-droplet"></i> AquaTrack</div>
        <ul class="nav-links">
            <li><a href="index.php"><i class="fas fa-home"></i> Главная</a></li>
            <li><a href="production.php"><i class="fas fa-industry"></i> Производство</a></li>
            <li><a href="shipments.php"><i class="fas fa-truck"></i> Отгрузки</a></li>
            <li><a href="archive.php"><i class="fas fa-archive"></i> Архив</a></li>
            <li><a href="reports.php"><i class="fas fa-file-pdf"></i> Отчёты</a></li>
            <li><a href="sources.php"><i class="fas fa-map-marker-alt"></i> Источники</a></li>
        </ul>
    </nav>

    <div class="container">
        <header>
            <h1>Редактирование забракованной партии</h1>
            <div class="subtitle">Партия <?= htmlspecialchars($batch['batch_number']) ?> • Статус: <span class="status-tag status--danger">Брак</span></div>
        </header>

        <?php if ($message): ?>
            <div class="alert alert-<?= $message_type ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <div class="batch-info">
            <div class="info-row">
                <span class="info-label">Номер партии:</span>
                <span class="info-value"><?= htmlspecialchars($batch['batch_number']) ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Марка воды:</span>
                <span class="info-value"><?= htmlspecialchars($batch['brand_name']) ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Дата розлива:</span>
                <span class="info-value"><?= date('d.m.Y H:i', strtotime($batch['bottling_datetime'])) ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Объём бутылок:</span>
                <span class="info-value"><?= $batch['volume_l'] ?> л (<?= htmlspecialchars($batch['material']) ?>)</span>
            </div>
            <div class="info-row">
                <span class="info-label">Линия розлива:</span>
                <span class="info-value"><?= htmlspecialchars($batch['line_name']) ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Всего бутылок:</span>
                <span class="info-value"><?= number_format($batch['total_bottles'], 0, ' ', ' ') ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Оператор:</span>
                <span class="info-value"><?= htmlspecialchars($batch['operator_name']) ?></span>
            </div>
        </div>

        <div class="form-container">
            <h2 class="form-title"><i class="fas fa-exclamation-triangle"></i> Причины брака</h2>
            <div class="form-group">
                <label>Результаты анализа</label>
                <div style="background: #142c45; padding: 15px; border-radius: 8px; margin-bottom: 15px;">
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                        <div><strong>pH:</strong> <?= $batch['ph'] ?></div>
                        <div><strong>Жёсткость:</strong> <?= $batch['hardness_mmol'] ?> ммоль/л</div>
                        <div><strong>Сухой остаток:</strong> <?= $batch['dry_residue_mg_l'] ?> мг/л</div>
                        <div><strong>Железо:</strong> <?= $batch['iron_mg_l'] ?> мг/л</div>
                        <div><strong>Нитраты:</strong> <?= $batch['nitrates_mg_l'] ?> мг/л</div>
                        <div><strong>Фториды:</strong> <?= $batch['fluorides_mg_l'] ?> мг/л</div>
                        <div><strong>ОМЧ:</strong> <?= $batch['omch_cfu_ml'] ?> КОЕ/мл</div>
                        <div><strong>Дрожжи/плесени:</strong> <?= $batch['yeast_mold_cfu_ml'] ?> КОЕ/мл</div>
                        <div><strong>Колиформы:</strong> <?= $batch['coliforms_detected'] ? 'ОБНАРУЖЕНЫ' : 'не обнаружены' ?></div>
                        <div><strong>Термотолерантные колиформы:</strong> <?= $batch['thermotolerant_coliforms_detected'] ? 'ОБНАРУЖЕНЫ' : 'не обнаружены' ?></div>
                        <div><strong>Псевдомонады:</strong> <?= $batch['pseudomonas_detected'] ? 'ОБНАРУЖЕНЫ' : 'не обнаружены' ?></div>
                        <div><strong>Прозрачность:</strong> <?= htmlspecialchars($batch['transparency']) ?></div>
                        <div><strong>Цвет:</strong> <?= htmlspecialchars($batch['color']) ?></div>
                        <div><strong>Запах:</strong> <?= htmlspecialchars($batch['odor']) ?></div>
                        <div><strong>Привкус:</strong> <?= htmlspecialchars($batch['taste']) ?></div>
                    </div>
                </div>
            </div>
            
            <form method="POST">
                <div class="form-group">
                    <label for="status">Новый статус *</label>
                    <select name="status" id="status" required>
                        <option value="">Выберите статус</option>
                        <option value="Годна к реализации">Годна к реализации</option>
                        <option value="Ожидает анализа">Ожидает анализа</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="notes">Комментарии</label>
                    <textarea name="notes" id="notes" rows="4" placeholder="Укажите причину изменения статуса или дополнительную информацию"><?= htmlspecialchars($batch['notes'] ?? '') ?></textarea>
                </div>
                
                <button type="submit" class="btn"><i class="fas fa-sync-alt"></i> Обновить статус</button>
                <a href="index.php" class="btn btn-back" style="margin-left: 10px;"><i class="fas fa-arrow-left"></i> Назад</a>
            </form>
        </div>
    </div>
    
    <footer>
        AquaTrack — система контроля производства питьевой бутилированной воды
    </footer>
</body>
</html>