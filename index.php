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

// === СТАТИСТИКА ===
$total_batches = $pdo->query("SELECT COUNT(*) FROM batches")->fetchColumn();
$ready_batches = $pdo->query("SELECT COUNT(*) FROM batches WHERE status = 'Годна к реализации'")->fetchColumn();
$total_shipments = $pdo->query("SELECT COUNT(*) FROM shipments")->fetchColumn();
$brake_count = $pdo->query("SELECT COUNT(*) FROM batches WHERE status = 'Брак'")->fetchColumn();
$total_sources = $pdo->query("SELECT COUNT(*) FROM water_sources")->fetchColumn();



// === ПАРТИИ, КОТОРЫЕ СКОРО ИСТЕКУТ (менее 30 дней до конца срока годности) ===
$expiring_batches = $pdo->query("
    SELECT batch_number, bottling_datetime,
           DATE_ADD(bottling_datetime, INTERVAL 12 MONTH) AS expiry_date,
           DATEDIFF(DATE_ADD(bottling_datetime, INTERVAL 12 MONTH), CURDATE()) AS days_left
    FROM batches
    WHERE status != 'Брак'
    HAVING days_left BETWEEN 0 AND 30
    ORDER BY days_left ASC
")->fetchAll();

// === БРАК: причины и анализ ===
$brake_reasons = $pdo->query("
    SELECT 
        'Микробиологический брак' AS reason,
        COUNT(*) AS count
    FROM treated_water_tests tw
    JOIN batches b ON tw.id = b.treated_test_id
    WHERE b.status = 'Брак' AND (
        tw.coliforms_detected = 1 OR 
        tw.thermotolerant_coliforms_detected = 1 OR 
        tw.pseudomonas_detected = 1
    )
    UNION ALL
    SELECT 
        'Химический брак' AS reason,
        COUNT(*) AS count
    FROM treated_water_tests tw
    JOIN batches b ON tw.id = b.treated_test_id
    WHERE b.status = 'Брак' AND (
        tw.nitrates_mg_l > 45 OR 
        tw.iron_mg_l > 0.3 OR 
        tw.ph < 6.5 OR tw.ph > 9.0
    )
    UNION ALL
    SELECT
        'Внешний вид' AS reason,
        COUNT(*) AS count
    FROM treated_water_tests tw
    JOIN batches b ON tw.id = b.treated_test_id
    WHERE b.status = 'Брак' AND (
        tw.transparency = 'Мутная' OR
        tw.color = 'Окрашена'
    )
")->fetchAll();



// === ПОСЛЕДНИЕ ОТГРУЗКИ ===
$recent_shipments = $pdo->query("
    SELECT s.waybill_number, c.name AS client, s.bottles_shipped, 
           s.shipment_date, b.batch_number
    FROM shipments s
    JOIN batches b ON s.batch_id = b.id
    JOIN clients c ON s.client_id = c.id
    ORDER BY s.shipment_date DESC
    LIMIT 5
")->fetchAll();

// === ОПРЕДЕЛЕНИЕ ГЛАВНОГО ПРЕДУПРЕЖДЕНИЯ ===
$alerts = [];

if (!empty($expiring_batches)) {
    $alerts[] = [
        'type' => 'warning',
        'title' => 'Скоро истечёт срок годности',
        'message' => 'У ' . count($expiring_batches) . ' партий осталось менее 30 дней до окончания срока годности.',
        'action_url' => 'archive.php',
        'action_label' => 'Просмотреть архив'
    ];
} elseif ($brake_count > 0) {
    $alerts[] = [
        'type' => 'warning',
        'title' => 'Обнаружен брак',
        'message' => 'Обнаружено ' . $brake_count . ' забракованных партий. Требуется анализ причин.',
        'action_url' => 'archive.php?status=Брак',
        'action_label' => 'Просмотреть брак'
    ];
} else {
    $alerts[] = [
        'type' => 'info',
        'title' => 'Производство в норме',
        'message' => 'Все технологические процессы соответствуют требованиям СТБ 1575-2013 и ТР ТС 021/2011. Микробиологические и химические показатели в пределах нормы. Оборудование функционирует штатно.',
        'action_url' => 'production.php',
        'action_label' => 'Начать производство'
    ];
}

$main_alert = $alerts[0];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AquaTrack — Главная</title>
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
            --shadow: 0 6px 20px rgba(0, 0, 0, 0.4);
        }
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', system-ui, sans-serif; }
        body { background: var(--bg); color: var(--text); line-height: 1.6; }
        .container { max-width: 1400px; margin: 0 auto; padding: 0 20px; }
        nav { background: var(--header-bg); padding: 14px 0; position: sticky; top: 0; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; }
        .logo { font-size: 26px; font-weight: 700; color: var(--accent); display: flex; align-items: center; gap: 12px; }
        .nav-links { display: flex; list-style: none; gap: 20px; }
        .nav-links a { color: var(--text-secondary); text-decoration: none; font-weight: 500; padding: 8px 16px; border-radius: 8px; }
        .nav-links a:hover, .nav-links a.active { background: rgba(79, 195, 247, 0.15); color: var(--accent); }

        header { text-align: center; padding: 30px 0 20px; }
        h1 { font-size: 32px; font-weight: 800; margin-bottom: 10px; background: linear-gradient(to right, #ffffff, var(--accent)); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .subtitle { color: var(--text-secondary); font-size: 18px; }

        /* Главный алерт */
        .alert {
            padding: 24px; border-radius: 16px; margin-bottom: 30px; display: flex; gap: 24px; align-items: flex-start; position: relative; overflow: hidden;
        }
        .alert::before { content: ''; position: absolute; top: 0; left: 0; bottom: 0; width: 4px; }
        .alert-success { background: rgba(129, 199, 132, 0.15); --color: var(--success); }
        .alert-warning { background: rgba(255, 179, 0, 0.15); --color: var(--warning); }
        .alert-danger { background: rgba(244, 67, 54, 0.15); --color: var(--danger); }
        .alert-info { background: rgba(79, 195, 247, 0.15); --color: var(--accent); }
        .alert::before { background: var(--color); }
        .alert-icon { font-size: 28px; color: var(--color); margin-top: 4px; }
        .alert-content { flex: 1; }
        .alert-title { font-size: 20px; font-weight: 700; margin-bottom: 8px; color: var(--color); }
        .alert-message { color: var(--text); margin-bottom: 16px; line-height: 1.6; }
        .btn { background: var(--accent-dark); color: white; border: none; padding: 10px 24px; border-radius: 8px; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 10px; font-size: 16px; transition: opacity 0.2s; }
        .btn:hover { opacity: 0.9; }

        /* Статистика */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: var(--card-bg); padding: 20px; border-radius: 12px; text-align: center; border: 1px solid var(--border); }
        .stat-value { font-size: 32px; font-weight: 700; color: var(--accent); margin: 8px 0; }
        .stat-label { color: var(--text-secondary); font-size: 14px; }

        /* Основные блоки */

        .section { background: var(--card-bg); border-radius: 16px; padding: 24px; margin-bottom: 24px; border: 1px solid var(--border); }
        .section-title { font-size: 20px; font-weight: 700; margin-bottom: 20px; color: var(--accent); display: flex; align-items: center; gap: 12px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px 10px; text-align: left; border-bottom: 1px solid var(--border); }
        th { color: var(--text-secondary); font-weight: 600; }
        tr:last-child td { border-bottom: none; }
        .status-tag { padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .status--good { background: rgba(129,199,132,0.2); color: var(--success); }
        .status--warning { background: rgba(255,179,0,0.2); color: var(--warning); }
        .status--danger { background: rgba(244,67,80,0.2); color: var(--danger); }
        .batch-link { color: var(--accent); text-decoration: none; font-weight: 600; }
        .batch-link:hover { text-decoration: underline; }
        .empty { text-align: center; color: var(--text-secondary); padding: 20px; font-style: italic; }

        /* Информационные блоки */
        .info-block { background: var(--card-bg); border-radius: 12px; padding: 16px; margin-bottom: 16px; border-left: 4px solid var(--accent); }
        .info-title { font-weight: 600; margin-bottom: 8px; color: var(--accent); }
        .info-content { color: var(--text); font-size: 15px; line-height: 1.5; }

        footer { text-align: center; color: var(--text-secondary); font-size: 15px; padding: 30px 0 20px; border-top: 1px solid var(--border); margin-top: 20px; }
    </style>
</head>
<body>
    <nav>
        <div class="logo"><i class="fas fa-droplet"></i> AquaTrack</div>
        <ul class="nav-links">
            <li><a href="index.php" class="active">Главная</a></li>
            <li><a href="production.php">Производство</a></li>
            <li><a href="shipments.php">Отгрузки</a></li>
            <li><a href="archive.php">Архив</a></li>
            <li><a href="reports.php">Отчёты</a></li>
            <li><a href="sources.php">Источники</a></li>
        </ul>
    </nav>

    <div class="container">
        <header>
            <h1>Оперативная панель управления производством</h1>
            <div class="subtitle">Республика Беларусь • Соответствие СТБ 1575-2013 и ТР ТС 021/2011</div>
        </header>

        <!-- Главное предупреждение -->
        <div class="alert alert-<?= $main_alert['type'] ?>">
            <div class="alert-icon">
                <i class="fas <?= 
                    $main_alert['type'] === 'success' ? 'fa-check-circle' : 
                    ($main_alert['type'] === 'danger' ? 'fa-exclamation-triangle' : 
                    ($main_alert['type'] === 'warning' ? 'fa-exclamation-circle' : 'fa-info-circle'))
                ?>"></i>
            </div>
            <div class="alert-content">
                <div class="alert-title"><?= htmlspecialchars($main_alert['title']) ?></div>
                <div class="alert-message"><?= htmlspecialchars($main_alert['message']) ?></div>
                <a href="<?= htmlspecialchars($main_alert['action_url']) ?>" class="btn">
                    <i class="fas fa-arrow-right"></i> <?= htmlspecialchars($main_alert['action_label']) ?>
                </a>
            </div>
        </div>



        <!-- Важная информация для операторов -->
        <div class="section">
            <div class="section-title"><i class="fas fa-star"></i> Требования качества и нормативы</div>
            
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 16px; margin-top: 15px;">
                <div class="info-block" style="border-left-color: var(--success);">
                    <div class="info-title"><i class="fas fa-vial"></i> Показатели качества по СТБ 1575-2013 и ТР ТС 021/2011</div>
                    <div class="info-content">
                        <strong>Микробиология (СТБ 1575-2013):</strong><br>
                        • Колиформы: не допускаются (ГОСТ 32598)<br>
                        • Термотолерантные колиформы: не допускаются (ГОСТ 32598)<br>
                        • Псевдомонады: не допускаются (ГОСТ 32599)<br><br>
                        
                        <strong>Химия (СТБ 1575-2013):</strong><br>
                        • Нитраты: ≤ 45 мг/л (ГОСТ 32596)<br>
                        • Железо: ≤ 0.3 мг/л (ГОСТ 32595)<br>
                        • pH: 6.5-9.0 (ГОСТ 32594)<br>
                        • Прозрачность: прозрачная (визуально)<br>
                        • Цветность: не окрашена (визуально)<br><br>

                        <strong>ТР ТС 021/2011:</strong><br>
                        • Обязательная прослеживаемость продукции<br>
                        • Архив — минимум 2 года после срока годности<br>
                        • Срок годности: 12 месяцев
                    </div>
                </div>
                
                <div class="info-block" style="border-left-color: var(--warning);">
                    <div class="info-title"><i class="fas fa-calendar-check"></i> Регламент проверок</div>
                    <div class="info-content">
                        • Ежесменный контроль оборудования<br>
                        • Еженедельные лабораторные анализы<br>
                        • Санитарное обслуживание: ежемесячно<br>
                        • Проверка источников: каждые 6 месяцев<br>
                        • Срок годности: 12 месяцев
                    </div>
                </div>
                
                <div class="info-block" style="border-left-color: var(--accent);">
                    <div class="info-title"><i class="fas fa-file-alt"></i> Необходимая документация</div>
                    <div class="info-content">
                        • Журнал входного контроля сырья<br>
                        • Протоколы лабораторных исследований<br>
                        • Журнал движения готовой продукции<br>
                        • Архив: 2 года после срока годности
                    </div>
                </div>
            </div>
        </div>

        

        <!-- Основной контент -->
        <!-- Все партии (готовые к отгрузке, требующие анализа, забракованные) -->
        <div class="section">
            <div class="section-title"><i class="fas fa-boxes"></i> Состояние партий</div>
            
            <?php 
            // Обновляем запрос, чтобы получить партии разных статусов
            $all_batches = $pdo->query("
                SELECT b.id, b.batch_number, b.remaining_bottles, b.bottling_datetime, b.status, wb.name AS brand,
                       DATE_ADD(b.bottling_datetime, INTERVAL 12 MONTH) AS expiry_date,
                       DATEDIFF(DATE_ADD(b.bottling_datetime, INTERVAL 12 MONTH), CURDATE()) AS days_to_expiry
                FROM batches b
                JOIN water_brands wb ON b.brand_id = wb.id
                WHERE b.status IN ('Годна к реализации', 'Ожидает анализа', 'Брак')
                ORDER BY b.bottling_datetime DESC
            ")->fetchAll();
            ?>
            
            <?php if ($all_batches): ?>
            <table>
                <thead>
                    <tr>
                        <th>Партия</th>
                        <th>Марка</th>
                        <th>Статус</th>
                        <th>Остаток</th>
                        <th>Дата розлива</th>
                        <th>Срок годности</th>
                        <th>Действие</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($all_batches as $b): 
                        $days_to_expiry = $b['days_to_expiry'];
                        $expiry_class = '';
                        if ($days_to_expiry <= 7) {
                            $expiry_class = 'status--danger';
                        } elseif ($days_to_expiry <= 30) {
                            $expiry_class = 'status--warning';
                        } else {
                            $expiry_class = 'status--good';
                        }
                        
                        $status_class = '';
                        $status_text = '';
                        switch($b['status']) {
                            case 'Годна к реализации':
                                $status_class = 'status--good';
                                $status_text = 'Годна к реализации';
                                break;
                            case 'Ожидает анализа':
                                $status_class = 'status--warning';
                                $status_text = 'Ожидает анализа';
                                break;
                            case 'Брак':
                                $status_class = 'status--danger';
                                $status_text = 'Брак';
                                break;
                            default:
                                $status_class = '';
                                $status_text = $b['status'];
                        }
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($b['batch_number']) ?></td>
                        <td><?= htmlspecialchars($b['brand']) ?></td>
                        <td><span class="status-tag <?= $status_class ?>"><?= $status_text ?></span></td>
                        <td><?= number_format($b['remaining_bottles'], 0, ' ', ' ') ?></td>
                        <td><?= date('d.m.Y', strtotime($b['bottling_datetime'])) ?></td>
                        <td><span class="status-tag <?= $expiry_class ?>"><?= date('d.m.Y', strtotime($b['expiry_date'])) ?> (<?= $days_to_expiry ?> дн.)</span></td>
                        <td>
                            <?php if ($b['status'] === 'Годна к реализации' && $b['remaining_bottles'] > 0): ?>
                                <a href="shipments.php?batch_id=<?= $b['id'] ?>" class="batch-link">Оформить отгрузку</a>
                            <?php elseif ($b['status'] === 'Ожидает анализа'): ?>
                                <a href="production.php?batch_id=<?= $b['id'] ?>" class="batch-link">Анализ партии</a>
                            <?php elseif ($b['status'] === 'Брак'): ?>
                                <a href="edit_batch.php?batch_id=<?= $b['id'] ?>" class="batch-link">Исправить брак</a>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <p class="empty">Нет партий для отображения.</p>
            <?php endif; ?>
        </div>

        <!-- Последние отгрузки -->
        <div class="section">
            <div class="section-title"><i class="fas fa-truck"></i> Последние отгрузки</div>
            <?php if ($recent_shipments): ?>
            <table>
                <thead>
                    <tr>
                        <th>Партия</th>
                        <th>Клиент</th>
                        <th>Бутылок</th>
                        <th>Дата</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_shipments as $s): ?>
                    <tr>
                        <td><?= htmlspecialchars($s['batch_number']) ?></td>
                        <td><?= htmlspecialchars($s['client']) ?></td>
                        <td><?= number_format($s['bottles_shipped'], 0, ' ', ' ') ?></td>
                        <td><?= date('d.m.Y', strtotime($s['shipment_date'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <p class="empty">Отгрузок пока нет.</p>
            <?php endif; ?>
        </div>

        <!-- Критическая информация -->
        <div class="section">
            <div class="section-title"><i class="fas fa-exclamation-triangle"></i> Критические вопросы и важные предупреждения</div>
                    
                    <?php
                    // Проверяем наличие критических ситуаций
                    $critical_issues = [];
                    
                    // Проверка брака
                    if (!empty($brake_reasons)) {
                        $critical_issues[] = 'brake';
                    }
                    
                    // Проверка истекающих сроков годности
                    if (!empty($expiring_batches)) {
                        $critical_issues[] = 'expiring';
                    }
                    
                    // Проверка источников с истекающим санитарным заключением
                    $sources_with_expiring_sanitary = $pdo->query("
                        SELECT id, name, sanitary_conclusion_number, sanitary_conclusion_valid_until,
                               DATEDIFF(sanitary_conclusion_valid_until, CURDATE()) AS days_left
                        FROM water_sources 
                        WHERE sanitary_conclusion_valid_until <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
                    ")->fetchAll();
                    
                    if (!empty($sources_with_expiring_sanitary)) {
                        $critical_issues[] = 'sources';
                    }
                    
                    // Проверка партий, ожидающих анализа
                    $pending_analysis_batches = $pdo->query("
                        SELECT b.id, b.batch_number, b.bottling_datetime, wb.name AS brand
                        FROM batches b
                        JOIN treated_water_tests tw ON b.treated_test_id = tw.id
                        JOIN water_brands wb ON b.brand_id = wb.id
                        WHERE b.status = 'Ожидает анализа'
                        ORDER BY b.bottling_datetime DESC
                    ")->fetchAll();
                    
                    if (!empty($pending_analysis_batches)) {
                        $critical_issues[] = 'pending_analysis';
                    }
                    
                    if (!empty($critical_issues)):
                    ?>
                        <?php if (!empty($pending_analysis_batches)): ?>
                        <div class="info-block" style="border-left-color: var(--warning);">
                            <div class="info-title">Партии, требующие лабораторного анализа</div>
                            <div class="info-content">
                                <?php foreach ($pending_analysis_batches as $b): ?>
                                <div>
                                    <a href="production.php?batch_id=<?= $b['id'] ?>" class="batch-link">
                                        Партия <?= htmlspecialchars($b['batch_number']) ?> (<?= htmlspecialchars($b['brand']) ?>)
                                    </a> — от  <?= date('d.m.Y', strtotime($b['bottling_datetime'])) ?>
                                </div>
                                <?php endforeach; ?>
                                <div style="margin-top: 8px;">
                                    <a href="production.php" class="btn" style="padding: 6px 12px; font-size: 14px;">
                                        <i class="fas fa-vial"></i> Перейти к анализу
                                    </a>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($brake_reasons)): ?>
                        <div class="info-block" style="border-left-color: var(--danger);">
                            <div class="info-title">Анализ брака</div>
                            <?php foreach ($brake_reasons as $r): ?>
                                <div class="info-content"><strong><?= htmlspecialchars($r['reason']) ?>:</strong> <?= $r['count'] ?> случаев</div>
                            <?php endforeach; ?>
                            
                            <!-- Дополнительная информация по последнему браку -->
                            <?php
                            $total_rejected_batches = $pdo->query("SELECT COUNT(*) FROM batches WHERE status = 'Брак'")->fetchColumn();
                            $last_rejected_batch = $pdo->query("
                                SELECT b.batch_number, b.created_at, tw.coliforms_detected, tw.thermotolerant_coliforms_detected, 
                                       tw.pseudomonas_detected, tw.nitrates_mg_l, tw.iron_mg_l, tw.ph, tw.transparency, tw.color
                                FROM batches b
                                JOIN treated_water_tests tw ON b.treated_test_id = tw.id
                                WHERE b.status = 'Брак'
                                ORDER BY b.created_at DESC
                                LIMIT 1
                            ")->fetch();
                            ?>
                            <div class="info-content">
                                <strong>Всего забраковано партий:</strong> <?= $total_rejected_batches ?><br>
                                <?php if ($last_rejected_batch): ?>
                                <strong>Последняя забракованная партия:</strong> <?= htmlspecialchars($last_rejected_batch['batch_number']) ?> (<?= date('d.m.Y', strtotime($last_rejected_batch['created_at'])) ?>)<br>
                                <strong>Основные причины:</strong><br>
                                <?php 
                                $issues = [];
                                if ($last_rejected_batch['coliforms_detected']) $issues[] = 'Колиформы';
                                if ($last_rejected_batch['thermotolerant_coliforms_detected']) $issues[] = 'Термотолерантные колиформы';
                                if ($last_rejected_batch['pseudomonas_detected']) $issues[] = 'Псевдомонады';
                                if ($last_rejected_batch['nitrates_mg_l'] > 45) $issues[] = 'Превышение нитратов (' . $last_rejected_batch['nitrates_mg_l'] . ' мг/л)';
                                if ($last_rejected_batch['iron_mg_l'] > 0.3) $issues[] = 'Превышение железа (' . $last_rejected_batch['iron_mg_l'] . ' мг/л)';
                                if ($last_rejected_batch['ph'] < 6.5 || $last_rejected_batch['ph'] > 9.0) $issues[] = 'pH вне диапазона (' . $last_rejected_batch['ph'] . ')';
                                if ($last_rejected_batch['transparency'] === 'Мутная') $issues[] = 'Мутность';
                                if ($last_rejected_batch['color'] === 'Окрашена') $issues[] = 'Цветность';
                                
                                foreach ($issues as $issue) {
                                    echo '• ' . $issue . '<br>';
                                }
                                ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($expiring_batches)): ?>
                        <div class="info-block" style="border-left-color: var(--warning);">
                            <div class="info-title">Партии с истекающим сроком годности</div>
                            <div class="info-content">
                                <?php foreach ($expiring_batches as $b): ?>
                                <div>Партия <?= htmlspecialchars($b['batch_number']) ?> — осталось <?= $b['days_left'] ?> дн.</div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($sources_with_expiring_sanitary)): ?>
                        <?php foreach ($sources_with_expiring_sanitary as $src): 
                            $days_left = $src['days_left'];
                            $is_expired = $days_left < 0;
                            $days_text = abs($days_left);
                            if ($days_left == 0) {
                                $days_text = "сегодня";
                                $date_text = "истекает сегодня";
                            } elseif ($is_expired) {
                                $date_text = "истек " . $days_text . " дн. назад";
                            } else {
                                $date_text = "истекает через " . $days_text . " дн.";
                            }
                            
                            $border_color = $is_expired ? 'var(--danger)' : ($days_left <= 7 ? 'var(--warning)' : 'var(--accent)');
                        ?>
                        <div class="info-block" style="border-left-color: <?= $border_color ?>;">
                            <div class="info-title"><?= htmlspecialchars($src['name']) ?></div>
                            <div class="info-content">
                                Санзаключение №<?= htmlspecialchars($src['sanitary_conclusion_number']) ?> 
                                (до <?= date('d.m.Y', strtotime($src['sanitary_conclusion_valid_until'])) ?>)<br>
                                <span style="<?= $is_expired ? 'color: var(--danger); font-weight: bold;' : '' ?>">
                                    <?= ucfirst($date_text) ?>
                                </span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                        
                    <?php else: ?>
                        <div class="info-block" style="border-left-color: var(--success);">
                            <div class="info-content" style="color: var(--success);">
                                <i class="fas fa-check-circle"></i> Критических ситуаций не обнаружено. Все процессы соответствуют требованиям качества.
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Предупреждение о санитарных заключениях -->
                <div class="section">
                    <div class="section-title"><i class="fas fa-exclamation-circle"></i> Важные предупреждения</div>
                    <div class="info-block" style="border-left-color: var(--warning);">
                        <div class="info-title">Санитарные заключения</div>
                        <div class="info-content">
                            <p>Обратите внимание: санитарные заключения на источники воды требуют регулярного продления!</p>
                            <?php
                            $expiring_conclusions = $pdo->query("
                                SELECT name, sanitary_conclusion_number, 
                                       DATEDIFF(sanitary_conclusion_valid_until, CURDATE()) AS days_left
                                FROM water_sources 
                                WHERE sanitary_conclusion_valid_until <= DATE_ADD(CURDATE(), INTERVAL 60 DAY)
                            ")->fetchAll();
                            
                            if (!empty($expiring_conclusions)):
                            ?>
                                <p><strong>Источники с истекающими заключениями:</strong></p>
                                <?php foreach ($expiring_conclusions as $src): ?>
                                <div>
                                    <?= htmlspecialchars($src['name']) ?>: 
                                    <?php if ($src['days_left'] < 0): ?>
                                        <span style="color: var(--danger);">истекло (<?= abs($src['days_left']) ?> дн. назад)</span>
                                    <?php else: ?>
                                        истекает через <?= $src['days_left'] ?> дн.
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p>Все санитарные заключения действительны.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                

                

                

                        <footer>
            AquaTrack — система контроля производства питьевой бутилированной воды. Все данные сохраняются в архиве.
        </footer>
    </div>
</body>
</html>