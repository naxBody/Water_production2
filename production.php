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

// === ЗАГРУЗКА СПРАВОЧНИКОВ ===
$sources = $pdo->query("SELECT id, name FROM water_sources ORDER BY name")->fetchAll();
$brands = $pdo->query("SELECT id, name FROM water_brands ORDER BY name")->fetchAll();
$bottle_types = $pdo->query("SELECT id, volume_l, material FROM bottle_types ORDER BY volume_l")->fetchAll();
$clients = $pdo->query("SELECT id, name FROM clients ORDER BY name")->fetchAll();
$lines = $pdo->query("SELECT id, name FROM production_lines ORDER BY name")->fetchAll();
$operators = $pdo->query("SELECT id, full_name, role FROM operators ORDER BY full_name")->fetchAll();

// === ГРУППИРОВКА ОПЕРАТОРОВ ПО РОЛЯМ ===
$operators_by_role = [];
foreach ($operators as $op) {
    if (!isset($operators_by_role[$op['role']])) {
        $operators_by_role[$op['role']] = [];
    }
    $operators_by_role[$op['role']][] = $op;
}

// === ОПРЕДЕЛЕНИЕ ТЕКУЩЕГО ЭТАПА ===
session_start(); // Enable sessions to maintain context across requests

// Initialize context from session if it exists
$context = $_SESSION['production_context'] ?? [];
$current_step = $_SESSION['current_step'] ?? 1;

// === АВТОМАТИЧЕСКОЕ ЗАПОЛНЕНИЕ ПРЕДВАРИТЕЛЬНЫХ ЗНАЧЕНИЙ ===
// Получим последнюю запись из базы для автозаполнения
$last_raw_test = $pdo->query("
    SELECT r.*, ws.name AS source_name, o.full_name AS sampled_by_name
    FROM raw_water_tests r
    JOIN water_sources ws ON r.source_id = ws.id
    LEFT JOIN operators o ON r.sampled_by = o.full_name
    ORDER BY r.sampled_at DESC LIMIT 1
")->fetch();

$last_treatment = $pdo->query("
    SELECT t.*, ws.name AS source_name, o.full_name AS operator_name
    FROM water_treatments t
    JOIN raw_water_tests r ON t.raw_test_id = r.id
    JOIN water_sources ws ON r.source_id = ws.id
    LEFT JOIN operators o ON t.operator = o.full_name
    ORDER BY t.started_at DESC LIMIT 1
")->fetch();

$last_analysis = $pdo->query("
    SELECT tw.*, ws.name AS source_name, o.full_name AS tested_by_name
    FROM treated_water_tests tw
    JOIN water_treatments t ON tw.treatment_id = t.id
    JOIN raw_water_tests r ON t.raw_test_id = r.id
    JOIN water_sources ws ON r.source_id = ws.id
    LEFT JOIN operators o ON tw.tested_by = o.full_name
    ORDER BY tw.tested_at DESC LIMIT 1
")->fetch();

$last_batch = $pdo->query("
    SELECT b.*, wb.name AS brand_name, bt.volume_l, bt.material, o.full_name AS operator_name
    FROM batches b
    JOIN water_brands wb ON b.brand_id = wb.id
    JOIN bottle_types bt ON b.bottle_type_id = bt.id
    LEFT JOIN operators o ON b.operator_name = o.full_name
    ORDER BY b.bottling_datetime DESC LIMIT 1
")->fetch();

// === ОБРАБОТКА ФОРМ ===
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($_POST['step'] == 1) {
            $source_id = (int)$_POST['source_id'];
            if (!$source_id) throw new Exception('Выберите источник.');
            $operator_id = (int)$_POST['sampled_by']; // ID оператора
            $operator = $pdo->query("SELECT full_name FROM operators WHERE id = $operator_id AND role = 'operator'")->fetchColumn();
            if (!$operator) throw new Exception('Выберите корректного оператора.');

            // Проверка санзаключения
            $valid_until = $pdo->prepare("SELECT sanitary_conclusion_valid_until FROM water_sources WHERE id = ?");
            $valid_until->execute([$source_id]);
            if ($valid_until->fetchColumn() < date('Y-m-d')) {
                throw new Exception('У источника истёк срок санитарного заключения!');
            }

            // Основные параметры
            $odor = (int)($_POST['odor_rating'] ?? 0);
            $taste = (int)($_POST['taste_rating'] ?? 0);
            $color = (int)($_POST['color_degrees'] ?? 0);
            $turbidity = (float)($_POST['turbidity_emf'] ?? 0);
            $ph = (float)($_POST['ph'] ?? 0);
            $hardness = (float)($_POST['hardness_mmol'] ?? 0);
            $dry_residue = (int)($_POST['dry_residue_mg_l'] ?? 0);
            $iron = (float)($_POST['iron_mg_l'] ?? 0);
            $nitrates = (float)($_POST['nitrates_mg_l'] ?? 0);
            $fluorides = (float)($_POST['fluorides_mg_l'] ?? 0);
            $chlorides = (int)($_POST['chlorides_mg_l'] ?? 0);
            $sulfates = (int)($_POST['sulfates_mg_l'] ?? 0);
            $omch = (int)($_POST['omch_cfu_ml'] ?? 0);
            $yeast = (int)($_POST['yeast_mold_cfu_ml'] ?? 0);

            // Микробиология
            $coliforms = !empty($_POST['coliforms_100ml']);
            $thermotolerant = !empty($_POST['thermotolerant_coliforms_100ml']);
            $pseudomonas = !empty($_POST['pseudomonas_250ml']);

            // Проверка на допуск
            $is_approved = !(
                $coliforms || $thermotolerant || $pseudomonas ||
                $nitrates > 50 || $color > 30 || $turbidity > 2.5
            );

            $stmt = $pdo->prepare("
                INSERT INTO raw_water_tests (
                    source_id, sampled_at, sampled_by,
                    odor_rating, taste_rating, color_degrees, turbidity_emf,
                    ph, hardness_mmol, dry_residue_mg_l, iron_mg_l,
                    nitrates_mg_l, fluorides_mg_l, chlorides_mg_l, sulfates_mg_l,
                    omch_cfu_ml, yeast_mold_cfu_ml,
                    coliforms_100ml, thermotolerant_coliforms_100ml, pseudomonas_250ml,
                    is_approved_for_treatment
                ) VALUES (
                    ?, NOW(), ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?,
                    ?, ?, ?,
                    ?
                )
            ");
            $stmt->execute([
                $source_id, $operator,
                $odor, $taste, $color, $turbidity,
                $ph, $hardness, $dry_residue, $iron,
                $nitrates, $fluorides, $chlorides, $sulfates,
                $omch, $yeast,
                $coliforms, $thermotolerant, $pseudomonas,
                $is_approved
            ]);

            // Get the ID of the newly created raw test
            $raw_test_id = $pdo->lastInsertId();
            
            // Get the raw test details to set in context
            $raw_test = $pdo->query("
                SELECT r.*, ws.name AS source_name, o.full_name AS sampled_by_name
                FROM raw_water_tests r
                JOIN water_sources ws ON r.source_id = ws.id
                LEFT JOIN operators o ON r.sampled_by = o.full_name
                WHERE r.id = $raw_test_id
            ")->fetch();
            
            $message = $is_approved ? '✅ Проба одобрена для очистки.' : '❌ Проба забракована: нарушены санитарные нормы.';
            $message_type = $is_approved ? 'success' : 'error';
            if ($is_approved) {
                $current_step = 2;
                $context['raw_test'] = $raw_test;
            }
            // Save context and current step to session
            $_SESSION['production_context'] = $context;
            $_SESSION['current_step'] = $current_step;

        } elseif ($_POST['step'] == 2) {
            $raw_test_id = $context['raw_test']['id'] ?? null;
            if (!$raw_test_id) throw new Exception('Нет пробы для очистки.');

            $volume = (int)$_POST['volume_treated_l'];
            if ($volume <= 0) throw new Exception('Укажите объём.');
            $operator_id = (int)$_POST['operator']; // ID оператора
            $operator = $pdo->query("SELECT full_name FROM operators WHERE id = $operator_id AND role = 'operator'")->fetchColumn();
            if (!$operator) throw new Exception('Выберите корректного оператора.');
            $treatments = $_POST['treatments'] ?? ['Мех. фильтрация'];

            $pdo->prepare("
                INSERT INTO water_treatments (raw_test_id, started_at, finished_at, volume_treated_l, treatment_type, operator)
                VALUES (?, NOW(), NOW(), ?, ?, ?)
            ")->execute([$raw_test_id, $volume, json_encode($treatments), $operator]);

            // Get the ID of the newly created treatment
            $treatment_id = $pdo->lastInsertId();
            
            // Get the treatment details to set in context
            $treatment = $pdo->query("
                SELECT t.*, ws.name AS source_name, o.full_name AS operator_name
                FROM water_treatments t
                JOIN raw_water_tests r ON t.raw_test_id = r.id
                JOIN water_sources ws ON r.source_id = ws.id
                LEFT JOIN operators o ON t.operator = o.full_name
                WHERE t.id = $treatment_id
            ")->fetch();
            
            $message = '✅ Очистка завершена. Требуется лабораторный контроль.';
            $message_type = 'success';
            $current_step = 3;
            $context['treatment'] = $treatment;
            // Save context and current step to session
            $_SESSION['production_context'] = $context;
            $_SESSION['current_step'] = $current_step;

        } elseif ($_POST['step'] == 3) {
            $treatment_id = $context['treatment']['id'] ?? null;
            if (!$treatment_id) throw new Exception('Нет очистки для анализа.');

            $lab_operator_id = (int)$_POST['tested_by']; // ID лаборанта
            $lab_operator = $pdo->query("SELECT full_name FROM operators WHERE id = $lab_operator_id AND role = 'lab_analyst'")->fetchColumn();
            if (!$lab_operator) throw new Exception('Выберите корректного лаборанта.');

            // Показатели после очистки
            $odor = $_POST['odor'] ?? 'Без постороннего';
            $taste = $_POST['taste'] ?? 'Отсутствует';
            $transparency = $_POST['transparency'] ?? 'Прозрачная';
            $color = $_POST['color'] ?? 'Не окрашена';
            $ph = (float)($_POST['ph'] ?? 7.2);
            $hardness = (float)($_POST['hardness'] ?? 4.0);
            $dry_residue = (int)($_POST['dry_residue'] ?? 500);
            $iron = (float)($_POST['iron'] ?? 0.1);
            $nitrates = (float)($_POST['nitrates'] ?? 20.0);
            $fluorides = (float)($_POST['fluorides'] ?? 1.0);
            $omch = (int)($_POST['omch'] ?? 50);
            $yeast = (int)($_POST['yeast'] ?? 20);

            $coliforms = !empty($_POST['coliforms_detected']);
            $thermotolerant = !empty($_POST['thermotolerant_coliforms_detected']);
            $pseudomonas = !empty($_POST['pseudomonas_detected']);

            $is_compliant = !($coliforms || $thermotolerant || $pseudomonas) &&
                $ph >= 6.5 && $ph <= 9.0 &&
                $hardness <= 7.0 &&
                $dry_residue <= 1000 &&
                $iron <= 0.3 &&
                $nitrates <= 45.0 &&
                $fluorides >= 0.6 && $fluorides <= 1.5 &&
                $omch <= 100 &&
                $yeast <= 100;

            $pdo->prepare("
                INSERT INTO treated_water_tests (
                    treatment_id, tested_at, tested_by,
                    odor, taste, transparency, color,
                    ph, hardness_mmol, dry_residue_mg_l, iron_mg_l, nitrates_mg_l, fluorides_mg_l,
                    omch_cfu_ml, coliforms_detected, thermotolerant_coliforms_detected, pseudomonas_detected,
                    yeast_mold_cfu_ml, is_compliant, notes
                ) VALUES (
                    ?, NOW(), ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?
                )
            ")->execute([
                $treatment_id, $lab_operator,
                $odor, $taste, $transparency, $color,
                $ph, $hardness, $dry_residue, $iron, $nitrates, $fluorides,
                $omch, $coliforms, $thermotolerant, $pseudomonas,
                $yeast, $is_compliant, null
            ]);

            // Get the ID of the newly created analysis
            $analysis_id = $pdo->lastInsertId();
            
            // Get the analysis details to set in context
            $analysis = $pdo->query("
                SELECT tw.*, ws.name AS source_name, o.full_name AS tested_by_name
                FROM treated_water_tests tw
                JOIN water_treatments t ON tw.treatment_id = t.id
                JOIN raw_water_tests r ON t.raw_test_id = r.id
                JOIN water_sources ws ON r.source_id = ws.id
                LEFT JOIN operators o ON tw.tested_by = o.full_name
                WHERE tw.id = $analysis_id
            ")->fetch();
            
            $message = $is_compliant ? '✅ Анализ пройден. Вода готова к розливу.' : '❌ Анализ не пройден: нарушены нормы СТБ 1575-2013.';
            $message_type = $is_compliant ? 'success' : 'error';
            if ($is_compliant) {
                $current_step = 4;
                $context['analysis'] = $analysis;
            }
            // Save context and current step to session
            $_SESSION['production_context'] = $context;
            $_SESSION['current_step'] = $current_step;

        } elseif ($_POST['step'] == 4) {
            $analysis_id = $context['analysis']['id'] ?? null;
            if (!$analysis_id) throw new Exception('Нет анализа для партии.');

            $brand_id = (int)$_POST['brand_id'];
            $bottle_id = (int)$_POST['bottle_id'];
            $line_id = (int)$_POST['line_id'];
            $bottles = (int)$_POST['total_bottles'];
            $operator_id = (int)$_POST['operator']; // ID оператора
            $operator = $pdo->query("SELECT full_name FROM operators WHERE id = $operator_id AND role = 'operator'")->fetchColumn();
            if (!$operator) throw new Exception('Выберите корректного оператора.');

            // Номер партии
            $date_part = date('ymd');
            $count = $pdo->query("SELECT COUNT(*) FROM batches WHERE batch_number LIKE 'W-$date_part-%'")->fetchColumn();
            $batch_number = "W-$date_part-" . str_pad($count + 1, 3, '0', STR_PAD_LEFT);

            // Объём в литрах
            $liters = $bottles * $pdo->query("SELECT volume_l FROM bottle_types WHERE id = $bottle_id")->fetchColumn();

            $pdo->prepare("
                INSERT INTO batches (
                    batch_number, brand_id, treated_test_id, bottling_datetime,
                    shelf_life_months, bottle_type_id, production_line_id,
                    total_bottles, total_liters, operator_name, status, remaining_bottles
                ) VALUES (?, ?, ?, NOW(), 12, ?, ?, ?, ?, ?, 'Годна к реализации', ?)
            ")->execute([$batch_number, $brand_id, $analysis_id, $bottle_id, $line_id, $bottles, $liters, $operator, $bottles]);

            $message = "✅ Партия $batch_number зарегистрирована.";
            $message_type = 'success';
            $current_step = 1;
            // Clear context and reset step to 1
            $_SESSION['production_context'] = [];
            $_SESSION['current_step'] = 1;
        }
    } catch (Exception $e) {
        $message = "❌ " . $e->getMessage();
        $message_type = 'error';
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Производственный цикл | AquaTrack</title>
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

        .progress-container {
            background: var(--card-bg); padding: 24px; border-radius: 16px; margin: 24px 0; border: 1px solid var(--border);
        }
        .stages { display: flex; justify-content: space-between; position: relative; max-width: 800px; margin: 0 auto; }
        .stages::before { content: ''; position: absolute; top: 24px; left: 30px; right: 30px; height: 3px; background: var(--border); }
        .stage { position: relative; z-index: 2; display: flex; flex-direction: column; align-items: center; width: 130px; }
        .stage-circle { width: 48px; height: 48px; border-radius: 50%; background: #264653; display: flex; align-items: center; justify-content: center; margin-bottom: 8px; font-weight: bold; color: var(--text-secondary); border: 2px solid var(--border); }
        .stage.active .stage-circle { background: var(--accent); border-color: var(--accent); color: #0c1a2d; }
        .stage.completed .stage-circle { background: var(--accent); border-color: var(--accent); color: #0c1a2d; }
        .stage-label { font-size: 12px; color: var(--text-secondary); text-align: center; line-height: 1.3; }
        .stage.active .stage-label, .stage.completed .stage-label { color: var(--accent); font-weight: 600; }

        .form-container { background: var(--card-bg); padding: 28px; border-radius: 16px; margin: 24px 0; border: 1px solid var(--border); }
        .form-title { font-size: 22px; margin-bottom: 20px; color: var(--accent); display: flex; align-items: center; gap: 10px; }
        .form-desc { color: var(--text-secondary); margin-bottom: 24px; font-size: 15px; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 6px; font-weight: 600; }
        .field-hint { font-size: 13px; color: var(--text-secondary); margin-top: 4px; }
        input, select, textarea { width: 100%; padding: 10px 12px; border: 1px solid var(--border); border-radius: 6px; background: #142c45; color: var(--text); }
        .checkbox-group { display: flex; gap: 15px; margin-top: 6px; flex-wrap: wrap; }
        .checkbox-item { display: flex; align-items: center; gap: 6px; }
        .btn { background: var(--accent-dark); color: white; border: none; padding: 12px 28px; border-radius: 8px; font-weight: 600; font-size: 16px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; }
        .btn:hover { opacity: 0.9; }

        .alert { padding: 16px; border-radius: 8px; margin: 20px 0; }
        .alert-success { background: rgba(129,199,132,0.2); border-left: 4px solid var(--success); }
        .alert-error { background: rgba(244,67,80,0.2); border-left: 4px solid var(--danger); }
        .alert-success, .alert-error { color: var(--text); }

        footer { text-align: center; color: var(--text-secondary); padding: 20px 0; border-top: 1px solid var(--border); margin-top: 20px; }
    </style>
</head>
<body>
    <nav>
        <div class="logo"><i class="fas fa-droplet"></i> AquaTrack</div>
        <ul class="nav-links">
            <li><a href="index.php"><i class="fas fa-home"></i> Главная</a></li>
            <li><a href="production.php" class="active"><i class="fas fa-industry"></i> Производство</a></li>
            <li><a href="shipments.php"><i class="fas fa-truck"></i> Отгрузки</a></li>
            <li><a href="archive.php"><i class="fas fa-archive"></i> Архив</a></li>
            <li><a href="reports.php"><i class="fas fa-file-pdf"></i> Отчёты</a></li>
            <li><a href="sources.php"><i class="fas fa-map-marker-alt"></i> Источники</a></li>
        </ul>
    </nav>

    <div class="container">
        <header>
            <h1>Производственный цикл</h1>
            <div class="subtitle">Все данные загружаются из базы. Выборка — только из существующих записей.</div>
        </header>

        <div class="progress-container">
            <div class="stages">
                <?php
                $steps = [
                    ['Предварительный анализ', 'fa-vial'],
                    ['Очистка воды', 'fa-filter'],
                    ['Лабораторный контроль', 'fa-flask'],
                    ['Регистрация партии', 'fa-box']
                ];
                foreach ($steps as $index => $step): ?>
                    <div class="stage <?= 
                        ($index + 1) < $current_step ? 'completed' : 
                        (($index + 1) == $current_step ? 'active' : '')
                    ?>">
                        <div class="stage-circle">
                            <i class="fas <?= $step[1] ?>"></i>
                        </div>
                        <div class="stage-label"><?= $step[0] ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?= $message_type ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <div class="form-container">
            <?php if ($current_step == 1): ?>
                <h2 class="form-title"><i class="fas fa-vial"></i> Этап 1: Предварительный анализ воды</h2>
                <p class="form-desc">Все данные берутся из реальных источников. Выберите источник из списка с действующим санзаключением.</p>
                <form method="POST">
                    <input type="hidden" name="step" value="1">
                    <div class="form-group">
                        <label for="source_id">Источник воды *</label>
                        <select name="source_id" id="source_id" required>
                            <option value="">Выберите источник</option>
                            <?php foreach ($sources as $src): ?>
                                <option value="<?= $src['id'] ?>" <?= isset($last_raw_test['source_id']) && $last_raw_test['source_id'] == $src['id'] ? 'selected' : '' ?>><?= htmlspecialchars($src['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="field-hint">Только источники с действующим санитарным заключением.</div>
                    </div>
                    <div class="form-group">
                        <label for="sampled_by">ФИО отобравшего пробу *</label>
                        <select name="sampled_by" required>
                            <option value="">Выберите оператора</option>
                            <?php foreach ($operators_by_role['operator'] as $op): ?>
                                <option value="<?= $op['id'] ?>" <?= isset($last_raw_test['sampled_by']) && $last_raw_test['sampled_by'] == $op['full_name'] ? 'selected' : '' ?>><?= htmlspecialchars($op['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Органолептические показатели</label>
                        <div class="field-hint">Оценка внешнего вида и вкусовых качеств воды</div>
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px;">
                            <div>
                                <label>Запах (0–2)</label>
                                <input type="number" name="odor_rating" min="0" max="2" value="<?= $last_raw_test['odor_rating'] ?? 0 ?>">
                                <div class="field-hint">0 - без запаха, 1 - слабый, 2 - выраженный. Влияет на потребительские свойства воды.</div>
                            </div>
                            <div>
                                <label>Привкус (0–2)</label>
                                <input type="number" name="taste_rating" min="0" max="2" value="<?= $last_raw_test['taste_rating'] ?? 0 ?>">
                                <div class="field-hint">0 - без привкуса, 1 - слабый, 2 - выраженный. Влияет на вкусовые качества воды.</div>
                            </div>
                            <div>
                                <label>Цветность (°)</label>
                                <input type="number" name="color_degrees" min="0" value="<?= $last_raw_test['color_degrees'] ?? 10 ?>">
                                <div class="field-hint">Допустимо до 30° по стандарту. Показывает наличие примесей, влияет на визуальное восприятие.</div>
                            </div>
                            <div>
                                <label>Мутность (ЕМФ)</label>
                                <input type="number" name="turbidity_emf" min="0" step="0.1" value="<?= $last_raw_test['turbidity_emf'] ?? 0.5 ?>">
                                <div class="field-hint">Допустимо до 2.5 ЕМФ по стандарту. Показывает наличие взвешенных частиц.</div>
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Физико-химические показатели</label>
                        <div class="field-hint">Химический состав воды, влияющий на безопасность и качество</div>
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px;">
                            <div>
                                <label>pH</label>
                                <input type="number" name="ph" step="0.01" value="<?= $last_raw_test['ph'] ?? 7.2 ?>">
                                <div class="field-hint">Норма: 6.5–9.0. Показывает кислотность/щелочность воды, влияет на вкус и коррозионные свойства.</div>
                            </div>
                            <div>
                                <label>Жёсткость (ммоль/л)</label>
                                <input type="number" name="hardness_mmol" step="0.1" value="<?= $last_raw_test['hardness_mmol'] ?? 4.0 ?>">
                                <div class="field-hint">Норма: до 7.0 ммоль/л. Определяет количество солей кальция и магния, влияет на вкус и образование накипи.</div>
                            </div>
                            <div>
                                <label>Сухой остаток (мг/л)</label>
                                <input type="number" name="dry_residue_mg_l" value="<?= $last_raw_test['dry_residue_mg_l'] ?? 500 ?>">
                                <div class="field-hint">Норма: до 1000 мг/л. Общее количество растворённых веществ, влияет на вкус воды.</div>
                            </div>
                            <div>
                                <label>Железо (мг/л)</label>
                                <input type="number" name="iron_mg_l" step="0.001" value="<?= $last_raw_test['iron_mg_l'] ?? 0.1 ?>">
                                <div class="field-hint">Норма: до 0.3 мг/л. Высокое содержание придаёт воде металлический привкус и окрашивает её.</div>
                            </div>
                            <div>
                                <label>Нитраты (мг/л)</label>
                                <input type="number" name="nitrates_mg_l" step="0.1" value="<?= $last_raw_test['nitrates_mg_l'] ?? 20.0 ?>">
                                <div class="field-hint">Норма: до 45 мг/л. Показатель загрязнения, высокие концентрации опасны для здоровья, особенно младенцев.</div>
                            </div>
                            <div>
                                <label>Фториды (мг/л)</label>
                                <input type="number" name="fluorides_mg_l" step="0.01" value="<?= $last_raw_test['fluorides_mg_l'] ?? 1.0 ?>">
                                <div class="field-hint">Норма: 0.6–1.5 мг/л. Полезны для зубов в малых концентрациях, но вредны при превышении.</div>
                            </div>
                            <div>
                                <label>Хлориды (мг/л)</label>
                                <input type="number" name="chlorides_mg_l" value="<?= $last_raw_test['chlorides_mg_l'] ?? 100 ?>">
                                <div class="field-hint">Норма: до 250 мг/л. Влияют на вкус воды, высокие концентрации придают солёный привкус.</div>
                            </div>
                            <div>
                                <label>Сульфаты (мг/л)</label>
                                <input type="number" name="sulfates_mg_l" value="<?= $last_raw_test['sulfates_mg_l'] ?? 150 ?>">
                                <div class="field-hint">Норма: до 500 мг/л. Могут вызывать послабляющий эффект при высоких концентрациях.</div>
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Микробиология</label>
                        <div class="field-hint">Показатели безопасности воды по микробиологическим параметрам</div>
                        <div class="checkbox-group">
                            <div class="checkbox-item">
                                <input type="checkbox" name="coliforms_100ml" id="coliforms" <?= (isset($last_raw_test['coliforms_100ml']) && $last_raw_test['coliforms_100ml']) ? 'checked' : '' ?>>
                                <label for="coliforms">Колиформы</label>
                                <div class="field-hint">Обнаружение в 100 мл воды - недопустимо. Показатель фекального загрязнения, наличие указывает на возможное присутствие патогенных бактерий.</div>
                            </div>
                            <div class="checkbox-item">
                                <input type="checkbox" name="thermotolerant_coliforms_100ml" id="thermotolerant" <?= (isset($last_raw_test['thermotolerant_coliforms_100ml']) && $last_raw_test['thermotolerant_coliforms_100ml']) ? 'checked' : '' ?>>
                                <label for="thermotolerant">Термотолерантные колиформы</label>
                                <div class="field-hint">Обнаружение в 100 мл воды - недопустимо. Более специфичный показатель фекального загрязнения, включая кишечную палочку.</div>
                            </div>
                            <div class="checkbox-item">
                                <input type="checkbox" name="pseudomonas_250ml" id="pseudomonas" <?= (isset($last_raw_test['pseudomonas_250ml']) && $last_raw_test['pseudomonas_250ml']) ? 'checked' : '' ?>>
                                <label for="pseudomonas">Pseudomonas</label>
                                <div class="field-hint">Обнаружение в 250 мл воды - недопустимо. Условно-патогенная бактерия, может вызывать инфекции у ослабленных людей.</div>
                            </div>
                        </div>
                        <div class="field-hint">Любое обнаружение — автоматический брак.</div>
                    </div>
                    <div class="form-group">
                        <label>Микробное число</label>
                        <div class="field-hint">Количество микроорганизмов в воде</div>
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px;">
                            <div>
                                <label>ОМЧ (КОЕ/мл)</label>
                                <input type="number" name="omch_cfu_ml" value="<?= $last_raw_test['omch_cfu_ml'] ?? 50 ?>">
                                <div class="field-hint">Норма: до 100 КОЕ/мл. Общее микробное число, показывает общее количество жизнеспособных микроорганизмов.</div>
                            </div>
                            <div>
                                <label>Дрожжи/плесени (КОЕ/мл)</label>
                                <input type="number" name="yeast_mold_cfu_ml" value="<?= $last_raw_test['yeast_mold_cfu_ml'] ?? 20 ?>">
                                <div class="field-hint">Норма: до 10 КОЕ/мл. Показывает наличие дрожжевых и плесневых грибов, может указывать на биологическое загрязнение.</div>
                            </div>
                        </div>
                    </div>
                    <button type="submit" class="btn"><i class="fas fa-save"></i> Сохранить анализ</button>
                </form>

            <?php elseif ($current_step == 2): ?>
                <h2 class="form-title"><i class="fas fa-filter"></i> Этап 2: Очистка воды</h2>
                <?php if (isset($context['raw_test']) && $context['raw_test']): ?>
                    <p class="form-desc">Проба из источника: <strong><?= htmlspecialchars($context['raw_test']['source_name']) ?></strong> (<?= date('d.m.Y H:i', strtotime($context['raw_test']['sampled_at'])) ?>)</p>
                <?php else: ?>
                    <p class="form-desc">Ожидание данных о пробе из источника...</p>
                <?php endif; ?>
                <form method="POST">
                    <input type="hidden" name="step" value="2">
                    <div class="form-group">
                        <label for="volume_treated_l">Объём после очистки (л) *</label>
                        <input type="number" name="volume_treated_l" required min="1" value="<?= $last_treatment['volume_treated_l'] ?? 10000 ?>">
                    </div>
                    <div class="form-group">
                        <label>Методы очистки</label>
                        <div class="checkbox-group">
                            <div class="checkbox-item"><input type="checkbox" name="treatments[]" value="Мех. фильтрация" checked><label>Механическая фильтрация</label></div>
                            <div class="checkbox-item"><input type="checkbox" name="treatments[]" value="УФ"><label>УФ-обработка</label></div>
                            <div class="checkbox-item"><input type="checkbox" name="treatments[]" value="Озон"><label>Озонирование</label></div>
                            <div class="checkbox-item"><input type="checkbox" name="treatments[]" value="Обратный осмос"><label>Обратный осмос</label></div>
                            <div class="checkbox-item"><input type="checkbox" name="treatments[]" value="Минерализация"><label>Минерализация</label></div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="operator">ФИО оператора *</label>
                        <select name="operator" required>
                            <option value="">Выберите оператора</option>
                            <?php foreach ($operators_by_role['operator'] as $op): ?>
                                <option value="<?= $op['id'] ?>" <?= isset($last_treatment['operator']) && $last_treatment['operator'] == $op['full_name'] ? 'selected' : '' ?>><?= htmlspecialchars($op['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn"><i class="fas fa-save"></i> Завершить очистку</button>
                </form>

            <?php elseif ($current_step == 3): ?>
                <h2 class="form-title"><i class="fas fa-flask"></i> Этап 3: Лабораторный контроль</h2>
                <p class="form-desc">Источник: <strong><?= htmlspecialchars($context['treatment']['source_name']) ?></strong>. Очистка завершена <?= date('d.m.Y H:i', strtotime($context['treatment']['started_at'])) ?>.</p>
                <form method="POST">
                    <input type="hidden" name="step" value="3">
                    <div class="form-group">
                        <label for="tested_by">ФИО лаборанта *</label>
                        <select name="tested_by" required>
                            <option value="">Выберите лаборанта</option>
                            <?php foreach ($operators_by_role['lab_analyst'] as $op): ?>
                                <option value="<?= $op['id'] ?>" <?= isset($last_analysis['tested_by']) && $last_analysis['tested_by'] == $op['full_name'] ? 'selected' : '' ?>><?= htmlspecialchars($op['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Органолептика</label>
                        <div class="field-hint">Оценка внешнего вида и вкусовых качеств воды после очистки</div>
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px;">
                            <div>
                                <label>Запах</label>
                                <select name="odor">
                                    <option value="Без постороннего" <?= (isset($last_analysis['odor']) && $last_analysis['odor'] == 'Без постороннего') ? 'selected' : '' ?>>Без постороннего</option>
                                    <option value="Посторонний" <?= (isset($last_analysis['odor']) && $last_analysis['odor'] == 'Посторонний') ? 'selected' : '' ?>>Посторонний</option>
                                </select>
                                <div class="field-hint">Должен быть без постороннего запаха. Влияет на потребительские свойства воды.</div>
                            </div>
                            <div>
                                <label>Привкус</label>
                                <select name="taste">
                                    <option value="Отсутствует" <?= (isset($last_analysis['taste']) && $last_analysis['taste'] == 'Отсутствует') ? 'selected' : '' ?>>Отсутствует</option>
                                    <option value="Присутствует" <?= (isset($last_analysis['taste']) && $last_analysis['taste'] == 'Присутствует') ? 'selected' : '' ?>>Присутствует</option>
                                </select>
                                <div class="field-hint">Должен быть без постороннего привкуса. Влияет на вкусовые качества воды.</div>
                            </div>
                            <div>
                                <label>Прозрачность</label>
                                <select name="transparency">
                                    <option value="Прозрачная" <?= (isset($last_analysis['transparency']) && $last_analysis['transparency'] == 'Прозрачная') ? 'selected' : '' ?>>Прозрачная</option>
                                    <option value="Мутная" <?= (isset($last_analysis['transparency']) && $last_analysis['transparency'] == 'Мутная') ? 'selected' : '' ?>>Мутная</option>
                                </select>
                                <div class="field-hint">Вода должна быть прозрачной. Показывает отсутствие взвешенных частиц.</div>
                            </div>
                            <div>
                                <label>Цвет</label>
                                <select name="color">
                                    <option value="Не окрашена" <?= (isset($last_analysis['color']) && $last_analysis['color'] == 'Не окрашена') ? 'selected' : '' ?>>Не окрашена</option>
                                    <option value="Окрашена" <?= (isset($last_analysis['color']) && $last_analysis['color'] == 'Окрашена') ? 'selected' : '' ?>>Окрашена</option>
                                </select>
                                <div class="field-hint">Вода не должна быть окрашена. Показывает отсутствие примесей, влияющих на цвет.</div>
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Физико-химия</label>
                        <div class="field-hint">Химический состав воды после очистки, влияющий на безопасность и качество</div>
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px;">
                            <div>
                                <label>pH</label>
                                <input type="number" name="ph" step="0.01" value="<?= $last_analysis['ph'] ?? 7.2 ?>">
                                <div class="field-hint">Норма: 6.5–9.0. Показывает кислотность/щелочность воды, влияет на вкус и коррозионные свойства.</div>
                            </div>
                            <div>
                                <label>Жёсткость (ммоль/л)</label>
                                <input type="number" name="hardness" step="0.1" value="<?= $last_analysis['hardness_mmol'] ?? 4.0 ?>">
                                <div class="field-hint">Норма: до 7.0 ммоль/л. Определяет количество солей кальция и магния, влияет на вкус и образование накипи.</div>
                            </div>
                            <div>
                                <label>Сухой остаток (мг/л)</label>
                                <input type="number" name="dry_residue" value="<?= $last_analysis['dry_residue_mg_l'] ?? 500 ?>">
                                <div class="field-hint">Норма: до 1000 мг/л. Общее количество растворённых веществ, влияет на вкус воды.</div>
                            </div>
                            <div>
                                <label>Железо (мг/л)</label>
                                <input type="number" name="iron" step="0.001" value="<?= $last_analysis['iron_mg_l'] ?? 0.1 ?>">
                                <div class="field-hint">Норма: до 0.3 мг/л. Высокое содержание придаёт воде металлический привкус и окрашивает её.</div>
                            </div>
                            <div>
                                <label>Нитраты (мг/л)</label>
                                <input type="number" name="nitrates" step="0.1" value="<?= $last_analysis['nitrates_mg_l'] ?? 20.0 ?>">
                                <div class="field-hint">Норма: до 45 мг/л. Показатель загрязнения, высокие концентрации опасны для здоровья, особенно младенцев.</div>
                            </div>
                            <div>
                                <label>Фториды (мг/л)</label>
                                <input type="number" name="fluorides" step="0.01" value="<?= $last_analysis['fluorides_mg_l'] ?? 1.0 ?>">
                                <div class="field-hint">Норма: 0.6–1.5 мг/л. Полезны для зубов в малых концентрациях, но вредны при превышении.</div>
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Микробиология</label>
                        <div class="checkbox-group">
                            <div class="checkbox-item">
                                <input type="checkbox" name="coliforms_detected" id="c1" <?= (isset($last_analysis['coliforms_detected']) && $last_analysis['coliforms_detected']) ? 'checked' : '' ?>>
                                <label for="c1">Колиформы</label>
                                <div class="field-hint">Обнаружение недопустимо</div>
                            </div>
                            <div class="checkbox-item">
                                <input type="checkbox" name="thermotolerant_coliforms_detected" id="c2" <?= (isset($last_analysis['thermotolerant_coliforms_detected']) && $last_analysis['thermotolerant_coliforms_detected']) ? 'checked' : '' ?>>
                                <label for="c2">Термотолерантные колиформы</label>
                                <div class="field-hint">Обнаружение недопустимо</div>
                            </div>
                            <div class="checkbox-item">
                                <input type="checkbox" name="pseudomonas_detected" id="c3" <?= (isset($last_analysis['pseudomonas_detected']) && $last_analysis['pseudomonas_detected']) ? 'checked' : '' ?>>
                                <label for="c3">Pseudomonas</label>
                                <div class="field-hint">Обнаружение недопустимо</div>
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Микробное число</label>
                        <div class="field-hint">Количество микроорганизмов в воде после очистки</div>
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px;">
                            <div>
                                <label>ОМЧ (КОЕ/мл)</label>
                                <input type="number" name="omch" value="<?= $last_analysis['omch_cfu_ml'] ?? 50 ?>">
                                <div class="field-hint">Норма: до 100 КОЕ/мл. Общее микробное число, показывает общее количество жизнеспособных микроорганизмов.</div>
                            </div>
                            <div>
                                <label>Дрожжи/плесени (КОЕ/мл)</label>
                                <input type="number" name="yeast" value="<?= $last_analysis['yeast_mold_cfu_ml'] ?? 20 ?>">
                                <div class="field-hint">Норма: до 100 КОЕ/мл. Показывает наличие дрожжевых и плесневых грибов, может указывать на биологическое загрязнение.</div>
                            </div>
                        </div>
                    </div>
                    <button type="submit" class="btn"><i class="fas fa-save"></i> Сохранить анализ</button>
                </form>

            <?php elseif ($current_step == 4): ?>
                <h2 class="form-title"><i class="fas fa-box"></i> Этап 4: Регистрация партии</h2>
                <p class="form-desc">Вода из источника <strong><?= htmlspecialchars($context['analysis']['source_name']) ?></strong> прошла контроль <?= date('d.m.Y H:i', strtotime($context['analysis']['tested_at'])) ?>.</p>
                <form method="POST">
                    <input type="hidden" name="step" value="4">
                    <div class="form-group">
                        <label for="brand_id">Марка воды *</label>
                        <select name="brand_id" required>
                            <?php foreach ($brands as $b): ?>
                                <option value="<?= $b['id'] ?>" <?= isset($last_batch['brand_id']) && $last_batch['brand_id'] == $b['id'] ? 'selected' : '' ?>><?= htmlspecialchars($b['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="bottle_id">Тип тары *</label>
                        <select name="bottle_id" required>
                            <?php foreach ($bottle_types as $bt): ?>
                                <option value="<?= $bt['id'] ?>" <?= isset($last_batch['bottle_type_id']) && $last_batch['bottle_type_id'] == $bt['id'] ? 'selected' : '' ?>><?= htmlspecialchars($bt['volume_l']) ?> л (<?= htmlspecialchars($bt['material']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="line_id">Производственная линия *</label>
                        <select name="line_id" required>
                            <?php foreach ($lines as $l): ?>
                                <option value="<?= $l['id'] ?>" <?= isset($last_batch['production_line_id']) && $last_batch['production_line_id'] == $l['id'] ? 'selected' : '' ?>><?= htmlspecialchars($l['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="total_bottles">Количество бутылок *</label>
                        <input type="number" name="total_bottles" required min="1" value="<?= $last_batch['total_bottles'] ?? 10000 ?>">
                    </div>
                    <div class="form-group">
                        <label for="operator">ФИО оператора *</label>
                        <select name="operator" required>
                            <option value="">Выберите оператора</option>
                            <?php foreach ($operators_by_role['operator'] as $op): ?>
                                <option value="<?= $op['id'] ?>" <?= isset($last_batch['operator_name']) && $last_batch['operator_name'] == $op['full_name'] ? 'selected' : '' ?>><?= htmlspecialchars($op['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn"><i class="fas fa-save"></i> Зарегистрировать партию</button>
                </form>

            <?php endif; ?>
        </div>

        <footer>
            Все данные загружаются из таблиц: water_sources, water_brands, bottle_types, clients, production_lines, operators.<br>
            Соответствие СТБ 1575-2013 и ТР ТС 021/2011.
        </footer>
    </div>
</body>
</html>