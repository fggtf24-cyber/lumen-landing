-- Таблица кейсов («Наши работы»). Выполнить один раз, после schema.sql:
-- панель Beget → MySQL → phpMyAdmin → вкладка SQL → вставить и выполнить.

CREATE TABLE IF NOT EXISTS cases (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title        VARCHAR(120)  NOT NULL,
    tag          VARCHAR(40)   NOT NULL DEFAULT '',   -- «Лендинг», «Корпоративный сайт»…
    body         TEXT          NOT NULL,              -- описание под заголовком
    url          VARCHAR(255)  NOT NULL DEFAULT '',   -- ссылка на живой сайт, может быть пустой
    link_label   VARCHAR(120)  NOT NULL DEFAULT '',   -- подпись ссылки, напр. «shorttermtherapy.ru»
    image        VARCHAR(120)  NOT NULL DEFAULT '',   -- ТОЛЬКО имя файла, папка всегда assets/cases/
    image_webp   VARCHAR(120)  NOT NULL DEFAULT '',   -- webp-версия того же снимка, если получилась
    image_alt    VARCHAR(255)  NOT NULL DEFAULT '',   -- описание картинки для незрячих и поиска
    metric_value VARCHAR(40)   NOT NULL DEFAULT '',   -- «−88%», «×3», «2 недели»
    metric_label VARCHAR(60)   NOT NULL DEFAULT '',   -- «вес изображений»
    position     INT           NOT NULL DEFAULT 0,    -- порядок на сайте, меньше — выше
    published    TINYINT(1)    NOT NULL DEFAULT 1,
    created_at   DATETIME      NOT NULL,

    PRIMARY KEY (id),
    -- выборка опубликованных для главной
    KEY idx_published_position (published, position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Первый кейс — тот, что раньше был вшит в index.php.
-- Его картинки лежат в репозитории по адресу assets/cases/.
-- id указан явно, а ON DUPLICATE KEY ничего не меняет: файл можно выполнять
-- повторно, дубля не будет и ваши правки этого кейса не затрутся.
INSERT INTO cases (id, title, tag, body, url, link_label, image, image_webp, image_alt,
                   metric_value, metric_label, position, published, created_at)
VALUES (1, 'Терапевтическая группа', 'Лендинг',
        'Набор в закрытую группу: программа, формат, ведущие. Форма заявки с отправкой на почту, антиспам и сжатые фото — сайт грузится быстро.',
        'https://shorttermtherapy.ru/', 'shorttermtherapy.ru',
        'case-therapy.jpg', 'case-therapy.webp',
        'Лендинг терапевтической группы: первый экран с фотографией ведущих',
        '−88%', 'вес изображений', 0, 1, NOW())
ON DUPLICATE KEY UPDATE id = id;
