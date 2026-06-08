<?php
// === БАЗОВАЯ ДИАГНОСТИКА ===
error_reporting(E_ALL);
ini_set('display_errors', 1);
ob_start();

$configFile = __DIR__ . '/parser-config.php';
if (!file_exists($configFile)) {
    die('<div style="font-family: monospace; padding: 20px; background: #ffebee; color: #c62828;">❌ Файл <b>parser-config.php</b> не найден!<br>Создайте его в той же папке, что и parser-web.php.<br>Пример содержимого:<br><pre>&lt;?php return [\'parser_password\'=>\'\', \'donor_forum_url\'=>\'https://sharewood.tech\']; ?&gt;</pre></div>');
}

$config = @require $configFile;
if (!is_array($config)) {
    die('<div style="font-family: monospace; padding: 20px; background: #ffebee; color: #c62828;">❌ parser-config.php возвращает НЕ массив.<br>Проверьте синтаксис PHP (запятые, кавычки, BOM).</div>');
}

// === Основные константы ===
define('PARSER_PASSWORD', $config['parser_password'] ?? '');
define('DONOR_FORUM_URL', rtrim($config['donor_forum_url'] ?? '', '/'));
define('DONOR_USERNAME', $config['donor_username'] ?? '');
define('DONOR_COOKIES', $config['donor_cookies'] ?? '');
define('TARGET_FORUM_URL', rtrim($config['target_forum_url'] ?? '', '/'));
define('API_URL', rtrim($config['api_url'] ?? '', '/') . '/');
define('API_KEY', $config['api_key'] ?? '');
define('API_USER_ID', (int)($config['api_user_id'] ?? 0));
define('TARGET_NODE_ID', (int)($config['target_node_id'] ?? 0));
define('BUTTON_URL', $config['button_url'] ?? '');
define('USER_AGENT', $config['user_agent'] ?? 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
define('LOG_FILE', $config['log_file'] ?? __DIR__ . '/parser_log.txt');
define('PROCESSED_FILE', $config['processed_file'] ?? __DIR__ . '/processed_titles.txt');
define('TOPICS_LIMIT', (int)($config['topics_limit'] ?? 20));
define('GROUPS_ID', (int)($config['groups_id'] ?? 2));

// === Логирование ===
function logMessage($msg) {
    $timestamp = date('H:i:s');
    $logMsg = "[$timestamp] $msg";
    file_put_contents(LOG_FILE, $logMsg . "\n", FILE_APPEND | LOCK_EX);
    return $logMsg;
}

// === Сохранение куки в файл для curl ===
function initCookies() {
    if (!empty(DONOR_COOKIES)) {
        $cookiesFile = __DIR__ . '/donor_cookies.txt';
        file_put_contents($cookiesFile, DONOR_COOKIES);
        logMessage("🍪 Куки загружены из настроек");
        return true;
    }
    
    if (file_exists(__DIR__ . '/donor_cookies.txt') && filesize(__DIR__ . '/donor_cookies.txt') > 0) {
        logMessage("🍪 Куки загружены из файла donor_cookies.txt");
        return true;
    }
    
    logMessage("⚠️ Предупреждение: Куки не заданы! Парсинг может не сработать из-за Cloudflare.");
    return false;
}

// === Загрузка HTML с авторизацией ===
function getHtmlWithAuth($url) {
    logMessage("🔍 Загрузка: $url");
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT => USER_AGENT,
        CURLOPT_COOKIEFILE => __DIR__ . '/donor_cookies.txt',
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_REFERER => DONOR_FORUM_URL . '/',
        CURLOPT_ENCODING => "",
    ]);
    
    $html = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode != 200) {
        logMessage("❌ HTTP $httpCode при загрузке $url");
        return false;
    }
    
    if (!$html) {
        logMessage("❌ Не удалось получить HTML для $url");
        return false;
    }
    
    if (stripos($html, 'Checking your browser') !== false || 
        stripos($html, 'Ray ID:') !== false ||
        stripos($html, 'Access Denied') !== false ||
        stripos($html, 'Please Wait...') !== false) {
        logMessage("❌ Обнаружена блокировка Cloudflare!");
        return false;
    }
    
    return $html;
}

// === Получение списка тем из раздела (HTML) ===
function getTopicsFromSection() {
    logMessage("📥 Загрузка раздела: " . DONOR_FORUM_URL . "/forums/biznes-marketing-i-menedzhment.8/");
    
    $url = DONOR_FORUM_URL . '/forums/biznes-marketing-i-menedzhment.8/';
    $html = getHtmlWithAuth($url);
    
    if (!$html) return [];
    
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    
    $xpath = new DOMXPath($dom);
    $topics = [];
    $processedTitles = getProcessedTitles();
    
    // Ищем темы по structItem--thread
    $rows = $xpath->query('//div[contains(@class, "structItem--thread")]');
    
    logMessage("🔍 Найдено элементов structItem--thread: " . $rows->length);
    
    foreach ($rows as $row) {
        $titleNode = $xpath->query('.//div[contains(@class, "structItem-title")]//a', $row)->item(0);
        
        if (!$titleNode) {
            continue;
        }
        
        $title = trim($titleNode->textContent);
        $link = $titleNode->getAttribute('href');
        
        if (empty($title)) {
            continue;
        }
        
        if (strpos($link, 'http') !== 0) {
            $link = DONOR_FORUM_URL . $link;
        }
        
        if (in_array($title, $processedTitles)) {
            logMessage("⏭️ Пропущена (уже обработана): $title");
            continue;
        }
        
        logMessage("✅ Найдена тема: $title");
        $topics[] = ['url' => $link, 'title' => $title];
        
        if (count($topics) >= TOPICS_LIMIT * 3) {
            logMessage("⚠️ Достигнут внутренний лимит поиска: " . count($topics));
            break;
        }
    }
    
    logMessage("📋 Найдено тем для проверки: " . count($topics));
    return $topics;
}

// === Получение обработанных заголовков ===
function getProcessedTitles() {
    if (file_exists(PROCESSED_FILE)) {
        $content = file_get_contents(PROCESSED_FILE);
        return array_filter(explode("\n", $content), fn($line) => trim($line) !== '');
    }
    return [];
}

// === Отметить тему как обработанную ===
function markTitleAsProcessed($title) {
    file_put_contents(PROCESSED_FILE, trim($title) . "\n", FILE_APPEND | LOCK_EX);
}

// === Парсинг содержимого темы ===
function parseTopicContent($url) {
    logMessage("📄 Парсинг темы: $url");
    
    $html = getHtmlWithAuth($url);
    if (!$html) return false;
    
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    
    $xpath = new DOMXPath($dom);
    
    $content = [
        'title' => '',
        'description' => '',
        'groups_block' => '',
        'images' => []
    ];
    
    // 1. Заголовок темы
    $titleNode = $xpath->query('//h1[contains(@class, "p-title-value")]')->item(0);
    $content['title'] = $titleNode ? trim($titleNode->textContent) : '';
    
    // 2. Тело первого сообщения (OP - original post)
    $firstPost = $xpath->query('//article[contains(@class, "message--post") and contains(@class, "message-threadStarterPost")]')->item(0);
    
    if (!$firstPost) {
        // Пробуем найти просто первое сообщение
        $firstPost = $xpath->query('//article[contains(@class, "message--post")]')->item(0);
    }
    
    if (!$firstPost) {
        logMessage("❌ Не найдено первое сообщение темы.");
        return false;
    }
    
    // Ищем bbWrapper внутри первого сообщения
    $bbWrapper = $xpath->query('.//div[contains(@class, "bbWrapper")]', $firstPost)->item(0);
    
    if (!$bbWrapper) {
        logMessage("❌ Не найдено bbWrapper в сообщении.");
        return false;
    }
    
    // 3. Извлекаем весь HTML контента
    $descHtml = '';
    foreach ($bbWrapper->childNodes as $child) {
        $descHtml .= $dom->saveHTML($child);
    }
    
    // 4. Удаляем ненужные элементы
    $descHtml = preg_replace('/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/mi', '', $descHtml);
    $descHtml = preg_replace('/<style\b[^<]*(?:(?!<\/style>)<[^<]*)*<\/style>/mi', '', $descHtml);
    $descHtml = preg_replace('/<svg[^>]*>.*?<\/svg>/si', '', $descHtml);
    $descHtml = preg_replace('/<iframe[^>]*>.*?<\/iframe>/si', '', $descHtml);
    $descHtml = preg_replace('/<img[^>]*src\s*=\s*["\']data:image\/svg\+xml[^"\']*["\'][^>]*>/i', '', $descHtml);
    
    // 5. Ищем ОБЛАЧНЫЕ ссылки
    $allLinks = $xpath->query('.//a[@href]', $bbWrapper);
    $cloudLinks = [];
    foreach ($allLinks as $lnk) {
        $href = $lnk->getAttribute('href');
        $proxyHref = $lnk->getAttribute('data-proxy-href');
        
        // Проверяем оба атрибута
        $checkHref = $proxyHref ?: $href;
        
        if (preg_match('/(cloud\.mail\.ru|drive\.google\.com|disk\.yandex\.ru|yadi\.sk|mega\.nz|dropbox\.com|mediafire\.com|gofile\.io|turbobit\.net|hitfile\.net|katfile\.com|rapidgator\.net|nitroflare\.com|1fichier\.com|ddl\.to|uptobox\.com|depfile\.com|solidfiles\.com|wetransfer\.com|my-files\.ru|files\.dscloud\.me|pixeldrain\.com|krakenfiles\.com)/i', $checkHref)) {
            if (!in_array($checkHref, $cloudLinks)) {
                $cloudLinks[] = $checkHref;
                logMessage("☁️ Облачная ссылка: $checkHref");
            }
        }
    }
    
    // 6. Ищем скрытые блоки [GROUPS]
    $hideBlocks = $xpath->query('.//div[contains(@class, "bbCodeBlock--hide")]', $bbWrapper);
    foreach ($hideBlocks as $block) {
        $blockText = trim($block->textContent);
        
        // Проверяем, есть ли там облачные ссылки
        if (preg_match('/(cloud\.mail\.ru|drive\.google\.com|disk\.yandex\.ru)/i', $blockText)) {
            $blockHtml = $dom->saveHTML($block);
            if (preg_match('/\[GROUPS=\d+\].*?\[\/GROUPS\]/is', $blockHtml, $matches)) {
                $content['groups_block'] = trim($matches[0]);
                logMessage("✅ Найден блок [GROUPS]");
            }
        }
    }
    
    // 7. Формируем [GROUPS] из найденных облачных ссылок, если не найден в скрытом блоке
    if (empty($content['groups_block']) && !empty($cloudLinks)) {
        $content['groups_block'] = "[GROUPS=" . GROUPS_ID . "]\n" . implode("\n", $cloudLinks) . "\n[/GROUPS]";
        logMessage("✅ Сформирован [GROUPS] из " . count($cloudLinks) . " облачных ссылок");
    }
    
    // 8. Извлекаем изображения
    $images = $xpath->query('.//img[contains(@class, "bbImage") and not(contains(@src, "data:image/svg"))]', $bbWrapper);
    foreach ($images as $img) {
        $src = $img->getAttribute('data-src') ?: $img->getAttribute('src');
        if ($src && !in_array($src, $content['images'])) {
            $content['images'][] = $src;
            logMessage("🖼️ Найдено изображение: " . basename($src));
        }
    }
    
    // 9. Очищаем HTML от лишних элементов для описания
    $descHtml = preg_replace('/Скрытый контент для авторизованных пользователей\.\s*/i', '', $descHtml);
    $descHtml = preg_replace('/Скрытый контент\s*\(?для авторизованных пользователей\)?\.?\s*/i', '', $descHtml);
    $descHtml = preg_replace('/Для просмотра необходимо нажать \'Мне нравится\'/i', '', $descHtml);
    $descHtml = preg_replace('/^\s*<br>\s*$/mi', '', $descHtml);
    $descHtml = trim(preg_replace('/\s+/', ' ', $descHtml));
    
    $content['description'] = $descHtml;
    
    if (empty($content['groups_block'])) {
        logMessage("⚠️ Облачные ссылки не найдены");
    }
    
    return $content;
}

// === API запрос ===
function apiRequest($endpoint, $data) {
    $url = API_URL . ltrim($endpoint, '/');
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($data),
        CURLOPT_HTTPHEADER => [
            'XF-Api-Key: ' . API_KEY,
            'XF-Api-User: ' . API_USER_ID,
            'Content-Type: application/x-www-form-urlencoded',
        ],
        CURLOPT_TIMEOUT => 30,
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode >= 400) {
        logMessage("❌ API Error ($httpCode): " . substr($response, 0, 300));
        return false;
    }
    
    return json_decode($response, true);
}

// === Создание темы ===
function createThread($title, $message, $nodeId) {
    $data = [
        'node_id' => $nodeId,
        'title' => $title,
        'message' => $message,
        'prefix_id' => 0,
    ];
    
    $result = apiRequest('threads', $data);
    return $result && isset($result['thread']) ? $result['thread']['thread_id'] : false;
}

// === Проверка дубликата по заголовку ===
function checkDuplicateTitle($title) {
    $searchUrl = API_URL . 'search';
    $searchData = [
        'keywords' => $title,
        'nodes' => TARGET_NODE_ID,
        'type' => 'threads',
    ];
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $searchUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($searchData),
        CURLOPT_HTTPHEADER => [
            'XF-Api-Key: ' . API_KEY,
            'XF-Api-User: ' . API_USER_ID,
            'Content-Type: application/x-www-form-urlencoded',
        ],
        CURLOPT_TIMEOUT => 20,
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode == 200) {
        $results = json_decode($response, true);
        if ($results && isset($results['results'])) {
            foreach ($results['results'] as $r) {
                if (isset($r['title']) && trim($r['title']) === trim($title)) {
                    logMessage("⚠️ Дубликат найден: '$title'");
                    return true;
                }
            }
        }
    }
    
    return false;
}

// === Форматирование в BB-код ===
function formatBBCode($parsed) {
    $output = '';
    
    // Заголовок
    if (!empty($parsed['title'])) {
        $output .= "[B]" . htmlspecialchars($parsed['title']) . "[/B]\n\n";
    }
    
    // Изображения (если есть)
    if (!empty($parsed['images'])) {
        foreach ($parsed['images'] as $img) {
            $output .= "[IMG]" . htmlspecialchars($img) . "[/IMG]\n";
        }
        $output .= "\n";
    }
    
    // Описание — конвертируем HTML в BB-код
    if (!empty($parsed['description'])) {
        $desc = $parsed['description'];
        
        // Форматирование
        $desc = preg_replace('/<b\b[^>]*>(.*?)<\/b>/si', '[B]$1[/B]', $desc);
        $desc = preg_replace('/<strong\b[^>]*>(.*?)<\/strong>/si', '[B]$1[/B]', $desc);
        $desc = preg_replace('/<i\b[^>]*>(.*?)<\/i>/si', '[I]$1[/I]', $desc);
        $desc = preg_replace('/<em\b[^>]*>(.*?)<\/em>/si', '[I]$1[/I]', $desc);
        $desc = preg_replace('/<u\b[^>]*>(.*?)<\/u>/si', '[U]$1[/U]', $desc);
        $desc = preg_replace('/<(s|strike|del)\b[^>]*>(.*?)<\/\1>/si', '[S]$2[/S]', $desc);
        
        // ССЫЛКИ: удаляем тег <a>, оставляем только текст
        $desc = preg_replace_callback('/<a\s+[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/si', function($m) {
            return strip_tags($m[2]);
        }, $desc);
        
        // Картинки УДАЛЯЕМ (уже добавлены выше)
        $desc = preg_replace('/<img[^>]*>/i', '', $desc);
        
        // Переводы строк
        $desc = preg_replace('/<br\s*\/?>/i', "\n", $desc);
        $desc = preg_replace('/<\/(p|div)>/i', "\n", $desc);
        
        // Списки
        $desc = preg_replace('/<ul[^>]*>/i', '[LIST]', $desc);
        $desc = preg_replace('/<ol[^>]*>/i', '[LIST=1]', $desc);
        $desc = preg_replace('/<\/[uo]l>/i', '[/LIST]', $desc);
        $desc = preg_replace('/<li[^>]*>(.*?)<\/li>/si', '[*]$1', $desc);
        
        // Удаляем все оставшиеся HTML-теги
        $desc = strip_tags($desc);
        
        // Декодируем HTML-сущности
        $desc = html_entity_decode($desc, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        // Убираем лишние пустые строки
        $desc = preg_replace('/\n{3,}/', "\n\n", $desc);
        $desc = trim($desc);
        
        if (!empty($desc)) {
            $output .= $desc . "\n\n";
        }
    }
    
    // Скачать + GROUPS
    if (!empty($parsed['groups_block'])) {
        $output .= "Скачать:\n" . $parsed['groups_block'];
    }
    
    return $output;
}

// === Обработка действий ===
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    if ($_GET['action'] === 'run_parser') {
        set_time_limit(600);
        initCookies();
        
        $output = [];
        $output[] = logMessage("=== 🚀 Запуск парсера ===");
        
        $topics = getTopicsFromSection();
        
        if (empty($topics)) {
            $output[] = logMessage("ℹ️ Тем не найдено (или достигнут лимит)");
            echo json_encode(['success' => true, 'logs' => $output, 'count' => 0, 'total' => 0]);
            exit;
        }
        
        $successCount = 0;
        $processedThisRun = 0;
        
        foreach ($topics as $index => $topic) {
            if ($processedThisRun >= TOPICS_LIMIT) {
                logMessage("✅ Достигнут лимит публикаций: " . TOPICS_LIMIT);
                break;
            }
            
            $output[] = logMessage("🔄 Обработка темы " . ($index + 1) . " / " . count($topics) . ": " . $topic['title']);
            
            // --- ШАГ 1: Парсинг ---
            $content = parseTopicContent($topic['url']);
            if (!$content) {
                $output[] = logMessage("❌ Не удалось спарсить тему");
                $delay = rand(15, 25);
                sleep($delay);
                continue;
            }
            
            // --- ШАГ 2: Проверка дубликата ---
            if (checkDuplicateTitle($topic['title'])) {
                $output[] = logMessage("⚠️ Дубликат заголовка. Пропуск.");
                markTitleAsProcessed($topic['title']);
                $delay = rand(10, 20);
                sleep($delay);
                continue;
            }
            
            // --- ШАГ 3: Форматирование ---
            $bbCode = formatBBCode($content);
            if (empty(trim($bbCode))) {
                $output[] = logMessage("❌ Пустой BB-код. Пропуск.");
                $delay = rand(10, 20);
                sleep($delay);
                continue;
            }
            
            // --- ШАГ 4: Публикация ---
            logMessage("📤 Публикация: " . $topic['title']);
            $threadId = createThread($topic['title'], $bbCode, TARGET_NODE_ID);
            
            if ($threadId) {
                $output[] = logMessage("✅ Тема создана (ID: $threadId)");
                markTitleAsProcessed($topic['title']);
                $successCount++;
                $processedThisRun++;
            } else {
                $output[] = logMessage("❌ Ошибка создания темы");
            }
            
            // --- ШАГ 5: Задержка ---
            $delay = rand(20, 40);
            logMessage("⏳ Пауза: $delay сек...");
            sleep($delay);
        }
        
        $output[] = logMessage("=== 🏁 Готово! ===");
        $output[] = logMessage("📊 Успешно: $successCount / " . count($topics));
        
        echo json_encode(['success' => true, 'logs' => $output, 'count' => $successCount, 'total' => count($topics)]);
        exit;
    }
    
    if ($_GET['action'] === 'reset_processed') {
        @unlink(PROCESSED_FILE);
        logMessage("🗑️ Список обработанных тем сброшен.");
        echo json_encode(['success' => true, 'message' => 'Сброшено']);
        exit;
    }
}

// === Вход в парсер ===
session_start();
if (!isset($_SESSION['parser_logged_in']) || $_SESSION['parser_logged_in'] !== true) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
        if (empty(PARSER_PASSWORD) || $_POST['password'] === PARSER_PASSWORD) {
            $_SESSION['parser_logged_in'] = true;
        } else {
            $error = 'Неверный пароль!';
        }
    }
    
    if (!isset($_SESSION['parser_logged_in']) || $_SESSION['parser_logged_in'] !== true) {
        ?>
        <!DOCTYPE html>
        <html lang="ru">
        <head>
            <meta charset="UTF-8">
            <title>Парсер тем</title>
            <style>
                body { font-family: Arial; margin: 0; padding: 20px; background: #f5f5f5; }
                .login-box { max-width: 500px; margin: 100px auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
                h1 { text-align: center; color: #333; }
                .error { color: red; background: #ffebee; padding: 10px; border-radius: 4px; margin: 10px 0; }
                input[type="password"] { width: 100%; padding: 12px; margin: 10px 0; border: 1px solid #ccc; border-radius: 4px; }
                button { width: 100%; padding: 12px; background: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer; }
                button:hover { background: #0056b3; }
                .info { font-size: 12px; color: #666; margin-top: 15px; }
            </style>
        </head>
        <body>
            <div class="login-box">
                <h1>🟢 Парсер тем</h1>
                <?php if (isset($error)): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
                <form method="POST">
                    <label>Пароль доступа:</label>
                    <input type="password" name="password" required autofocus>
                    <button type="submit">Войти</button>
                </form>
                <div class="info">
                    ⚠️ Перед запуском:<br>
                    1. Убедитесь, что куки заданы в parser-config.php<br>
                    2. Проверьте доступ к разделу форума<br>
                </div>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
}

// === HTML интерфейс парсера ===
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Парсер тем</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: linear-gradient(135deg, #6a11cb 0%, #2575fc 100%); min-height: 100vh; padding: 20px; }
        .container { max-width: 800px; margin: 0 auto; background: white; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.2); overflow: hidden; position: relative; }
        .header { background: #1e7e34; color: white; padding: 20px; text-align: center; }
        .header h1 { margin: 0; font-size: 28px; }
        .header p { opacity: 0.9; margin-top: 5px; }
        .controls { padding: 20px; display: flex; flex-wrap: wrap; gap: 12px; }
        button { padding: 12px 24px; border: none; border-radius: 6px; font-weight: 600; cursor: pointer; transition: all 0.3s; }
        .btn-run { background: #2e7d32; color: white; }
        .btn-run:hover { background: #1b5e20; }
        .btn-clear, .btn-refresh { background: #757575; color: white; }
        .btn-clear:hover, .btn-refresh:hover { background: #616161; }
        .btn-reset { background: #ff6b35; color: white; }
        .btn-reset:hover { background: #e65100; }
        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; padding: 20px; background: #f8f9fa; }
        .stat-card { background: white; padding: 20px; border-radius: 8px; text-align: center; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        .stat-value { font-size: 28px; font-weight: bold; color: #1e7e34; margin: 10px 0; }
        .stat-label { color: #757575; font-size: 14px; }
        .log-container { padding: 20px; }
        .log-title { font-weight: bold; margin-bottom: 10px; color: #333; }
        .log-output { background: #121212; color: #e0e0e0; font-family: 'Courier New', monospace; padding: 15px; border-radius: 8px; white-space: pre-wrap; word-break: break-all; height: 400px; overflow-y: auto; }
        .footer { text-align: center; padding: 15px; color: #fff; font-size: 12px; opacity: 0.8; background: rgba(0,0,0,0.1); }
        .nav-buttons { position: absolute; top: 20px; right: 20px; display: flex; gap: 10px; z-index: 10; }
        .nav-btn { background: rgba(255,255,255,0.2); color: white; text-decoration: none; padding: 8px 16px; border-radius: 6px; font-size: 14px; display: inline-block; }
        .nav-btn:hover { background: rgba(255,255,255,0.3); }
    </style>
</head>
<body>
    <div class="container">
        <div class="nav-buttons">
            <a href="settings.php" class="nav-btn">⚙️ Настройки</a>
            <a href="?logout=1" class="nav-btn" onclick="return confirm('Выйти из парсера?')">↪ Выйти</a>
        </div>
        
        <div class="header">
            <h1>Парсер тем</h1>
            <p>Автоматический парсинг и публикация</p>
        </div>
        
        <div class="controls">
            <button id="btnRun" class="btn-run">▶ ЗАПУСТИТЬ ПАРСИНГ</button>
            <button id="btnClearLog" class="btn-clear">🗑 ОЧИСТИТЬ ЛОГ</button>
            <button id="btnRefreshLog" class="btn-refresh">🔄 ОБНОВИТЬ ЛОГ</button>
            <button id="btnResetProcessed" class="btn-reset">♻ СБРОСИТЬ ОБРАБОТАННЫЕ</button>
        </div>
        
        <div class="stats">
            <div class="stat-card">
                <div class="stat-label">ВСЕГО ТЕМ</div>
                <div class="stat-value" id="totalTopics">0</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">СОЗДАНО</div>
                <div class="stat-value" id="createdCount">0</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">ПОСЛЕДНИЙ ЗАПУСК</div>
                <div class="stat-value" id="lastRun"><?= date('d.m.Y, H:i:s') ?></div>
            </div>
        </div>
        
        <div class="log-container">
            <div class="log-title">Лог операций:</div>
            <div class="log-output" id="logOutput">Готов к работе. Нажмите «ЗАПУСТИТЬ ПАРСИНГ».</div>
        </div>
        
        <div class="footer">
            © 2026 | Sharewood.tech Parser
        </div>
    </div>

    <script>
        const btnRun = document.getElementById('btnRun');
        const logOutput = document.getElementById('logOutput');
        const totalTopicsEl = document.getElementById('totalTopics');
        const createdCountEl = document.getElementById('createdCount');
        const lastRunEl = document.getElementById('lastRun');
        
        function appendLog(msg) {
            logOutput.textContent += msg + '\n';
            logOutput.scrollTop = logOutput.scrollHeight;
        }
        
        btnRun.addEventListener('click', () => {
            btnRun.disabled = true;
            btnRun.textContent = '⏳ Работает...';
            appendLog('=== Запуск парсера ===');
            
            fetch('?action=run_parser')
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        appendLog(`✅ Готово! Создано: ${data.count} из ${data.total}`);
                        totalTopicsEl.textContent = data.total;
                        createdCountEl.textContent = data.count;
                        lastRunEl.textContent = new Date().toLocaleString('ru-RU', {
                            day: '2-digit', month: '2-digit', year: 'numeric',
                            hour: '2-digit', minute: '2-digit', second: '2-digit'
                        });
                    } else {
                        appendLog(`❌ Ошибка: ${data.error || 'Неизвестная ошибка'}`);
                    }
                    btnRun.disabled = false;
                    btnRun.textContent = '▶ ЗАПУСТИТЬ ПАРСИНГ';
                })
                .catch(err => {
                    appendLog(`❌ Сетевая ошибка: ${err.message}`);
                    btnRun.disabled = false;
                    btnRun.textContent = '▶ ЗАПУСТИТЬ ПАРСИНГ';
                });
        });
        
        document.getElementById('btnClearLog').addEventListener('click', () => {
            logOutput.textContent = 'Лог очищен.';
        });
        
        document.getElementById('btnRefreshLog').addEventListener('click', () => {
            appendLog('Обновление лога...');
        });
        
        document.getElementById('btnResetProcessed').addEventListener('click', () => {
            if (confirm('Сбросить список обработанных тем?')) {
                fetch('?action=reset_processed')
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            appendLog('🗑 Список обработанных тем сброшен.');
                        }
                    });
            }
        });
    </script>
</body>
</html>
<?php
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}
?>