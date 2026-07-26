-- Таблица отзывов. Выполнить один раз при развёртывании:
-- панель Beget → MySQL → phpMyAdmin → вкладка SQL → вставить и выполнить.

CREATE TABLE IF NOT EXISTS reviews (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name         VARCHAR(100)  NOT NULL,
    contact      VARCHAR(150)  NOT NULL DEFAULT '',   -- не публикуется, только для связи
    body         TEXT          NOT NULL,
    status       ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    created_at   DATETIME      NOT NULL,
    moderated_at DATETIME      NULL DEFAULT NULL,
    ip           VARCHAR(45)   NOT NULL DEFAULT '',

    PRIMARY KEY (id),
    -- выборка одобренных для главной и очереди на модерацию
    KEY idx_status_moderated (status, moderated_at),
    KEY idx_status_created (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
