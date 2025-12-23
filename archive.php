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

// === СТАТИСТИКА ДЛЯ АРХИВА ===
$total_batches = $pdo->query("SELECT COUNT(*) FROM batches")->fetchColumn();
$total_rejected = $pdo->query("SELECT COUNT(*) FROM batches WHERE status = 'Брак'")->fetchColumn();
$total_shipped = $pdo->query("SELECT COUNT(*) FROM shipments")->fetchColumn();
$total_clients = $pdo->query("SELECT COUNT(*) FROM clients")->fetchColumn();
$branded_batches = $pdo->query("SELECT wb.name, COUNT(*) as cnt FROM batches b JOIN water_brands wb ON b.brand_id = wb.id GROUP BY wb.name ORDER BY cnt DESC")->fetchAll();

// === ЗАГРУЗКА ПАРТИЙ ДЛЯ СПИСКА ===
$batches = $pdo->query("
    SELECT 
        b.id, b.batch_number, b.bottling_datetime, b.status, b.remaining_bottles,
        wb.name AS brand, bt.volume_l, bt.material,
        ws.name AS source_name
    FROM batches b
    JOIN water_brands wb ON b.brand_id = wb.id
    JOIN bottle_types bt ON b.bottle_type_id = bt.id
    JOIN treated_water_tests tw ON b.treated_test_id = tw.id
    JOIN water_treatments t ON tw.treatment_id = t.id
    JOIN raw_water_tests r ON t.raw_test_id = r.id
    JOIN water_sources ws ON r.source_id = ws.id
    ORDER BY b.bottling_datetime DESC
    LIMIT 100
")->fetchAll();

// === ЗАГРУЗКА ПОЛНЫХ ДАННЫХ ДЛЯ МОДАЛЬНОГО ОКНА (если запрошен ID) ===
$detail = null;
if (isset($_GET['batch_id'])) {
    $batch_id = (int)$_GET['batch_id'];
    $stmt = $pdo->prepare("
        SELECT 
            b.batch_number, b.bottling_datetime, b.status, b.remaining_bottles, b.total_bottles,
            wb.name AS brand, bt.volume_l, bt.material,
            ws.name AS source_name, ws.sanitary_conclusion_number, ws.sanitary_conclusion_valid_until,
            r.sampled_at AS raw_sampled_at, r.sampled_by AS raw_sampled_by,
            r.odor_rating, r.taste_rating, r.color_degrees, r.turbidity_emf,
            r.ph AS raw_ph, r.nitrates_mg_l AS raw_nitrates, r.coliforms_100ml,
            t.started_at AS treatment_started, t.finished_at AS treatment_finished, t.volume_treated_l, t.treatment_type, t.operator AS treatment_operator,
            tw.tested_at AS analysis_tested_at, tw.tested_by AS analysis_tested_by,
            tw.odor AS analysis_odor, tw.taste AS analysis_taste, tw.transparency, tw.color AS analysis_color,
            tw.ph AS analysis_ph, tw.nitrates_mg_l AS analysis_nitrates,
            tw.coliforms_detected, tw.is_compliant,
            s.waybill_number, s.bottles_shipped, s.shipment_date, s.shipped_by, c.name AS client_name
        FROM batches b
        JOIN water_brands wb ON b.brand_id = wb.id
        JOIN bottle_types bt ON b.bottle_type_id = bt.id
        JOIN treated_water_tests tw ON b.treated_test_id = tw.id
        JOIN water_treatments t ON tw.treatment_id = t.id
        JOIN raw_water_tests r ON t.raw_test_id = r.id
        JOIN water_sources ws ON r.source_id = ws.id
        LEFT JOIN shipments s ON b.id = s.batch_id
        LEFT JOIN clients c ON s.client_id = c.id
        WHERE b.id = ?
    ");
    $stmt->execute([$batch_id]);
    $detail = $stmt->fetch();
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Архив | AquaTrack</title>
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
            --warning: #ffb300;
            --danger: #f44336;
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
        
        .stat-value { font-size: 28px; font-weight: 700; color: var(--accent); margin: 8px 0; }
        .stat-label { color: var(--text-secondary); font-size: 14px; }
        .stat-trend { color: var(--text-secondary); font-size: 12px; display: flex; align-items: center; gap: 5px; margin-top: 4px; }

        /* Список партий */
        .archive-list {
            background: var(--card-bg); border-radius: 16px; overflow: hidden; border: 1px solid var(--border);
            margin-bottom: 24px;
        }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 14px 12px; text-align: left; border-bottom: 1px solid var(--border); }
        th { color: var(--text-secondary); font-weight: 600; background: rgba(0,0,0,0.2); }
        tr:last-child td { border-bottom: none; }
        .batch-link { color: var(--accent); text-decoration: none; font-weight: 600; }
        .batch-link:hover { text-decoration: underline; }
        .status { padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .status--good { background: rgba(129,199,132,0.2); color: var(--success); }
        .status--partial { background: rgba(255,179,0,0.2); color: var(--warning); }
        .status--done { background: rgba(129,199,132,0.2); color: var(--success); }
        .status--brake { background: rgba(244,67,80,0.2); color: var(--danger); }

        /* Модальное окно */
        .modal {
            display: none;
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.7);
            z-index: 1000;
            overflow-y: auto;
        }
        .modal-content {
            background: var(--card-bg); margin: 40px auto; padding: 30px; max-width: 900px; border-radius: 16px; border: 1px solid var(--border);
        }
        .modal-header {
            display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;
            padding-bottom: 16px; border-bottom: 1px solid var(--border);
        }
        .close-modal { background: none; border: none; color: var(--text); font-size: 24px; cursor: pointer; }
        .section { margin-bottom: 24px; }
        .section-title { font-size: 18px; font-weight: 700; color: var(--accent); margin-bottom: 12px; display: flex; align-items: center; gap: 10px; }
        .detail-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 8px; }
        .detail-label { color: var(--text-secondary); font-weight: 600; }
        .empty { text-align: center; color: var(--text-secondary); padding: 30px; font-style: italic; }

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
            <li><a href="archive.php" class="active"><i class="fas fa-archive"></i> Архив</a></li>
            <li><a href="reports.php"><i class="fas fa-file-pdf"></i> Отчёты</a></li>
        </ul>
    </nav>

    <div class="container">
        <header>
            <h1>Архив производства</h1>
            <div class="subtitle">Все данные с момента запуска системы. Автоматически обновляется при каждом действии.</div>
        </header>

        <?php
// === НОВАЯ СТАТИСТИКА ДЛЯ АРХИВА ===
// Вычисление новых метрик
$total_produced_bottles = $pdo->query("SELECT COALESCE(SUM(total_bottles), 0) FROM batches")->fetchColumn();
$total_shipped_bottles = $pdo->query("SELECT COALESCE(SUM(bottles_shipped), 0) FROM shipments")->fetchColumn();
$top_brand = $pdo->query("
    SELECT wb.name, COUNT(*) as cnt 
    FROM batches b 
    JOIN water_brands wb ON b.brand_id = wb.id 
    GROUP BY wb.name 
    ORDER BY cnt DESC 
    LIMIT 1
")->fetch();
$avg_production_time = $pdo->query("
    SELECT AVG(TIMESTAMPDIFF(HOUR, t.started_at, t.finished_at)) as avg_hours
    FROM water_treatments t
")->fetchColumn();
$compliance_rate = $pdo->query("
    SELECT 
        ROUND(COALESCE((SUM(CASE WHEN tw.is_compliant = 1 THEN 1 ELSE 0 END) * 100.0 / COUNT(*)), 0), 2) as rate
    FROM treated_water_tests tw
    JOIN batches b ON tw.id = b.treated_test_id
")->fetchColumn();
$recent_activity = $pdo->query("
    SELECT COUNT(*) 
    FROM batches 
    WHERE bottling_datetime >= DATE_SUB(NOW(), INTERVAL 7 DAY)
")->fetchColumn();

// Форматирование значений
$top_brand_name = $top_brand ? htmlspecialchars($top_brand['name']) : 'Нет данных';
$top_brand_count = $top_brand ? $top_brand['cnt'] : 0;
$avg_production_time_formatted = $avg_production_time ? round($avg_production_time, 1).' ч' : 'N/A';

// Получаем количество партий по статусам
$good_batches = $pdo->query("SELECT COUNT(*) FROM batches WHERE status != 'Брак'")->fetchColumn();
$bad_batches = $total_rejected;
$shipped_batches = $pdo->query("SELECT COUNT(*) FROM batches WHERE status = 'Полностью реализована'")->fetchColumn();
$partial_batches = $pdo->query("SELECT COUNT(*) FROM batches WHERE status = 'Частично отгружена'")->fetchColumn();
?>

        <!-- Новая информация в начале страницы -->
        <div class="summary-container" style="margin-bottom: 20px;">
            <div class="summary-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px; margin-bottom: 16px;">
                <div class="summary-card" style="background: var(--card-bg); padding: 16px; border-radius: 12px; border: 1px solid var(--border); box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
                    <h3 style="color: var(--accent); margin-bottom: 10px; display: flex; align-items: center; gap: 8px;">
                        <i class="fas fa-boxes"></i> Производство
                    </h3>
                    <div style="font-size: 28px; font-weight: 700; color: var(--accent); margin: 8px 0;"><?= number_format($total_batches, 0, ' ', ' ') ?></div>
                    <div style="color: var(--text-secondary); font-size: 14px;">Всего партий произведено</div>
                    <div style="margin-top: 8px; display: flex; justify-content: space-between; font-size: 13px;">
                        <span style="color: var(--success);"><i class="fas fa-check-circle"></i> Годных: <?= number_format($good_batches, 0, ' ', ' ') ?></span>
                        <span style="color: var(--danger);"><i class="fas fa-times-circle"></i> Брак: <?= number_format($bad_batches, 0, ' ', ' ') ?></span>
                    </div>
                </div>
                
                <div class="summary-card" style="background: var(--card-bg); padding: 16px; border-radius: 12px; border: 1px solid var(--border); box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
                    <h3 style="color: var(--accent); margin-bottom: 10px; display: flex; align-items: center; gap: 8px;">
                        <i class="fas fa-truck-moving"></i> Реализация
                    </h3>
                    <div style="font-size: 28px; font-weight: 700; color: var(--accent); margin: 8px 0;"><?= number_format($total_shipped_bottles, 0, ' ', ' ') ?></div>
                    <div style="color: var(--text-secondary); font-size: 14px;">Всего бутылок отгружено</div>
                    <div style="margin-top: 8px; display: flex; justify-content: space-between; font-size: 13px;">
                        <span style="color: var(--success);"><i class="fas fa-check"></i> Реализовано: <?= number_format($shipped_batches, 0, ' ', ' ') ?></span>
                        <span style="color: var(--warning);"><i class="fas fa-pause"></i> В наличии: <?= number_format($partial_batches, 0, ' ', ' ') ?></span>
                    </div>
                </div>
                
                <div class="summary-card" style="background: var(--card-bg); padding: 16px; border-radius: 12px; border: 1px solid var(--border); box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
                    <h3 style="color: var(--accent); margin-bottom: 10px; display: flex; align-items: center; gap: 8px;">
                        <i class="fas fa-chart-line"></i> Качество
                    </h3>
                    <div style="font-size: 28px; font-weight: 700; color: var(--accent); margin: 8px 0;"><?= $compliance_rate ?>%</div>
                    <div style="color: var(--text-secondary); font-size: 14px;">Соответствие стандартам</div>
                    <div style="margin-top: 8px; font-size: 13px; color: var(--text-secondary);">
                        <i class="fas fa-bottle-water"></i> Лидер: <?= $top_brand_name ?> (<?= $top_brand_count ?> партий)
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Разделитель -->
        <div style="height: 16px;"></div>

        <h2 style="color: var(--accent); margin-bottom: 16px; display: flex; align-items: center; gap: 10px;">
            <i class="fas fa-clipboard-list"></i> Подробный архив
        </h2>

        <!-- Список партий -->
        <div class="archive-list">
            <table>
                <thead>
                    <tr>
                        <th>Партия</th>
                        <th>Марка / Тара</th>
                        <th>Источник</th>
                        <th>Дата розлива</th>
                        <th>Статус</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($batches): ?>
                        <?php foreach ($batches as $b): ?>
                            <tr>
                                <td><?= htmlspecialchars($b['batch_number']) ?></td>
                                <td><?= htmlspecialchars($b['brand']) ?><br><small><?= $b['volume_l'] ?> л (<?= htmlspecialchars($b['material']) ?>)</small></td>
                                <td><?= htmlspecialchars($b['source_name']) ?></td>
                                <td><?= date('d.m.Y', strtotime($b['bottling_datetime'])) ?></td>
                                <td>
                                    <span class="status status--<?= 
                                        $b['status'] === 'Полностью реализована' ? 'done' : 
                                        ($b['status'] === 'Частично отгружена' ? 'partial' : 
                                        ($b['status'] === 'Брак' ? 'brake' : 'good'))
                                    ?>"><?= htmlspecialchars($b['status']) ?></span>
                                </td>
                                <td>
                                    <a href="#" class="batch-link" data-id="<?= $b['id'] ?>">Просмотреть</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="6" class="empty">Архив пуст. Начните производственный цикл.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <footer style="margin-top: 40px;">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 20px;">
                <div>
                    <h4 style="color: var(--accent); margin-bottom: 10px; display: flex; align-items: center; gap: 8px;">
                        <i class="fas fa-info-circle"></i> Информация
                    </h4>
                    <p style="color: var(--text-secondary); font-size: 14px;">Все данные хранятся автоматически с момента регистрации. Соответствует ТР ТС 021/2011.</p>
                </div>
                <div>
                    <h4 style="color: var(--accent); margin-bottom: 10px; display: flex; align-items: center; gap: 8px;">
                        <i class="fas fa-shield-alt"></i> Безопасность
                    </h4>
                    <p style="color: var(--text-secondary); font-size: 14px;">Данные защищены и доступны только авторизованным пользователям.</p>
                </div>
                <div>
                    <h4 style="color: var(--accent); margin-bottom: 10px; display: flex; align-items: center; gap: 8px;">
                        <i class="fas fa-sync-alt"></i> Актуальность
                    </h4>
                    <p style="color: var(--text-secondary); font-size: 14px;">Информация обновляется в реальном времени при каждом действии.</p>
                </div>
            </div>
            <div style="border-top: 1px solid var(--border); padding-top: 15px; text-align: center; color: var(--text-secondary);">
                AquaTrack &copy; <?= date('Y') ?> | Система контроля качества бутилированной воды
            </div>
        </footer>
    </div>

    <!-- Модальное окно деталей -->
    <div id="detailModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="modalTitle">Детали партии</h2>
                <button class="close-modal">&times;</button>
            </div>
            <div id="modalContent">
                <!-- Загрузится через AJAX -->
                <p style="text-align:center; color:var(--text-secondary);">Загрузка...</p>
            </div>
        </div>
    </div>

    <script>
        document.querySelectorAll('.batch-link').forEach(link => {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                const batchId = this.getAttribute('data-id');
                const modal = document.getElementById('detailModal');
                const content = document.getElementById('modalContent');
                
                // Запрос данных через AJAX
                fetch(`archive.php?batch_id=${batchId}`)
                    .then(response => response.text())
                    .then(html => {
                        // Извлекаем только блок с данными из ответа
                        const parser = new DOMParser();
                        const doc = parser.parseFromString(html, 'text/html');
                        const detailBlock = doc.querySelector('#detailBlock');
                        if (detailBlock) {
                            content.innerHTML = detailBlock.innerHTML;
                            document.getElementById('modalTitle').textContent = 'Партия ' + detailBlock.querySelector('[data-field="batch_number"]').textContent;
                        } else {
                            content.innerHTML = '<p style="color:var(--danger);">Ошибка загрузки данных.</p>';
                        }
                        modal.style.display = 'block';
                    })
                    .catch(() => {
                        content.innerHTML = '<p style="color:var(--danger);">Ошибка сети.</p>';
                        modal.style.display = 'block';
                    });
            });
        });

        // Закрытие модального окна
        document.querySelector('.close-modal').addEventListener('click', () => {
            document.getElementById('detailModal').style.display = 'none';
        });

        window.addEventListener('click', (e) => {
            if (e.target === document.getElementById('detailModal')) {
                document.getElementById('detailModal').style.display = 'none';
            }
        });
    </script>

    <?php if ($detail): ?>
        <div id="detailBlock" style="display:none;">
            <div class="section">
                <h3 class="section-title"><i class="fas fa-box"></i> Партия продукции</h3>
                <div class="detail-row">
                    <div><span class="detail-label">Номер партии:</span> <span data-field="batch_number"><?= htmlspecialchars($detail['batch_number']) ?></span></div>
                    <div><span class="detail-label">Дата розлива:</span> <?= date('d.m.Y H:i', strtotime($detail['bottling_datetime'])) ?></div>
                </div>
                <div class="detail-row">
                    <div><span class="detail-label">Марка:</span> <?= htmlspecialchars($detail['brand']) ?></div>
                    <div><span class="detail-label">Тара:</span> <?= $detail['volume_l'] ?> л (<?= htmlspecialchars($detail['material']) ?>)</div>
                </div>
                <div class="detail-row">
                    <div><span class="detail-label">Статус:</span> <span class="status status--<?= 
                        $detail['status'] === 'Полностью реализована' ? 'done' : 
                        ($detail['status'] === 'Частично отгружена' ? 'partial' : 
                        ($detail['status'] === 'Брак' ? 'brake' : 'good'))
                    ?>"><?= htmlspecialchars($detail['status']) ?></span></div>
                    <div><span class="detail-label">Произведено:</span> <?= number_format($detail['total_bottles'], 0, ' ', ' ') ?> бут.</div>
                </div>
                <div><span class="detail-label">Остаток:</span> <?= number_format($detail['remaining_bottles'], 0, ' ', ' ') ?> бут.</div>
            </div>

            <div class="section">
                <h3 class="section-title"><i class="fas fa-water"></i> Источник воды</h3>
                <div class="detail-row">
                    <div><span class="detail-label">Название:</span> <?= htmlspecialchars($detail['source_name']) ?></div>
                    <div><span class="detail-label">Санзаключение:</span> №<?= htmlspecialchars($detail['sanitary_conclusion_number']) ?> (до <?= date('d.m.Y', strtotime($detail['sanitary_conclusion_valid_until'])) ?>)</div>
                </div>
            </div>

            <div class="section">
                <h3 class="section-title"><i class="fas fa-vial"></i> Предварительный анализ</h3>
                <div class="detail-row">
                    <div><span class="detail-label">Дата пробы:</span> <?= date('d.m.Y H:i', strtotime($detail['raw_sampled_at'])) ?></div>
                    <div><span class="detail-label">Отобрал:</span> <?= htmlspecialchars($detail['raw_sampled_by']) ?></div>
                </div>
                <div class="detail-row">
                    <div><span class="detail-label">pH:</span> <?= $detail['raw_ph'] ?></div>
                    <div><span class="detail-label">Нитраты:</span> <?= $detail['raw_nitrates'] ?> мг/л</div>
                </div>
                <div><span class="detail-label">Микробиология:</span> 
                    <?= ($detail['coliforms_100ml'] ? 'Колиформы обнаружены' : 'Без патогенов') ?>
                </div>
            </div>

            <div class="section">
                <h3 class="section-title"><i class="fas fa-filter"></i> Очистка</h3>
                <div class="detail-row">
                    <div><span class="detail-label">Начало:</span> <?= date('d.m.Y H:i', strtotime($detail['treatment_started'])) ?></div>
                    <div><span class="detail-label">Окончание:</span> <?= date('d.m.Y H:i', strtotime($detail['treatment_finished'])) ?></div>
                </div>
                <div><span class="detail-label">Объём:</span> <?= $detail['volume_treated_l'] ?> л</div>
                <div><span class="detail-label">Методы:</span> <?= implode(', ', json_decode($detail['treatment_type'])) ?></div>
                <div><span class="detail-label">Оператор:</span> <?= htmlspecialchars($detail['treatment_operator']) ?></div>
            </div>

            <div class="section">
                <h3 class="section-title"><i class="fas fa-flask"></i> Лабораторный контроль</h3>
                <div class="detail-row">
                    <div><span class="detail-label">Дата анализа:</span> <?= date('d.m.Y H:i', strtotime($detail['analysis_tested_at'])) ?></div>
                    <div><span class="detail-label">Лаборант:</span> <?= htmlspecialchars($detail['analysis_tested_by']) ?></div>
                </div>
                <div class="detail-row">
                    <div><span class="detail-label">pH:</span> <?= $detail['analysis_ph'] ?></div>
                    <div><span class="detail-label">Нитраты:</span> <?= $detail['analysis_nitrates'] ?> мг/л</div>
                </div>
                <div class="detail-row">
                    <div><span class="detail-label">Запах:</span> <?= htmlspecialchars($detail['analysis_odor']) ?></div>
                    <div><span class="detail-label">Привкус:</span> <?= htmlspecialchars($detail['analysis_taste']) ?></div>
                </div>
                <div class="detail-row">
                    <div><span class="detail-label">Прозрачность:</span> <?= htmlspecialchars($detail['transparency']) ?></div>
                    <div><span class="detail-label">Цвет:</span> <?= htmlspecialchars($detail['analysis_color']) ?></div>
                </div>
                <div><span class="detail-label">Микробиология:</span> 
                    <?= ($detail['coliforms_detected'] ? '<span style="color:var(--danger);">Обнаружены патогены</span>' : '<span style="color:var(--success);">Соответствует нормам</span>') ?>
                </div>
                <div><span class="detail-label">Соответствие СТБ 1575-2013:</span> 
                    <?= $detail['is_compliant'] ? '<span style="color:var(--success);">Да</span>' : '<span style="color:var(--danger);">Нет</span>' ?>
                </div>
            </div>

            <?php if ($detail['waybill_number']): ?>
            <div class="section">
                <h3 class="section-title"><i class="fas fa-truck"></i> Отгрузка</h3>
                <div class="detail-row">
                    <div><span class="detail-label">Клиент:</span> <?= htmlspecialchars($detail['client_name']) ?></div>
                    <div><span class="detail-label">Дата:</span> <?= date('d.m.Y', strtotime($detail['shipment_date'])) ?></div>
                </div>
                <div class="detail-row">
                    <div><span class="detail-label">ТТН:</span> <?= htmlspecialchars($detail['waybill_number']) ?></div>
                    <div><span class="detail-label">Количество:</span> <?= number_format($detail['bottles_shipped'], 0, ' ', ' ') ?> бут.</div>
                </div>
                <div><span class="detail-label">Ответственный:</span> <?= htmlspecialchars($detail['shipped_by']) ?></div>
            </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</body>
</html>