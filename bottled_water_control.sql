CREATE DATABASE IF NOT EXISTS bottled_water_control CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE bottled_water_control;

-- Справочники (меняются редко, но тоже часть архива)
CREATE TABLE water_brands (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE
) ENGINE=InnoDB;

CREATE TABLE water_sources (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    sanitary_conclusion_number VARCHAR(100) NOT NULL,
    sanitary_conclusion_valid_until DATE NOT NULL,
    coordinates VARCHAR(100) NULL
) ENGINE=InnoDB;

CREATE TABLE bottle_types (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    volume_l DECIMAL(4,2) NOT NULL,
    material VARCHAR(50) NOT NULL,
    description VARCHAR(100) NULL,
    UNIQUE(volume_l, material)
) ENGINE=InnoDB;

CREATE TABLE production_lines (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE
) ENGINE=InnoDB;

CREATE TABLE clients (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    inn VARCHAR(20) NOT NULL UNIQUE,
    address TEXT NOT NULL,
    contact_person VARCHAR(150) NULL
) ENGINE=InnoDB;

-- Предварительный анализ сырой воды (весь — в архиве)
CREATE TABLE raw_water_tests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_id INT UNSIGNED NOT NULL,
    sampled_at DATETIME NOT NULL,
    sampled_by VARCHAR(150) NOT NULL,
    odor_rating TINYINT UNSIGNED NULL CHECK (odor_rating BETWEEN 0 AND 2),
    taste_rating TINYINT UNSIGNED NULL CHECK (taste_rating BETWEEN 0 AND 2),
    color_degrees SMALLINT UNSIGNED NULL CHECK (color_degrees <= 100),
    turbidity_emf DECIMAL(4,2) NULL CHECK (turbidity_emf <= 10.0),
    ph DECIMAL(3,2) NULL CHECK (ph >= 0 AND ph <= 14),
    hardness_mmol DECIMAL(4,2) NULL,
    dry_residue_mg_l SMALLINT UNSIGNED NULL,
    iron_mg_l DECIMAL(4,3) NULL,
    manganese_mg_l DECIMAL(4,3) NULL,
    nitrates_mg_l DECIMAL(5,2) NULL,
    fluorides_mg_l DECIMAL(4,2) NULL,
    chlorides_mg_l SMALLINT UNSIGNED NULL,
    sulfates_mg_l SMALLINT UNSIGNED NULL,
    omch_cfu_ml MEDIUMINT UNSIGNED NULL,
    coliforms_100ml BOOLEAN NOT NULL DEFAULT FALSE,
    thermotolerant_coliforms_100ml BOOLEAN NOT NULL DEFAULT FALSE,
    pseudomonas_250ml BOOLEAN NOT NULL DEFAULT FALSE,
    yeast_mold_cfu_ml MEDIUMINT UNSIGNED NULL,
    is_approved_for_treatment BOOLEAN NOT NULL DEFAULT FALSE,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (source_id) REFERENCES water_sources(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- Очистка воды (все циклы — сохраняются)
CREATE TABLE water_treatments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    raw_test_id INT UNSIGNED NOT NULL,
    started_at DATETIME NOT NULL,
    finished_at DATETIME NOT NULL,
    volume_treated_l INT UNSIGNED NOT NULL,
    treatment_type JSON NOT NULL,
    operator VARCHAR(150) NOT NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (raw_test_id) REFERENCES raw_water_tests(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- Контрольный анализ после очистки (архив всех анализов)
CREATE TABLE treated_water_tests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    treatment_id INT UNSIGNED NOT NULL,
    tested_at DATETIME NOT NULL,
    tested_by VARCHAR(150) NOT NULL,
    odor VARCHAR(20) NOT NULL CHECK (odor IN ('Без постороннего', 'Посторонний')),
    taste VARCHAR(20) NOT NULL CHECK (taste IN ('Отсутствует', 'Присутствует')),
    transparency VARCHAR(20) NOT NULL CHECK (transparency IN ('Прозрачная', 'Мутная')),
    color VARCHAR(20) NOT NULL CHECK (color IN ('Не окрашена', 'Окрашена')),
    ph DECIMAL(3,2) NOT NULL CHECK (ph >= 6.5 AND ph <= 9.0),
    hardness_mmol DECIMAL(4,2) NOT NULL CHECK (hardness_mmol <= 7.0),
    dry_residue_mg_l SMALLINT UNSIGNED NOT NULL CHECK (dry_residue_mg_l <= 1000),
    iron_mg_l DECIMAL(4,3) NOT NULL CHECK (iron_mg_l <= 0.3),
    nitrates_mg_l DECIMAL(5,2) NOT NULL CHECK (nitrates_mg_l <= 45.0),
    fluorides_mg_l DECIMAL(4,2) NOT NULL CHECK (fluorides_mg_l >= 0.6 AND fluorides_mg_l <= 1.5),
    omch_cfu_ml MEDIUMINT UNSIGNED NOT NULL CHECK (omch_cfu_ml <= 100),
    coliforms_detected BOOLEAN NOT NULL DEFAULT FALSE,
    thermotolerant_coliforms_detected BOOLEAN NOT NULL DEFAULT FALSE,
    pseudomonas_detected BOOLEAN NOT NULL DEFAULT FALSE,
    yeast_mold_cfu_ml MEDIUMINT UNSIGNED NOT NULL CHECK (yeast_mold_cfu_ml <= 100),
    is_compliant BOOLEAN GENERATED ALWAYS AS (
        NOT coliforms_detected 
        AND NOT thermotolerant_coliforms_detected 
        AND NOT pseudomonas_detected
    ) STORED,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (treatment_id) REFERENCES water_treatments(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- Партии продукции (все — в архиве, даже брак)
CREATE TABLE batches (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    batch_number VARCHAR(30) NOT NULL UNIQUE,
    brand_id INT UNSIGNED NOT NULL,
    treated_test_id INT UNSIGNED NOT NULL,
    bottling_datetime DATETIME NOT NULL,
    shelf_life_months TINYINT UNSIGNED NOT NULL DEFAULT 12,
    bottle_type_id INT UNSIGNED NOT NULL,
    production_line_id INT UNSIGNED NOT NULL,
    total_bottles INT UNSIGNED NOT NULL,
    total_liters DECIMAL(10,2) NOT NULL,
    operator_name VARCHAR(150) NULL,
    status ENUM('Ожидает анализа', 'Годна к реализации', 'Брак', 'Частично отгружена', 'Полностью реализована') NOT NULL DEFAULT 'Ожидает анализа',
    remaining_bottles INT UNSIGNED NOT NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (brand_id) REFERENCES water_brands(id) ON DELETE RESTRICT,
    FOREIGN KEY (treated_test_id) REFERENCES treated_water_tests(id) ON DELETE RESTRICT,
    FOREIGN KEY (bottle_type_id) REFERENCES bottle_types(id) ON DELETE RESTRICT,
    FOREIGN KEY (production_line_id) REFERENCES production_lines(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- Отгрузки (весь движение — в архиве)
CREATE TABLE shipments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    batch_id INT UNSIGNED NOT NULL,
    client_id INT UNSIGNED NOT NULL,
    shipment_date DATE NOT NULL,
    bottles_shipped INT UNSIGNED NOT NULL,
    waybill_number VARCHAR(50) NOT NULL UNIQUE,
    shipped_by VARCHAR(150) NOT NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (batch_id) REFERENCES batches(id) ON DELETE RESTRICT,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE RESTRICT,
    CHECK (bottles_shipped > 0)
) ENGINE=InnoDB;

-- Индексы для быстрого поиска в архиве (как на реальном заводе)
CREATE INDEX idx_batches_batch_number ON batches(batch_number);
CREATE INDEX idx_batches_status ON batches(status);
CREATE INDEX idx_batches_bottling_date ON batches(bottling_datetime);
CREATE INDEX idx_shipments_waybill ON shipments(waybill_number);
CREATE INDEX idx_shipments_date ON shipments(shipment_date);
CREATE INDEX idx_raw_tests_source ON raw_water_tests(source_id);
CREATE INDEX idx_treated_tests_compliance ON treated_water_tests(is_compliant);
CREATE INDEX idx_batches_brand ON batches(brand_id);











USE bottled_water_control;

-- 1. Справочники
INSERT INTO water_brands (name) VALUES
('Криница'), ('Слуцкая'), ('Здоровье'), ('Родны Край'), ('Белая Русь');

INSERT INTO water_sources (name, sanitary_conclusion_number, sanitary_conclusion_valid_until, coordinates) VALUES
('Скважина №3, Минский р-н, глубина 110 м', 'СЭЗ-2020-5587', '2027-03-15', '53.8930, 27.5675'),
('Артезианская скважина №7, Борисовский р-н', 'СЭЗ-2021-6102', '2026-11-30', '54.2178, 28.4932'),
('Источник «Зелёный Бор», Витебская обл.', 'СЭЗ-2022-7034', '2025-08-20', '55.1850, 30.2100');

INSERT INTO bottle_types (volume_l, material, description) VALUES
(0.5, 'ПЭТ', 'Бутылка 0.5 л'),
(1.5, 'ПЭТ', 'Бутылка 1.5 л'),
(5.0, 'ПЭТ', 'Канистра 5 л'),
(19.0, 'Поликарбонат', 'Канистра 19 л');

INSERT INTO production_lines (name) VALUES
('Линия розлива №1'), ('Линия розлива №2');

INSERT INTO clients (name, inn, address, contact_person) VALUES
('ОАО «Евроопт»', '100012345', 'г. Минск, пр-т Дзержинского, 3', 'Иванов А.С.'),
('ИП Петров С.В.', '301234567', 'г. Гомель, ул. Советская, 88', 'Петров С.В.'),
('ТОВ «ВодаТорг»', '202345678', 'г. Брест, ул. Московская, 45', 'Сидоренко О.П.'),
('Сеть магазинов «Копейка»', '100987654', 'г. Могилёв, пл. Славы, 5', 'Коваленко Н.А.'),
('ЧУП «Аква+»', '400123987', 'г. Гродно, ул. Ленина, 12', 'Михайлова Е.К.');

-- 2. Предварительные анализы (12 записей: 9 годных к очистке, 3 — брак)
INSERT INTO raw_water_tests (source_id, sampled_at, sampled_by, odor_rating, taste_rating, color_degrees, turbidity_emf, ph, hardness_mmol, dry_residue_mg_l, iron_mg_l, manganese_mg_l, nitrates_mg_l, fluorides_mg_l, chlorides_mg_l, sulfates_mg_l, omch_cfu_ml, coliforms_100ml, thermotolerant_coliforms_100ml, pseudomonas_250ml, yeast_mold_cfu_ml, is_approved_for_treatment, notes) VALUES
(1, '2023-03-12 08:20:00', 'Сидоров В.И.', 0, 0, 4, 0.2, 7.4, 4.2, 450, 0.11, 0.04, 15.0, 0.85, 90, 120, 40, FALSE, FALSE, FALSE, 12, TRUE, NULL),
(1, '2023-06-18 09:10:00', 'Сидоров В.И.', 1, 1, 22, 1.6, 6.9, 6.3, 850, 0.26, 0.08, 36.0, 1.0, 200, 290, 75, FALSE, FALSE, FALSE, 70, TRUE, 'Повышенная жёсткость'),
(2, '2023-09-05 07:45:00', 'Коваленко Л.М.', 0, 0, 3, 0.1, 7.8, 3.7, 400, 0.08, 0.03, 9.0, 1.1, 65, 95, 30, FALSE, FALSE, FALSE, 9, TRUE, NULL),
(2, '2023-11-20 10:30:00', 'Коваленко Л.М.', 0, 0, 5, 0.4, 8.0, 5.1, 520, 0.16, 0.05, 24.0, 1.2, 125, 185, 65, TRUE, FALSE, FALSE, 120, FALSE, 'Обнаружены колиформы — брак'),
(3, '2024-02-14 09:00:00', 'Петров А.Н.', 2, 2, 40, 3.5, 5.8, 7.5, 1300, 0.50, 0.15, 58.0, 0.4, 380, 420, 200, TRUE, TRUE, TRUE, 250, FALSE, 'Сильное загрязнение — не подлежит очистке'),
(3, '2024-05-08 08:50:00', 'Петров А.Н.', 1, 1, 18, 1.4, 7.0, 5.6, 750, 0.22, 0.07, 30.0, 0.75, 190, 270, 65, FALSE, FALSE, FALSE, 55, TRUE, 'Требуется УФ и озон'),
(1, '2024-08-22 08:15:00', 'Сидоров В.И.', 0, 0, 5, 0.3, 7.5, 4.6, 470, 0.14, 0.04, 19.0, 0.95, 95, 135, 45, FALSE, FALSE, FALSE, 18, TRUE, NULL),
(2, '2024-11-10 11:20:00', 'Коваленко Л.М.', 0, 0, 4, 0.2, 7.2, 5.3, 540, 0.17, 0.05, 26.0, 0.9, 115, 165, 55, FALSE, FALSE, FALSE, 30, TRUE, NULL),
(3, '2025-01-30 08:40:00', 'Петров А.Н.', 0, 0, 6, 0.5, 7.6, 4.9, 480, 0.15, 0.04, 21.0, 1.0, 105, 145, 48, FALSE, FALSE, FALSE, 35, TRUE, NULL),
(1, '2025-04-05 09:30:00', 'Сидоров В.И.', 1, 0, 16, 1.2, 6.8, 6.1, 820, 0.25, 0.08, 38.0, 0.65, 240, 310, 85, FALSE, FALSE, FALSE, 70, TRUE, 'Высокие нитраты — ОСМОС'),
(2, '2025-07-12 07:55:00', 'Коваленко Л.М.', 0, 0, 3, 0.1, 7.9, 4.0, 420, 0.10, 0.03, 11.0, 1.15, 75, 105, 32, FALSE, FALSE, FALSE, 14, TRUE, NULL),
(3, '2025-10-18 10:05:00', 'Петров А.Н.', 0, 0, 5, 0.3, 7.4, 5.1, 510, 0.16, 0.05, 23.0, 0.9, 110, 155, 52, FALSE, FALSE, FALSE, 29, TRUE, NULL);

-- 3. Очистка воды (только для is_approved_for_treatment = TRUE)
INSERT INTO water_treatments (raw_test_id, started_at, finished_at, volume_treated_l, treatment_type, operator, notes) VALUES
(1, '2023-03-12 09:00:00', '2023-03-12 10:30:00', 10500, '["мех. фильтрация", "УФ"]', 'Андреев К.С.', NULL),
(2, '2023-06-18 10:00:00', '2023-06-18 12:15:00', 8200, '["умягчение", "мех. фильтрация", "озон"]', 'Андреев К.С.', 'Умягчение и озонирование'),
(3, '2023-09-05 08:30:00', '2023-09-05 09:45:00', 12500, '["мех. фильтрация", "УФ"]', 'Андреев К.С.', NULL),
(6, '2024-05-08 09:30:00', '2024-05-08 12:00:00', 9200, '["уголь", "мех. фильтрация", "УФ", "озон"]', 'Андреев К.С.', 'Уголь + озон'),
(7, '2024-08-22 09:00:00', '2024-08-22 10:30:00', 11200, '["мех. фильтрация", "УФ"]', 'Андреев К.С.', NULL),
(8, '2024-11-10 12:00:00', '2024-11-10 14:15:00', 7300, '["мех. фильтрация", "озон"]', 'Андреев К.С.', NULL),
(9, '2025-01-30 09:30:00', '2025-01-30 11:00:00', 10300, '["мех. фильтрация", "УФ"]', 'Андреев К.С.', NULL),
(10, '2025-04-05 10:15:00', '2025-04-05 13:30:00', 6300, '["обратный осмос", "минерализация", "озон"]', 'Андреев К.С.', 'ОСМОС + минерализация'),
(11, '2025-07-12 08:45:00', '2025-07-12 10:15:00', 13200, '["мех. фильтрация", "УФ"]', 'Андреев К.С.', NULL),
(12, '2025-10-18 11:00:00', '2025-10-18 12:30:00', 9800, '["мех. фильтрация", "озон"]', 'Андреев К.С.', NULL);

-- 4. Контрольные анализы после очистки (все соответствуют СТБ 1575)
INSERT INTO treated_water_tests (treatment_id, tested_at, tested_by, odor, taste, transparency, color, ph, hardness_mmol, dry_residue_mg_l, iron_mg_l, nitrates_mg_l, fluorides_mg_l, omch_cfu_ml, coliforms_detected, thermotolerant_coliforms_detected, pseudomonas_detected, yeast_mold_cfu_ml, notes) VALUES
(1, '2023-03-12 11:00:00', 'Лаборант И.П.', 'Без постороннего', 'Отсутствует', 'Прозрачная', 'Не окрашена', 7.4, 4.2, 450, 0.11, 15.0, 0.85, 30, FALSE, FALSE, FALSE, 10, NULL),
(2, '2023-06-18 12:45:00', 'Лаборант И.П.', 'Без постороннего', 'Отсутствует', 'Прозрачная', 'Не окрашена', 7.2, 3.1, 490, 0.08, 18.0, 0.8, 40, FALSE, FALSE, FALSE, 18, 'Жёсткость скорректирована'),
(3, '2023-09-05 10:15:00', 'Лаборант И.П.', 'Без постороннего', 'Отсутствует', 'Прозрачная', 'Не окрашена', 7.8, 3.7, 400, 0.08, 9.0, 1.1, 25, FALSE, FALSE, FALSE, 8, NULL),
(4, '2024-05-08 12:30:00', 'Лаборант И.П.', 'Без постороннего', 'Отсутствует', 'Прозрачная', 'Не окрашена', 7.1, 4.3, 470, 0.09, 20.0, 0.8, 32, FALSE, FALSE, FALSE, 20, 'Нитраты снижены'),
(5, '2024-08-22 11:00:00', 'Лаборант И.П.', 'Без постороннего', 'Отсутствует', 'Прозрачная', 'Не окрашена', 7.5, 4.6, 470, 0.14, 19.0, 0.95, 35, FALSE, FALSE, FALSE, 15, NULL),
(6, '2024-11-10 14:45:00', 'Лаборант И.П.', 'Без постороннего', 'Отсутствует', 'Прозрачная', 'Не окрашена', 7.3, 5.1, 530, 0.16, 24.0, 0.9, 38, FALSE, FALSE, FALSE, 22, NULL),
(7, '2025-01-30 11:30:00', 'Лаборант И.П.', 'Без постороннего', 'Отсутствует', 'Прозрачная', 'Не окрашена', 7.6, 4.9, 480, 0.15, 21.0, 1.0, 30, FALSE, FALSE, FALSE, 20, NULL),
(8, '2025-04-05 14:00:00', 'Лаборант И.П.', 'Без постороннего', 'Отсутствует', 'Прозрачная', 'Не окрашена', 7.4, 2.9, 430, 0.07, 15.0, 0.7, 42, FALSE, FALSE, FALSE, 16, 'После ОСМОСа'),
(9, '2025-07-12 10:45:00', 'Лаборант И.П.', 'Без постороннего', 'Отсутствует', 'Прозрачная', 'Не окрашена', 7.9, 4.0, 420, 0.10, 11.0, 1.15, 28, FALSE, FALSE, FALSE, 12, NULL),
(10, '2025-10-18 13:00:00', 'Лаборант И.П.', 'Без постороннего', 'Отсутствует', 'Прозрачная', 'Не окрашена', 7.4, 5.1, 510, 0.16, 23.0, 0.9, 34, FALSE, FALSE, FALSE, 24, NULL);

-- 5. Партии (10 партий: разные статусы)
INSERT INTO batches (batch_number, brand_id, treated_test_id, bottling_datetime, shelf_life_months, bottle_type_id, production_line_id, total_bottles, total_liters, operator_name, status, remaining_bottles) VALUES
('W-230312-001', 1, 1, '2023-03-12 12:00:00', 12, 2, 1, 7000, 10500.00, 'Оператор Л.В.', 'Полностью реализована', 0),
('W-230618-001', 2, 2, '2023-06-18 13:30:00', 12, 1, 2, 16400, 8200.00, 'Оператор Л.В.', 'Полностью реализована', 0),
('W-230905-001', 3, 3, '2023-09-05 11:00:00', 12, 4, 1, 657, 12500.00, 'Оператор Л.В.', 'Частично отгружена', 120),
('W-240508-001', 4, 4, '2024-05-08 13:00:00', 12, 3, 2, 1840, 9200.00, 'Оператор Л.В.', 'Полностью реализована', 0),
('W-240822-001', 1, 5, '2024-08-22 12:00:00', 12, 2, 1, 7466, 11200.00, 'Оператор Л.В.', 'Частично отгружена', 420),
('W-241110-001', 2, 6, '2024-11-10 15:30:00', 12, 1, 2, 14600, 7300.00, 'Оператор Л.В.', 'Полностью реализована', 0),
('W-250130-001', 5, 7, '2025-01-30 12:30:00', 12, 4, 1, 542, 10300.00, 'Оператор Л.В.', 'Годна к реализации', 542),
('W-250405-001', 3, 8, '2025-04-05 14:30:00', 12, 2, 1, 4200, 6300.00, 'Оператор Л.В.', 'Годна к реализации', 4200),
('W-250712-001', 1, 9, '2025-07-12 11:30:00', 12, 3, 2, 2640, 13200.00, 'Оператор Л.В.', 'Годна к реализации', 2640),
('W-251018-001', 4, 10, '2025-10-18 14:00:00', 12, 1, 1, 19600, 9800.00, 'Оператор Л.В.', 'Годна к реализации', 19600);

-- 6. Отгрузки (7 записей: включая частичные)
INSERT INTO shipments (batch_id, client_id, shipment_date, bottles_shipped, waybill_number, shipped_by) VALUES
(1, 1, '2023-03-18', 7000, 'ТТН-2023-0007', 'Грузчик А.А.'),
(2, 2, '2023-07-01', 16400, 'ТТН-2023-0042', 'Грузчик А.А.'),
(3, 3, '2023-10-15', 537, 'ТТН-2023-0115', 'Грузчик А.А.'),
(4, 4, '2024-06-10', 1840, 'ТТН-2024-0033', 'Грузчик А.А.'),
(5, 1, '2024-09-20', 7046, 'ТТН-2024-0128', 'Грузчик А.А.'),
(6, 5, '2024-12-05', 14600, 'ТТН-2024-0189', 'Грузчик А.А.'),
(3, 2, '2024-02-28', 120, 'ТТН-2024-0022', 'Грузчик А.А.'); -- последняя часть партии W-230905-001