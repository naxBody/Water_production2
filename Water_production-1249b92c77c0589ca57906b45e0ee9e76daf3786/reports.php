<?php
// === НАСТРОЙКИ БД ===
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
    die("❌ Ошибка БД: " . htmlspecialchars($e->getMessage()));
}

// === ПАРАМЕТРЫ ===
$journal_type = $_GET['journal'] ?? 'production';
$batch_id = (int)($_GET['batch_id'] ?? 0);

// === ЗАГРУЗКА ДАННЫХ ===
// Годные партии для паспорта
$good_batches = $pdo->query("
    SELECT b.id, b.batch_number, b.bottling_datetime, wb.name AS brand
    FROM batches b
    JOIN water_brands wb ON b.brand_id = wb.id
    WHERE b.status IN ('Годна к реализации', 'Частично отгружена', 'Полностью реализована')
    ORDER BY b.bottling_datetime DESC
")->fetchAll();

// Журналы
if ($journal_type === 'production') {
    $journal_data = $pdo->query("
        SELECT 
            b.batch_number, wb.name AS brand,
            bt.volume_l, bt.material,
            b.bottling_datetime, b.status,
            b.total_bottles, b.remaining_bottles
        FROM batches b
        JOIN water_brands wb ON b.brand_id = wb.id
        JOIN bottle_types bt ON b.bottle_type_id = bt.id
        ORDER BY b.bottling_datetime DESC
        LIMIT 100
    ")->fetchAll();
} elseif ($journal_type === 'rejection') {
    $journal_data = $pdo->query("
        SELECT 
            b.batch_number, wb.name AS brand,
            b.bottling_datetime,
            r.sampled_at AS raw_date,
            tw.tested_at AS analysis_date,
            'Микробиологический брак' AS reason
        FROM batches b
        JOIN water_brands wb ON b.brand_id = wb.id
        JOIN treated_water_tests tw ON b.treated_test_id = tw.id
        JOIN water_treatments t ON tw.treatment_id = t.id
        JOIN raw_water_tests r ON t.raw_test_id = r.id
        WHERE b.status = 'Брак'
        ORDER BY b.bottling_datetime DESC
    ")->fetchAll();
} elseif ($journal_type === 'stock') {
    $journal_data = $pdo->query("
        SELECT 
            b.batch_number, wb.name AS brand,
            bt.volume_l, bt.material,
            b.bottling_datetime,
            DATE_ADD(b.bottling_datetime, INTERVAL 12 MONTH) AS expiry_date,
            b.remaining_bottles
        FROM batches b
        JOIN water_brands wb ON b.brand_id = wb.id
        JOIN bottle_types bt ON b.bottle_type_id = bt.id
        WHERE b.remaining_bottles > 0
        ORDER BY b.bottling_datetime DESC
    ")->fetchAll();
}

// === ЭКСПОРТ В CSV ===
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $journal_type . '_' . date('Y-m-d') . '.csv');

    $output = fopen('php://output', 'w');
    // BOM для Excel
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    if ($journal_type === 'production') {
        fputcsv($output, ['Номер партии', 'Марка', 'Тара', 'Дата розлива', 'Статус', 'Всего бут.', 'Остаток'], ';');
        foreach ($journal_data as $row) {
            fputcsv($output, [
                $row['batch_number'],
                $row['brand'],
                $row['volume_l'] . ' л (' . $row['material'] . ')',
                date('d.m.Y H:i', strtotime($row['bottling_datetime'])),
                $row['status'],
                $row['total_bottles'],
                $row['remaining_bottles']
            ], ';');
        }
    } elseif ($journal_type === 'rejection') {
        fputcsv($output, ['Номер партии', 'Марка', 'Дата розлива', 'Причина'], ';');
        foreach ($journal_data as $row) {
            fputcsv($output, [
                $row['batch_number'],
                $row['brand'],
                date('d.m.Y H:i', strtotime($row['bottling_datetime'])),
                $row['reason']
            ], ';');
        }
    } elseif ($journal_type === 'stock') {
        fputcsv($output, ['Номер партии', 'Марка', 'Тара', 'Дата розлива', 'Срок годности', 'Остаток'], ';');
        foreach ($journal_data as $row) {
            fputcsv($output, [
                $row['batch_number'],
                $row['brand'],
                $row['volume_l'] . ' л (' . $row['material'] . ')',
                date('d.m.Y H:i', strtotime($row['bottling_datetime'])),
                date('d.m.Y', strtotime($row['expiry_date'])),
                $row['remaining_bottles']
            ], ';');
        }
    }

    fclose($output);
    exit;
}

// === ПАСПОРТ КАЧЕСТВА (HTML для печати) ===
if (isset($_GET['passport']) && $_GET['passport'] === 'html') {
    $batch_id = (int)$_GET['batch_id'];
    
    $stmt = $pdo->prepare("
        SELECT 
            b.batch_number, b.bottling_datetime, wb.name AS brand,
            bt.volume_l, bt.material,
            ws.name AS source_name,
            tw.ph, tw.hardness_mmol, tw.dry_residue_mg_l, tw.iron_mg_l, tw.nitrates_mg_l, tw.fluorides_mg_l,
            tw.omch_cfu_ml, tw.yeast_mold_cfu_ml,
            tw.coliforms_detected, tw.thermotolerant_coliforms_detected, tw.pseudomonas_detected,
            tw.tested_at
        FROM batches b
        JOIN water_brands wb ON b.brand_id = wb.id
        JOIN bottle_types bt ON b.bottle_type_id = bt.id
        JOIN treated_water_tests tw ON b.treated_test_id = tw.id
        JOIN water_treatments t ON tw.treatment_id = t.id
        JOIN raw_water_tests r ON t.raw_test_id = r.id
        JOIN water_sources ws ON r.source_id = ws.id
        WHERE b.id = ? AND b.status != 'Брак'
    ");
    $stmt->execute([$batch_id]);
    $data = $stmt->fetch();

    if (!$data) {
        echo "<div style='text-align:center; padding:50px; color:#f44336;'>Партия не найдена или забракована.</div>";
        exit;
    }

    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Паспорт качества</title>
        <style>
            body { font-family: Arial, sans-serif; max-width: 800px; margin: 0 auto; padding: 20px; }
            .header { text-align: center; margin-bottom: 30px; }
            .title { font-size: 20px; font-weight: bold; margin: 20px 0; }
            table { width: 100%; border-collapse: collapse; margin: 15px 0; }
            th, td { border: 1px solid #000; padding: 8px; text-align: left; }
            th { background: #f0f0f0; }
            .footer { margin-top: 40px; text-align: center; font-style: italic; }
        </style>
    </head>
    <body>
        <div class="header">
            <div style="font-size: 24px; font-weight: bold;">ПАСПОРТ КАЧЕСТВА</div>
            <div>питьевой бутилированной воды</div>
        </div>

        <div class="title">1. Общие сведения</div>
        <table>
            <tr><td>Наименование продукции</td><td><?= htmlspecialchars($data['brand']) ?> (питьевая вода)</td></tr>
            <tr><td>Номер партии</td><td><?= htmlspecialchars($data['batch_number']) ?></td></tr>
            <tr><td>Дата розлива</td><td><?= date('d.m.Y', strtotime($data['bottling_datetime'])) ?></td></tr>
            <tr><td>Срок годности</td><td><?= date('d.m.Y', strtotime($data['bottling_datetime'] . ' +12 months')) ?> (12 месяцев)</td></tr>
            <tr><td>Источник воды</td><td><?= htmlspecialchars($data['source_name']) ?></td></tr>
            <tr><td>Тара</td><td><?= $data['volume_l'] ?> л (<?= htmlspecialchars($data['material']) ?>)</td></tr>
        </table>

        <div class="title">2. Результаты лабораторного контроля</div>
        <table>
            <tr><th>Показатель</th><th>Норма по СТБ 1575-2013</th><th>Фактическое значение</th></tr>
            <tr><td>pH</td><td>6,5–9,0</td><td><?= $data['ph'] ?></td></tr>
            <tr><td>Жёсткость, ммоль/л</td><td>≤ 7,0</td><td><?= $data['hardness_mmol'] ?></td></tr>
            <tr><td>Сухой остаток, мг/л</td><td>≤ 1000</td><td><?= $data['dry_residue_mg_l'] ?></td></tr>
            <tr><td>Железо, мг/л</td><td>≤ 0,3</td><td><?= $data['iron_mg_l'] ?></td></tr>
            <tr><td>Нитраты, мг/л</td><td>≤ 45</td><td><?= $data['nitrates_mg_l'] ?></td></tr>
            <tr><td>Фториды, мг/л</td><td>0,6–1,5</td><td><?= $data['fluorides_mg_l'] ?></td></tr>
            <tr><td>ОМЧ, КОЕ/мл</td><td>≤ 100</td><td><?= $data['omch_cfu_ml'] ?></td></tr>
            <tr><td>Дрожжи и плесени, КОЕ/мл</td><td>≤ 100</td><td><?= $data['yeast_mold_cfu_ml'] ?></td></tr>
            <tr><td>Колиформные бактерии</td><td>Не допускаются</td><td><?= $data['coliforms_detected'] ? 'Обнаружены' : 'Не обнаружены' ?></td></tr>
            <tr><td>Термотолерантные колиформы</td><td>Не допускаются</td><td><?= $data['thermotolerant_coliforms_detected'] ? 'Обнаружены' : 'Не обнаружены' ?></td></tr>
            <tr><td>Pseudomonas aeruginosa</td><td>Не допускается</td><td><?= $data['pseudomonas_detected'] ? 'Обнаружена' : 'Не обнаружена' ?></td></tr>
        </table>

        <div class="title">3. Заключение</div>
        <p>
            Продукция <?= htmlspecialchars($data['brand']) ?>, партия <?= htmlspecialchars($data['batch_number']) ?>, 
            дата розлива <?= date('d.m.Y', strtotime($data['bottling_datetime'])) ?> 
            <?= $data['coliforms_detected'] || $data['thermotolerant_coliforms_detected'] || $data['pseudomonas_detected'] ? 
                '<span style="color:red;">НЕ СООТВЕТСТВУЕТ</span>' : 
                '<span style="color:green;">СООТВЕТСТВУЕТ</span>' 
            ?> требованиям СТБ 1575-2013.
        </p>

        <div class="footer">
            Дата формирования: <?= date('d.m.Y') ?><br>
            Документ действителен только при наличии оригинальной подписи и печати.
        </div>

        <script>
            window.print();
        </script>
    </body>
    </html>
    <?php
    exit;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Отчёты | AquaTrack</title>
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
            --border: #2a4a6d;
            --success: #81c784;
        }
        * { margin:0; padding:0; box-sizing:border-box; font-family: 'Segoe UI', system-ui, sans-serif; }
        body { background: var(--bg); color: var(--text); line-height: 1.6; }
        .container { max-width: 1200px; margin: 0 auto; padding: 20px; }
        nav { background: var(--header-bg); padding: 14px 20px; position: sticky; top: 0; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; }
        .logo { font-size: 22px; font-weight: 700; color: var(--accent); display: flex; align-items: center; gap: 10px; }
        .nav-links { display: flex; list-style: none; gap: 16px; }
        .nav-links a { color: var(--text-secondary); text-decoration: none; padding: 6px 12px; border-radius: 6px; }
        .nav-links a:hover, .nav-links a.active { background: rgba(79,195,247,0.15); color: var(--accent); }

        header { text-align: center; padding: 30px 0 20px; }
        h1 { font-size: 28px; margin-bottom: 8px; color: var(--accent); }
        .subtitle { color: var(--text-secondary); }

        .report-sections {
            display: grid; grid-template-columns: 1fr 1fr;
            gap: 24px;
            margin-bottom: 30px;
        }
        @media (max-width: 900px) {
            .report-sections { grid-template-columns: 1fr; }
        }

        .section {
            background: var(--card-bg); padding: 24px; border-radius: 16px; border: 1px solid var(--border);
        }
        .section-title { font-size: 20px; margin-bottom: 20px; color: var(--accent); display: flex; align-items: center; gap: 10px; }
        .btn { background: var(--accent-dark); color: white; border: none; padding: 10px 20px; border-radius: 8px; font-weight: 600; font-size: 15px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; text-decoration: none; }
        .btn:hover { opacity: 0.9; }
        .btn-pdf { background: #d32f2f; }
        .btn-csv { background: #2e7d32; }

        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        th, td { padding: 12px 10px; text-align: left; border-bottom: 1px solid var(--border); }
        th { color: var(--text-secondary); font-weight: 600; }
        tr:last-child td { border-bottom: none; }
        select, input { width: 100%; padding: 8px 12px; border: 1px solid var(--border); border-radius: 6px; background: #142c45; color: var(--text); margin-top: 8px; }

        .tabs { display: flex; gap: 12px; margin: 24px 0; }
        .tab { padding: 8px 16px; border-radius: 6px; cursor: pointer; }
        .tab.active { background: var(--accent); color: #0c1a2d; font-weight: 600; }

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
            <li><a href="shipments.php"><i class="fas fa-truck"></i> Отгрузки</a></li>
            <li><a href="archive.php"><i class="fas fa-archive"></i> Архив</a></li>
            <li><a href="reports.php" class="active"><i class="fas fa-file-pdf"></i> Отчёты</a></li>
        </ul>
    </nav>

    <div class="container">
        <header>
            <h1>Отчёты и документация</h1>
            <div class="subtitle">Генерация паспортов качества и журналов в соответствии с СТБ 1575-2013</div>
        </header>

        <!-- Паспорт качества -->
        <div class="report-sections">
            <div class="section">
                <h2 class="section-title"><i class="fas fa-file-alt"></i> Паспорт качества</h2>
                <p>Создайте официальный паспорт качества для любой партии, готовой к реализации.</p>
                <div style="margin-top: 16px;">
                    <label for="batch_select">Выберите партию:</label>
                    <select id="batch_select">
                        <option value="">-- Выберите партию --</option>
                        <?php foreach ($good_batches as $b): ?>
                            <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['batch_number']) ?> — <?= htmlspecialchars($b['brand']) ?> (<?= date('d.m.Y', strtotime($b['bottling_datetime'])) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="margin-top: 20px;">
                    <button id="generatePassport" class="btn btn-pdf" disabled>
                        <i class="fas fa-print"></i> Распечатать паспорт (HTML)
                    </button>
                </div>
            </div>

            <!-- Журналы -->
            <div class="section">
                <h2 class="section-title"><i class="fas fa-clipboard-list"></i> Журналы</h2>
                <div class="tabs">
                    <div class="tab <?= $journal_type === 'production' ? 'active' : '' ?>" data-tab="production">Производство</div>
                    <div class="tab <?= $journal_type === 'rejection' ? 'active' : '' ?>" data-tab="rejection">Брак</div>
                    <div class="tab <?= $journal_type === 'stock' ? 'active' : '' ?>" data-tab="stock">Остатки</div>
                </div>
                <a href="reports.php?journal=<?= $journal_type ?>&export=csv" class="btn btn-csv" style="margin-top: 12px;">
                    <i class="fas fa-file-csv"></i> Экспорт в CSV
                </a>
                <?php if (!empty($journal_data)): ?>
                    <table style="margin-top: 16px;">
                        <?php if ($journal_type === 'production'): ?>
                            <thead>
                                <tr>
                                    <th>Партия</th>
                                    <th>Марка</th>
                                    <th>Тара</th>
                                    <th>Дата</th>
                                    <th>Статус</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($journal_data as $j): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($j['batch_number']) ?></td>
                                        <td><?= htmlspecialchars($j['brand']) ?></td>
                                        <td><?= $j['volume_l'] ?> л</td>
                                        <td><?= date('d.m.Y', strtotime($j['bottling_datetime'])) ?></td>
                                        <td><?= htmlspecialchars($j['status']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        <?php elseif ($journal_type === 'rejection'): ?>
                            <thead>
                                <tr>
                                    <th>Партия</th>
                                    <th>Марка</th>
                                    <th>Дата розлива</th>
                                    <th>Причина</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($journal_data as $j): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($j['batch_number']) ?></td>
                                        <td><?= htmlspecialchars($j['brand']) ?></td>
                                        <td><?= date('d.m.Y', strtotime($j['bottling_datetime'])) ?></td>
                                        <td><?= htmlspecialchars($j['reason']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        <?php elseif ($journal_type === 'stock'): ?>
                            <thead>
                                <tr>
                                    <th>Партия</th>
                                    <th>Марка</th>
                                    <th>Срок годности</th>
                                    <th>Остаток</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($journal_data as $j): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($j['batch_number']) ?></td>
                                        <td><?= htmlspecialchars($j['brand']) ?></td>
                                        <td><?= date('d.m.Y', strtotime($j['expiry_date'])) ?></td>
                                        <td><?= number_format($j['remaining_bottles'], 0, ' ', ' ') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        <?php endif; ?>
                    </table>
                <?php else: ?>
                    <p class="empty">Нет данных для отображения.</p>
                <?php endif; ?>
            </div>
        </div>

        <footer>
            Все документы соответствуют требованиям СТБ 1575-2013 и ТР ТС 021/2011.
        </footer>
    </div>

    <script>
        // Паспорт качества
        const batchSelect = document.getElementById('batch_select');
        const generateBtn = document.getElementById('generatePassport');

        batchSelect.addEventListener('change', () => {
            generateBtn.disabled = !batchSelect.value;
        });

        generateBtn.addEventListener('click', () => {
            if (batchSelect.value) {
                window.open(`reports.php?passport=html&batch_id=${batchSelect.value}`, '_blank');
            }
        });

        // Переключение журналов
        document.querySelectorAll('.tab').forEach(tab => {
            tab.addEventListener('click', () => {
                const type = tab.getAttribute('data-tab');
                window.location.href = `reports.php?journal=${type}`;
            });
        });
    </script>
</body>
</html>