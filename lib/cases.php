<?php
/**
 * Кейсы: выборка для главной и работа с картинками.
 *
 * Картинки загружает администратор через /admin/cases.php. Файл от посетителя
 * сюда попасть не может, но правила те же, что для чужого файла: проверяем
 * настоящий тип, а не расширение, и пересохраняем средствами GD — так внутрь
 * не проедет ни PHP-код в EXIF, ни «картинка», которая на деле скрипт.
 */

declare(strict_types=1);

/** Папка с картинками кейсов относительно корня сайта. */
const CASES_IMAGE_DIR = 'assets/cases';

/** Больше 6 МБ на кейс не нужно, а хостинг такое всё равно обрежет. */
const CASES_MAX_UPLOAD = 6291456;

/** Шире 1600 px карточке незачем — только вес страницы. */
const CASES_MAX_WIDTH = 1600;

class CaseImageException extends RuntimeException {}

/** Опубликованные кейсы для главной, в заданном администратором порядке. */
function casesPublished(PDO $pdo): array
{
    return $pdo->query(
        'SELECT title, tag, body, url, link_label, image, image_webp, image_alt,
                metric_value, metric_label
         FROM cases WHERE published = 1
         ORDER BY position ASC, id ASC
         LIMIT 30'
    )->fetchAll();
}

/** Все кейсы для админки, включая скрытые. */
function casesAll(PDO $pdo): array
{
    return $pdo->query('SELECT * FROM cases ORDER BY position ASC, id ASC')->fetchAll();
}

/**
 * Метрика вида «−88%» разбирается на части, чтобы число можно было
 * анимировать на главной так же, как в вёрстке до переезда в базу.
 *
 * @return array{0:string,1:?int,2:string} префикс, число, суффикс
 */
function caseMetricParts(string $value): array
{
    if (preg_match('/^(\D*)(\d+)(.*)$/u', $value, $m)) {
        return [$m[1], (int) $m[2], $m[3]];
    }

    return [$value, null, ''];
}

/** Путь к папке с картинками на диске. */
function casesImageDir(): string
{
    return dirname(__DIR__) . '/' . CASES_IMAGE_DIR;
}

/**
 * Принимает файл из формы и кладёт в assets/cases/ пережатую копию.
 *
 * @param array $file элемент $_FILES
 * @return array{image:string,image_webp:string} имена файлов (без папки)
 * @throws CaseImageException с текстом, который можно показать администратору
 */
function caseStoreImage(array $file): array
{
    $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
        throw new CaseImageException('Файл слишком большой — хостинг не принял его целиком.');
    }
    if ($error !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
        throw new CaseImageException('Файл не загрузился, попробуйте ещё раз.');
    }
    if (($file['size'] ?? 0) > CASES_MAX_UPLOAD) {
        throw new CaseImageException('Картинка тяжелее 6 МБ. Уменьшите её и попробуйте снова.');
    }

    $tmp  = $file['tmp_name'];
    $info = @getimagesize($tmp);
    if ($info === false) {
        throw new CaseImageException('Это не изображение. Нужен JPEG, PNG или WebP.');
    }

    $allowed = [IMAGETYPE_JPEG => 'imagecreatefromjpeg', IMAGETYPE_PNG => 'imagecreatefrompng'];
    if (defined('IMAGETYPE_WEBP')) {
        $allowed[IMAGETYPE_WEBP] = 'imagecreatefromwebp';
    }

    $type = $info[2];
    if (!isset($allowed[$type])) {
        throw new CaseImageException('Поддерживаются только JPEG, PNG и WebP.');
    }

    $dir = casesImageDir();
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new CaseImageException('Папка assets/cases недоступна для записи (нужен chmod 775).');
    }
    if (!is_writable($dir)) {
        throw new CaseImageException('Папка assets/cases недоступна для записи (нужен chmod 775).');
    }

    $name  = 'case-' . date('Ymd') . '-' . bin2hex(random_bytes(6));
    $loader = $allowed[$type];

    // Без GD пересохранить нельзя — тогда кладём проверенный файл как есть.
    if (!function_exists($loader) || !function_exists('imagejpeg')) {
        $ext  = $type === IMAGETYPE_PNG ? 'png' : ($type === IMAGETYPE_JPEG ? 'jpg' : 'webp');
        $dest = "$dir/$name.$ext";
        if (!@move_uploaded_file($tmp, $dest)) {
            throw new CaseImageException('Не удалось сохранить файл в assets/cases.');
        }
        @chmod($dest, 0644);

        return ['image' => "$name.$ext", 'image_webp' => ''];
    }

    $img = @$loader($tmp);
    if (!$img) {
        throw new CaseImageException('Не удалось прочитать изображение — возможно, файл повреждён.');
    }

    $img = caseResize($img);

    // Прозрачность нам не нужна: карточка всегда на светлой бумаге.
    if ($type === IMAGETYPE_PNG || $type === (defined('IMAGETYPE_WEBP') ? IMAGETYPE_WEBP : -1)) {
        $flat = imagecreatetruecolor(imagesx($img), imagesy($img));
        imagefill($flat, 0, 0, imagecolorallocate($flat, 244, 237, 221)); // --paper-2
        imagecopy($flat, $img, 0, 0, 0, 0, imagesx($img), imagesy($img));
        imagedestroy($img);
        $img = $flat;
    }

    $jpeg = "$dir/$name.jpg";
    if (!imagejpeg($img, $jpeg, 82)) {
        imagedestroy($img);
        throw new CaseImageException('Не удалось сохранить файл в assets/cases.');
    }
    @chmod($jpeg, 0644);

    // WebP — бонус: если GD его не умеет, карточка обойдётся одним JPEG.
    $webp = '';
    if (function_exists('imagewebp') && @imagewebp($img, "$dir/$name.webp", 80)) {
        @chmod("$dir/$name.webp", 0644);
        $webp = "$name.webp";
    }

    imagedestroy($img);

    return ['image' => "$name.jpg", 'image_webp' => $webp];
}

/**
 * Уменьшает картинку до разумной ширины. Маленькие не трогаем.
 * Без типа в сигнатуре: в PHP 7.4 GD отдаёт resource, в 8.0+ — объект GdImage.
 */
function caseResize($img)
{
    $w = imagesx($img);
    $h = imagesy($img);
    if ($w <= CASES_MAX_WIDTH) {
        return $img;
    }

    $newH   = (int) round($h * CASES_MAX_WIDTH / $w);
    $scaled = imagecreatetruecolor(CASES_MAX_WIDTH, $newH);
    imagecopyresampled($scaled, $img, 0, 0, 0, 0, CASES_MAX_WIDTH, $newH, $w, $h);
    imagedestroy($img);

    return $scaled;
}

/**
 * Удаляет файлы картинок кейса. Имя из базы всегда прогоняем через basename:
 * даже если в строку кто-то подложит «../», за пределы папки это не выйдет.
 */
function caseDeleteImages(array $row): void
{
    $dir = casesImageDir();
    foreach (['image', 'image_webp'] as $key) {
        $name = (string) ($row[$key] ?? '');
        if ($name === '') {
            continue;
        }
        $path = $dir . '/' . basename($name);
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

/** Публичный путь к картинке кейса или пустая строка, если её нет. */
function caseImageUrl(?string $name): string
{
    $name = basename((string) $name);

    return $name === '' || $name === '.' ? '' : CASES_IMAGE_DIR . '/' . $name;
}
