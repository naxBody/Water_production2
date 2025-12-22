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

// === ОБРАБОТКА ФОРМЫ ДОБАВЛЕНИЯ ИЛИ РЕДАКТИРОВАНИЯ ===
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? 'add';
        $id = (int)$_POST['id'] ?? 0;
        $name = trim($_POST['name']);
        $conclusion_number = trim($_POST['sanitary_conclusion_number']);
        $valid_until = $_POST['sanitary_conclusion_valid_until'];

        if (!$name || !$conclusion_number || !$valid_until) {
            throw new Exception('Заполните все поля.');
        }

        if ($action === 'add') {
            $pdo->prepare("
                INSERT INTO water_sources (name, sanitary_conclusion_number, sanitary_conclusion_valid_until)
                VALUES (?, ?, ?)
            ")->execute([$name, $conclusion_number, $valid_until]);
            $message = '✅ Источник добавлен.';
            $message_type = 'success';
        } elseif ($action === 'edit' && $id > 0) {
            $pdo->prepare("
                UPDATE water_sources SET name = ?, sanitary_conclusion_number = ?, sanitary_conclusion_valid_until = ?
                WHERE id = ?
            ")->execute([$name, $conclusion_number, $valid_until, $id]);
            $message = '✅ Источник обновлён.';
            $message_type = 'success';
        }

    } catch (Exception $e) {
        $message = "❌ " . $e->getMessage();
        $message_type = 'error';
    }
}

// === ПОЛУЧЕНИЕ ДАННЫХ ===
$sources = $pdo->query("
    SELECT 
        id, name, sanitary_conclusion_number, sanitary_conclusion_valid_until,
        CASE WHEN sanitary_conclusion_valid_until < CURDATE() THEN 'Истёк' ELSE 'Действует' END AS status
    FROM water_sources
    ORDER BY name
")->fetchAll();

// === ФИЛЬТРЫ ===
$expired_only = isset($_GET['filter']) && $_GET['filter'] === 'expired';

if ($expired_only) {
    $sources = array_filter($sources, function($s) {
        return $s['status'] === 'Истёк';
    });
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Источники воды | AquaTrack</title>
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
        .container { max-width: 1200px; margin: 0 auto; padding: 20px; }
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

        .form-container { background: var(--card-bg); padding: 24px; border-radius: 16px; margin: 24px 0; border: 1px solid var(--border); }
        .form-title { font-size: 20px; margin-bottom: 20px; color: var(--accent); display: flex; align-items: center; gap: 10px; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 6px; font-weight: 600; }
        input, select, textarea { width: 100%; padding: 10px 12px; border: 1px solid var(--border); border-radius: 6px; background: #142c45; color: var(--text); }
        .btn { background: var(--accent-dark); color: white; border: none; padding: 12px 28px; border-radius: 8px; font-weight: 600; font-size: 16px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; }
        .btn:hover { opacity: 0.9; }

        .section { background: var(--card-bg); padding: 24px; border-radius: 16px; margin: 24px 0; border: 1px solid var(--border); }
        .section-title { font-size: 20px; margin-bottom: 20px; color: var(--accent); display: flex; align-items: center; gap: 10px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px 10px; text-align: left; border-bottom: 1px solid var(--border); }
        th { color: var(--text-secondary); font-weight: 600; }
        tr:last-child td { border-bottom: none; }
        .status--good { color: var(--success); font-weight: 600; }
        .status--danger { color: var(--danger); font-weight: 600; }
        .empty { text-align: center; color: var(--text-secondary); padding: 20px; font-style: italic; }

        .filters { display: flex; gap: 16px; margin: 16px 0; }
        .filter-btn { padding: 8px 16px; border-radius: 6px; cursor: pointer; }
        .filter-btn.active { background: var(--accent); color: #0c1a2d; font-weight: 600; }

        footer { text-align: center; color: var(--text-secondary); padding: 20px 0; border-top: 1px solid var(--border); margin-top: 20px; }
    </style>
</head>
<body>
    <nav>
        <div class="logo"><i class="fas fa-droplet"></i> AquaTrack</div>
        <ul class="nav-links">
            <li><a href="index.php">Главная</a></li>
            <li><a href="production.php">Производство</a></li>
            <li><a href="shipments.php">Отгрузки</a></li>
            <li><a href="archive.php">Архив</a></li>
            <li><a href="reports.php">Отчёты</a></li>
            <li><a href="sources.php" class="active">Источники</a></li>
        </ul>
    </nav>

    <div class="container">
        <header>
            <h1>Управление источниками воды</h1>
            <div class="subtitle">Все источники должны иметь действующее санитарно-эпидемиологическое заключение.</div>
        </header>

        <?php if ($message): ?>
            <div class="alert alert-<?= $message_type ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <!-- Форма добавления -->
        <div class="form-container">
            <h2 class="form-title"><i class="fas fa-plus"></i> Добавить источник</h2>
            <form method="POST">
                <input type="hidden" name="action" value="add">
                <div class="form-group">
                    <label for="name">Название источника *</label>
                    <input type="text" name="name" id="name" required placeholder="Скважина №1, Родник «Белая Русь»">
                </div>
                <div class="form-group">
                    <label for="sanitary_conclusion_number">Номер санзаключения *</label>
                    <input type="text" name="sanitary_conclusion_number" id="sanitary_conclusion_number" required placeholder="СЭЗ-2025-001">
                </div>
                <div class="form-group">
                    <label for="sanitary_conclusion_valid_until">Дата окончания действия *</label>
                    <input type="date" name="sanitary_conclusion_valid_until" id="sanitary_conclusion_valid_until" required>
                </div>
                <button type="submit" class="btn"><i class="fas fa-save"></i> Сохранить источник</button>
            </form>
        </div>

        <!-- Фильтры -->
        <div class="filters">
            <a href="sources.php" class="filter-btn <?= !$expired_only ? 'active' : '' ?>">Все источники</a>
            <a href="sources.php?filter=expired" class="filter-btn <?= $expired_only ? 'active' : '' ?>">Истёкшие</a>
        </div>

        <!-- Список источников -->
        <div class="section">
            <h2 class="section-title"><i class="fas fa-water"></i> Список источников</h2>
            <?php if ($sources): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Название</th>
                            <th>Номер санзаключения</th>
                            <th>Действует до</th>
                            <th>Статус</th>
                            <th>Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sources as $s): ?>
                            <tr>
                                <td><?= htmlspecialchars($s['name']) ?></td>
                                <td><?= htmlspecialchars($s['sanitary_conclusion_number']) ?></td>
                                <td><?= date('d.m.Y', strtotime($s['sanitary_conclusion_valid_until'])) ?></td>
                                <td>
                                    <span class="status--<?= $s['status'] === 'Истёк' ? 'danger' : 'good' ?>">
                                        <?= htmlspecialchars($s['status']) ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="#" onclick="editSource(<?= $s['id'] ?>, '<?= addslashes($s['name']) ?>', '<?= addslashes($s['sanitary_conclusion_number']) ?>', '<?= $s['sanitary_conclusion_valid_until'] ?>')" class="btn" style="padding: 6px 12px; font-size: 14px;">
                                        <i class="fas fa-edit"></i> Редактировать
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p class="empty">Источников пока нет. Добавьте первый источник.</p>
            <?php endif; ?>
        </div>

        <footer>
            Все источники хранятся в архиве. Изменения применяются мгновенно.
        </footer>
    </div>

    <script>
        function editSource(id, name, conclusion, validUntil) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" value="${id}">
                <div class="form-group">
                    <label>Название источника *</label>
                    <input type="text" name="name" value="${name}" required>
                </div>
                <div class="form-group">
                    <label>Номер санзаключения *</label>
                    <input type="text" name="sanitary_conclusion_number" value="${conclusion}" required>
                </div>
                <div class="form-group">
                    <label>Дата окончания действия *</label>
                    <input type="date" name="sanitary_conclusion_valid_until" value="${validUntil}" required>
                </div>
                <button type="submit" class="btn"><i class="fas fa-save"></i> Обновить</button>
                <a href="sources.php" class="btn" style="background: #757575;">Отмена</a>
            `;
            const container = document.querySelector('.form-container');
            container.innerHTML = '<h2 class="form-title"><i class="fas fa-edit"></i> Редактировать источник</h2>';
            container.appendChild(form);
        }
    </script>
</body>
</html>