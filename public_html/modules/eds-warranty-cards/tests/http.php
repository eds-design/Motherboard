<?php
// Full controller/security smoke test on a disposable server, never the live config.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$socket = getenv('EDS_WARRANTY_CARDS_TEST_SOCKET');
if (!$socket || !str_starts_with($socket, '/tmp/') || !is_file(dirname($socket) . '/isolated-test-server')) {
    throw new RuntimeException('A marked isolated MariaDB socket in /tmp is required.');
}
$publicRoot = dirname(__DIR__, 3);
$fixture = '/tmp/eds-warranty-cards-http-' . bin2hex(random_bytes(8));
mkdir($fixture, 0700);
mkdir($fixture . '/sessions', 0700);
foreach (['core', 'controllers', 'models', 'views', 'lang', 'modules', 'vendors', 'assets'] as $directory) {
    symlink($publicRoot . '/' . $directory, $fixture . '/' . $directory);
}
copy($publicRoot . '/index.php', $fixture . '/index.php');
copy($publicRoot . '/version.php', $fixture . '/version.php');
copy($publicRoot . '/config.sample.php', $fixture . '/config.php');
putenv('DB_HOST=localhost'); putenv('DB_NAME=eds_warranty_cards_http_test');
putenv('DB_USER=root'); putenv('DB_PASS='); putenv('FORCE_HTTPS=false');
putenv('APP_ENCRYPTION_KEY=isolated-test-key-for-warranty-card-http-checks');
putenv('APP_TIMEZONE=Europe/Sofia');
putenv('APP_DEBUG=1');
$admin = new PDO('mysql:unix_socket=' . $socket, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$admin->exec('DROP DATABASE IF EXISTS eds_warranty_cards_http_test');
$admin->exec('CREATE DATABASE eds_warranty_cards_http_test');
unset($admin);
$portSocket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if (!$portSocket) { throw new RuntimeException($error); }
$address = stream_socket_get_name($portSocket, false);
fclose($portSocket);
$base = 'http://' . $address;
putenv('BASE_URL=' . $base);
chdir($fixture);
require 'config.php';
foreach (['I18n', 'Hooks', 'ModuleLoader', 'Database', 'Schema', 'Model', 'Controller', 'Logger'] as $core) {
    require_once 'core/' . $core . '.php';
}
require 'models/Settings.php';
require 'controllers/InstallController.php';
$database = new Database();
if ($database->connect()->query('SELECT @@socket')->fetchColumn() !== $socket) {
    throw new RuntimeException('Database connection must use the isolated test socket.');
}
$installer = new InstallController();
(new ReflectionMethod($installer, 'createTables'))->invoke($installer);
(new ReflectionMethod($installer, 'createAdminAndSettings'))->invoke($installer, 'test-admin', 'admin@example.test', 'Test-Password-123');
Schema::ensure($database);
Schema::markCurrent($database);
$pdo = $database->connect();
$settings = new Settings($database);
$settings->setSetting('enabled_modules', '["warranty","eds-warranty-cards"]');
$settings->setSetting('language', 'bg-bg');
foreach ([
    'company_name' => 'HTTP Fallback Service',
    'company_address' => 'HTTP Fallback Address',
    'company_phone' => '+359 2 111 111',
    'company_email' => 'fallback@example.test',
    'company_website' => 'https://fallback.example.test',
    'company_logo_url' => '/fallback-logo.svg',
] as $key => $value) {
    $settings->setSetting($key, $value);
}
$stmt = $pdo->prepare('INSERT INTO users (username,email,password,user_group,is_active,created_at) VALUES (?,?,?,?,1,NOW())');
foreach (['Technician', 'Limited'] as $role) {
    $stmt->execute(['test-' . strtolower($role), strtolower($role) . '@example.test', password_hash('Test-Password-123', PASSWORD_DEFAULT), $role]);
}
$pdo->exec("INSERT INTO customers (name,phone,created_at) VALUES ('Test Customer','1234567',NOW())");
$pdo->exec("INSERT INTO work_orders (work_order_number,customer_id,computer,description,created_by,created_at) VALUES ('WO-TEST',1,'PC','Problem',1,NOW()),('WO-LEGACY',1,'Legacy PC','Legacy problem',1,NOW()),('WO-DELETE',1,'Delete test PC','Delete test problem',1,NOW())");
$pdo->exec("INSERT INTO work_orders (id,work_order_number,customer_id,computer,description,created_by,created_at) VALUES (100,'WO-OVER-LIMIT',1,'Large inventory PC','Large inventory problem',1,NOW())");
$pdo->exec('CREATE TABLE work_order_products (id INT PRIMARY KEY, work_order_id INT NOT NULL, product_name VARCHAR(255) NOT NULL, quantity INT NOT NULL) ENGINE=InnoDB');
$pdo->exec("INSERT INTO work_order_products VALUES (501,1,'Смяна на хард диск',1),(502,1,'SSD 500 GB',2)");
// Real migration from stage 1 must preserve the existing formatted counter.
$pdo->exec('CREATE TABLE eds_warranty_cards_counter (id TINYINT UNSIGNED PRIMARY KEY, next_number LONGTEXT CHARACTER SET ascii COLLATE ascii_bin NOT NULL, schema_version INT UNSIGNED NOT NULL, updated_at DATETIME NOT NULL) ENGINE=InnoDB');
$pdo->exec("INSERT INTO eds_warranty_cards_counter VALUES (1,'00004',1,NOW())");
$log = $fixture . '/server.log';
$process = proc_open([PHP_BINARY, '-d', 'pdo_mysql.default_socket=' . $socket, '-d', 'session.save_path=' . $fixture . '/sessions', '-S', $address, 'index.php'], [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $fixture);
if (!is_resource($process)) { throw new RuntimeException('HTTP server failed to start.'); }
fclose($pipes[0]);
$checks = 0;
function httpCheck(bool $condition, string $label): void {
    global $checks;
    if (!$condition) { throw new RuntimeException('FAILED: ' . $label); }
    $checks++;
}
function request(string $path, string $user = '', ?array $post = null): array {
    global $base, $fixture;
    $curl = curl_init($base . $path);
    $headers = [];
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_PROXY => '',
        CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers): int {
            if (str_contains($line, ':')) {
                [$key, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($key))] = trim($value);
            }
            return strlen($line);
        },
    ]);
    if ($user !== '') {
        curl_setopt($curl, CURLOPT_COOKIEJAR, $fixture . '/' . $user . '.cookies');
        curl_setopt($curl, CURLOPT_COOKIEFILE, $fixture . '/' . $user . '.cookies');
    }
    if ($post !== null) { curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $body = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    if ($body === false) { throw new RuntimeException($error); }
    return ['status' => $status, 'headers' => $headers, 'body' => $body];
}
function hiddenFields(string $html): array {
    $dom = new DOMDocument(); @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    $fields = [];
    foreach ((new DOMXPath($dom))->query('//input[@type="hidden" and @name]') as $input) {
        $fields[$input->getAttribute('name')] = $input->getAttribute('value');
    }
    return $fields;
}
function storedTerms(PDO $pdo): string {
    $value = $pdo->query('SELECT terms_html FROM eds_warranty_cards_settings WHERE id=1')->fetchColumn();
    return is_string($value) ? $value : '';
}
function issuerHttpPost(array $overrides = []): array {
    return array_replace([
        'eds_warranty_cards_issuer_service_name' => '',
        'eds_warranty_cards_issuer_legal_name' => '',
        'eds_warranty_cards_issuer_registration_number' => '',
        'eds_warranty_cards_issuer_representative' => '',
        'eds_warranty_cards_issuer_address' => '',
        'eds_warranty_cards_issuer_phone' => '',
        'eds_warranty_cards_issuer_email' => '',
        'eds_warranty_cards_issuer_website' => '',
        'eds_warranty_cards_issuer_logo_url' => '',
    ], $overrides);
}
function storedIssuer(PDO $pdo): array {
    $row = $pdo->query('SELECT issuer_service_name,issuer_legal_name,issuer_registration_number,issuer_representative,issuer_address,issuer_phone,issuer_email,issuer_website,issuer_logo_url FROM eds_warranty_cards_settings WHERE id=1')->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : [];
}
function login(string $role): void {
    $login = request('/login', $role);
    if ($login['status'] === 302) {
        httpCheck(true, $role . ' already logged in');
        return;
    }
    $fields = hiddenFields($login['body']);
    $result = request('/login', $role, $fields + ['username' => 'test-' . $role, 'password' => 'Test-Password-123']);
    httpCheck($result['status'] === 302, $role . ' login');
}
try {
    $ready = false;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        try { $first = request('/login'); $ready = true; break; }
        catch (RuntimeException) { usleep(100000); }
    }
    httpCheck($ready && $first['status'] === 503, 'standard module migration runs');
    httpCheck($pdo->query('SELECT next_number FROM eds_warranty_cards_counter')->fetchColumn() === '00004', 'upgrade preserves formatted counter');
    httpCheck((int) $pdo->query('SELECT schema_version FROM eds_warranty_cards_counter')->fetchColumn() === 5, 'upgrade marks module version');
    httpCheck($pdo->query("SHOW COLUMNS FROM eds_warranty_cards_settings LIKE 'terms_html'")->fetch(PDO::FETCH_ASSOC)['Type'] === 'longtext', 'migration creates module-owned LONGTEXT terms storage');
    $path = '/work-orders/view/1/eds-warranty-card/draft';
    $settingsPath = '/module-manager/eds-warranty-cards/settings';
    $registryPath = '/eds-warranty-cards';
    $unauth = request($path);
    httpCheck($unauth['status'] === 302 && str_ends_with($unauth['headers']['location'], '/login'), 'unauthenticated draft denied');
    $unauthSettings = request($settingsPath);
    httpCheck($unauthSettings['status'] === 302 && str_ends_with($unauthSettings['headers']['location'], '/login'), 'unauthenticated module settings denied');
    $unauthRegistry = request($registryPath);
    httpCheck($unauthRegistry['status'] === 302 && str_ends_with($unauthRegistry['headers']['location'], '/login'), 'unauthenticated issued-card registry denied');
    login('admin');
    $emptyRegistry = request($registryPath, 'admin');
    $emptyRegistryDom = new DOMDocument(); @$emptyRegistryDom->loadHTML('<?xml encoding="UTF-8">' . $emptyRegistry['body']);
    $emptyRegistryXpath = new DOMXPath($emptyRegistryDom);
    httpCheck($emptyRegistry['status'] === 200
        && str_contains($emptyRegistry['body'], 'Издадени гаранционни карти')
        && str_contains($emptyRegistry['body'], 'Няма издадени гаранционни карти.'), 'Admin opens the empty issued-card registry');
    httpCheck($emptyRegistryXpath->query('//nav//a[@id="eds-warranty-cards-nav-link" and @href="' . $base . '/eds-warranty-cards" and @data-work-orders-url="' . $base . '/work-orders" and normalize-space(.)="Гаранционни карти"]')->length === 1
        && $emptyRegistryXpath->query('//nav//script[@src="' . $base . '/eds-warranty-cards/assets/warranty-card-nav.js?v=0.11.1"]')->length === 1, 'Admin receives exactly one registry link and its local navigation enhancement');
    httpCheck(request($registryPath, 'admin', [])['status'] === 405, 'registry rejects non-GET requests');
    login('technician');
    $technicianEmptyRegistry = request($registryPath, 'technician');
    $technicianRegistryDom = new DOMDocument(); @$technicianRegistryDom->loadHTML('<?xml encoding="UTF-8">' . $technicianEmptyRegistry['body']);
    httpCheck($technicianEmptyRegistry['status'] === 200
        && (new DOMXPath($technicianRegistryDom))->query('//nav//a[@id="eds-warranty-cards-nav-link" and @href="' . $base . '/eds-warranty-cards"]')->length === 1
        && (new DOMXPath($technicianRegistryDom))->query('//nav//script[contains(@src,"/eds-warranty-cards/assets/warranty-card-nav.js")]')->length === 1, 'Technician receives one navigation link and local enhancement and opens the registry');
    login('limited');
    $limitedRegistry = request($registryPath, 'limited');
    $limitedNavigationPage = request('/work-orders', 'limited');
    httpCheck($limitedRegistry['status'] === 302 && str_ends_with($limitedRegistry['headers']['location'], '/403'), 'Limited cannot open the registry directly');
    httpCheck(!str_contains($limitedNavigationPage['body'], 'href="' . $base . '/eds-warranty-cards"')
        && !str_contains($limitedNavigationPage['body'], '/eds-warranty-cards/assets/warranty-card-nav.js'), 'Limited receives neither registry link nor navigation JavaScript');
    $settingsPage = request($settingsPath, 'admin');
    $settingsDom = new DOMDocument(); @$settingsDom->loadHTML('<?xml encoding="UTF-8">' . $settingsPage['body']);
    $settingsXpath = new DOMXPath($settingsDom);
    $termsFields = $settingsXpath->query('//textarea[@name="eds_warranty_cards_terms_html"]');
    $termsField = $termsFields->item(0);
    $namedTermsFields = $settingsXpath->query('//*[@name="eds_warranty_cards_terms_html" or @name="eds_warranty_cards_terms_plain"]');
    httpCheck($settingsPage['status'] === 200 && $termsFields->length === 1 && $namedTermsFields->length === 1 && (int) $termsField->getAttribute('rows') >= 10 && !$termsField->hasAttribute('hidden') && !$termsField->hasAttribute('disabled') && !$termsField->hasAttribute('style'), 'normal textarea is the only named terms field and has no permanent inline hiding');
    httpCheck($settingsXpath->query('//*[@contenteditable]')->length === 0 && $settingsXpath->query('//*[@data-eds-command]')->length === 0 && !str_contains($settingsPage['body'], 'execCommand'), 'legacy contenteditable editor and execCommand are absent');
    httpCheck($settingsXpath->query('//*[@id="eds-warranty-cards-quill" and @hidden]')->length === 1, 'Quill shell starts hidden');
    httpCheck($settingsXpath->query('//*[@id="eds-warranty-cards-quill-toolbar"]//button[contains(concat(" ", normalize-space(@class), " "), " ql-bold ")]')->length === 1
        && $settingsXpath->query('//*[@id="eds-warranty-cards-quill-toolbar"]//button[contains(concat(" ", normalize-space(@class), " "), " ql-list ") and @value="bullet"]')->length === 1
        && $settingsXpath->query('//*[@id="eds-warranty-cards-quill-toolbar"]//button[contains(concat(" ", normalize-space(@class), " "), " ql-list ") and @value="ordered"]')->length === 1
        && $settingsXpath->query('//*[@id="eds-warranty-cards-quill-toolbar"]//button')->length === 3, 'toolbar exposes only bold, bullet list and ordered list');
    httpCheck($settingsXpath->query('//link[@href="' . $base . '/eds-warranty-cards/assets/quill.snow.css"]')->length === 1
        && $settingsXpath->query('//script[@src="' . $base . '/eds-warranty-cards/assets/quill.js"]')->length === 1
        && $settingsXpath->query('//script[@src="' . $base . '/eds-warranty-cards/assets/warranty-terms-editor.js"]')->length === 1, 'settings page references local editor resources');
    $resourceUrlsAreLocal = true;
    foreach ($settingsXpath->query('//script[@src] | //link[@href]') as $resource) {
        $url = $resource->hasAttribute('src') ? $resource->getAttribute('src') : $resource->getAttribute('href');
        if (!str_starts_with($url, $base . '/')) {
            $resourceUrlsAreLocal = false;
        }
    }
    httpCheck($resourceUrlsAreLocal, 'all rendered script and stylesheet requests stay on the application origin');
    httpCheck(!str_contains(strtolower($settingsPage['body']), 'cdn.') && !str_contains(strtolower($settingsPage['body']), 'jsdelivr') && !str_contains(strtolower($settingsPage['body']), 'unpkg'), 'rendered editor has no CDN references');
    $issuerNames = array_keys(issuerHttpPost());
    $issuerFieldsAreEmpty = true;
    foreach ($issuerNames as $issuerName) {
        $issuerNodes = $settingsXpath->query('//*[@name="' . $issuerName . '"]');
        $issuerNode = $issuerNodes->item(0);
        $issuerValue = $issuerNode instanceof DOMElement && strtolower($issuerNode->tagName) === 'textarea' ? $issuerNode->textContent : ($issuerNode instanceof DOMElement ? $issuerNode->getAttribute('value') : null);
        if ($issuerNodes->length !== 1 || $issuerValue !== '') { $issuerFieldsAreEmpty = false; }
    }
    $issuerHeading = strpos($settingsPage['body'], 'Данни за издателя');
    $numberHeading = strpos($settingsPage['body'], 'Следващ номер на гаранционна карта');
    $termsHeading = strpos($settingsPage['body'], 'Гаранционни условия');
    httpCheck($issuerFieldsAreEmpty, 'issuer fields are namespaced, unique and remain empty instead of copying general fallbacks');
    httpCheck($issuerHeading !== false && $numberHeading !== false && $termsHeading !== false && $issuerHeading < $numberHeading && $numberHeading < $termsHeading, 'settings sections render in issuer, number and terms order');
    $quillJs = request('/eds-warranty-cards/assets/quill.js');
    $quillCss = request('/eds-warranty-cards/assets/quill.snow.css');
    $editorJs = request('/eds-warranty-cards/assets/warranty-terms-editor.js');
    $editorCss = request('/eds-warranty-cards/assets/warranty-terms-editor.css');
    $documentCss = request('/eds-warranty-cards/assets/warranty-card-document.css');
    $paginationJs = request('/eds-warranty-cards/assets/warranty-card-pagination.js');
    $registryCss = request('/eds-warranty-cards/assets/warranty-card-registry.css');
    $registryNavigationJs = request('/eds-warranty-cards/assets/warranty-card-nav.js');
    httpCheck($quillJs['status'] === 200 && str_starts_with($quillJs['headers']['content-type'], 'text/javascript') && hash('sha256', $quillJs['body']) === 'f6157c72ac9b3f51cdead426335688a027b12405d9d6a4daadd38a676b2d7ff2', 'local route serves unchanged Quill 2.0.3 JavaScript');
    httpCheck($quillCss['status'] === 200 && str_starts_with($quillCss['headers']['content-type'], 'text/css') && hash('sha256', $quillCss['body']) === '1c7948cd13aa92fac6390319bc1e5e461823da171519d3a768db56164f871636', 'local route serves unchanged Quill Snow CSS');
    httpCheck($editorJs['status'] === 200 && str_contains($editorJs['body'], "formats: ['bold', 'list']") && str_contains($editorJs['body'], 'textarea.value = quill.getSemanticHTML') && !str_contains($editorJs['body'], 'execCommand'), 'local integration restricts formats and synchronizes textarea');
    httpCheck($editorCss['status'] === 200 && str_contains($editorCss['body'], 'min-height: 300px')
        && preg_match('/#eds_warranty_cards_terms_html\[hidden\],\s*#eds-warranty-cards-quill\[hidden\]\s*\{[^}]*display:\s*none\s*!important;/s', $editorCss['body']) === 1, 'local module CSS styles the editor and enforces hidden state');
    httpCheck($documentCss['status'] === 200 && str_starts_with($documentCss['headers']['content-type'], 'text/css')
        && str_contains($documentCss['body'], 'size: A4 portrait') && str_contains($documentCss['body'], 'display: table-header-group'), 'allowlist route serves the shared local A4 document CSS');
    httpCheck($paginationJs['status'] === 200 && str_starts_with($paginationJs['headers']['content-type'], 'text/javascript')
        && str_contains($paginationJs['body'], 'document.fonts.ready') && str_contains($paginationJs['body'], 'eds-warranty-page-shell')
        && !preg_match('#https?://|//cdn#i', $paginationJs['body']), 'allowlist route serves local asset-aware pagination without external runtime resources');
    httpCheck($registryCss['status'] === 200 && str_starts_with($registryCss['headers']['content-type'], 'text/css')
        && str_contains($registryCss['body'], '@media (max-width: 640px)')
        && str_contains($registryCss['body'], '@media (min-width: 641px)')
        && str_contains($registryCss['body'], 'justify-content: flex-end'), 'allowlist route serves local adaptive registry styles with desktop action alignment');
    httpCheck($registryNavigationJs['status'] === 200 && str_starts_with($registryNavigationJs['headers']['content-type'], 'text/javascript')
        && str_contains($registryNavigationJs['body'], "candidate.getAttribute('href') === workOrdersUrl")
        && !preg_match('#https?://|//cdn#i', $registryNavigationJs['body']), 'allowlist route serves exact-match navigation JavaScript without external resources');
    httpCheck(request('/eds-warranty-cards/assets/not-allowed.js')['status'] === 404 && request('/eds-warranty-cards/assets/quill.js', '', [])['status'] === 405, 'asset route rejects unknown files and writes');
    $settingsFields = hiddenFields($settingsPage['body']);
    $badSettingsCsrf = array_merge($settingsFields, issuerHttpPost(['eds_warranty_cards_issuer_service_name'=>'CSRF Issuer']), ['eds_warranty_cards_next_number'=>'00004','eds_warranty_cards_terms_html'=>'<p>CSRF TERMS</p>']);
    $badSettingsCsrf['csrf_token'] = 'BAD';
    request($settingsPath, 'admin', $badSettingsCsrf);
    httpCheck(storedTerms($pdo) === '' && storedIssuer($pdo)['issuer_service_name'] === '', 'CSRF blocks warranty terms and issuer setting changes');
    $termsAttack = '<script>alert(1)</script><img src=x onerror=alert(1)><p onclick="alert(1)">Текст</p><svg onload=alert(1)></svg><a href="javascript:alert(1)">Връзка</a><style>body{display:none}</style><iframe srcdoc="<script>alert(1)</script>"></iframe><object data="javascript:alert(1)"></object><p style="color:red" class="x" data-test="1">Условия</p><p>Пазете <b>документа</b><br>Нов ред</p><ul><li>Точка</li></ul><ol><li>Стъпка</li></ol>';
    $savedSettings = request($settingsPath, 'admin', array_merge($settingsFields, issuerHttpPost(), ['eds_warranty_cards_next_number'=>'00004','eds_warranty_cards_terms_html'=>$termsAttack]));
    $storedTerms = storedTerms($pdo);
    httpCheck($savedSettings['status'] === 200 && str_contains($savedSettings['body'], 'Настройките на гаранционните карти са записани'), 'Admin saves warranty terms through standard settings route');
    httpCheck($storedTerms === '<p>Текст</p>Връзка<p>Условия</p><p>Пазете <strong>документа</strong><br>Нов ред</p><ul><li>Точка</li></ul><ol><li>Стъпка</li></ol>', 'only sanitized allowlisted terms are stored');
    httpCheck(!preg_match('/(?:script|onclick|onerror|javascript:|<img|<svg|<style|<iframe|<object|style=|class=|data-test)/i', $storedTerms), 'stored terms contain nothing executable or disallowed');
    $reopenedSettings = request($settingsPath, 'admin');
    $reopenedDom = new DOMDocument(); @$reopenedDom->loadHTML('<?xml encoding="UTF-8">' . $reopenedSettings['body']);
    $reopenedField = (new DOMXPath($reopenedDom))->query('//textarea[@name="eds_warranty_cards_terms_html"]')->item(0);
    httpCheck($reopenedField && $reopenedField->textContent === $storedTerms && !str_contains($reopenedSettings['body'], 'alert(1)'), 'reopened textarea contains exactly the stored sanitized terms');
    $fallbackFields = hiddenFields($reopenedSettings['body']);
    request($settingsPath, 'admin', array_merge($fallbackFields, issuerHttpPost(), ['eds_warranty_cards_next_number'=>'00004','eds_warranty_cards_terms_html'=>'<p>Обикновен ред 1<br>Обикновен ред 2</p>']));
    httpCheck(storedTerms($pdo) === '<p>Обикновен ред 1<br>Обикновен ред 2</p>', 'no-JavaScript textarea saves through the same server sanitizer');
    $restoreTermsPage = request($settingsPath, 'admin');
    request($settingsPath, 'admin', array_merge(hiddenFields($restoreTermsPage['body']), issuerHttpPost(), ['eds_warranty_cards_next_number'=>'00004','eds_warranty_cards_terms_html'=>$termsAttack]));
    httpCheck(storedTerms($pdo) === $storedTerms, 'rich sanitized terms restored for issuance checks');
    $tooLongPage = request($settingsPath, 'admin');
    $tooLongResponse = request($settingsPath, 'admin', array_merge(hiddenFields($tooLongPage['body']), issuerHttpPost(), ['eds_warranty_cards_next_number'=>'00005','eds_warranty_cards_terms_html'=>str_repeat('я', 20001)]));
    httpCheck($tooLongResponse['status'] === 200 && str_contains($tooLongResponse['body'], 'най-много 20000 знака'), 'settings route rejects more than 20000 sanitized UTF-8 characters');
    $tooLongDom = new DOMDocument(); @$tooLongDom->loadHTML('<?xml encoding="UTF-8">' . $tooLongResponse['body']);
    $tooLongField = (new DOMXPath($tooLongDom))->query('//textarea[@name="eds_warranty_cards_terms_html"]')->item(0);
    httpCheck($tooLongField && $tooLongField->textContent === str_repeat('я', 20001), 'settings error retains submitted terms in the textarea');
    httpCheck(storedTerms($pdo) === $storedTerms && $pdo->query('SELECT next_number FROM eds_warranty_cards_counter')->fetchColumn() === '00004', 'invalid terms change neither stored terms nor counter');
    $errorAttack = '</textarea><script>ERROR-RETENTION-ATTACK</script>';
    $errorResponse = request($settingsPath, 'admin', array_merge(hiddenFields($tooLongPage['body']), issuerHttpPost(), ['eds_warranty_cards_next_number'=>'00003','eds_warranty_cards_terms_html'=>$errorAttack]));
    $errorDom = new DOMDocument(); @$errorDom->loadHTML('<?xml encoding="UTF-8">' . $errorResponse['body']);
    $errorXpath = new DOMXPath($errorDom);
    $errorField = $errorXpath->query('//textarea[@name="eds_warranty_cards_terms_html"]')->item(0);
    httpCheck($errorField && $errorField->textContent === $errorAttack && $errorXpath->query('//script[contains(., "ERROR-RETENTION-ATTACK")]')->length === 0, 'untrusted terms retained after an error cannot escape the textarea');
    httpCheck(storedTerms($pdo) === $storedTerms, 'failed counter save does not replace stored sanitized terms');

    $allIssuer = issuerHttpPost([
        'eds_warranty_cards_issuer_service_name' => 'Сервиз Юникод 😀',
        'eds_warranty_cards_issuer_legal_name' => 'Електронни решения ООД',
        'eds_warranty_cards_issuer_registration_number' => 'BG-ЕИК-123',
        'eds_warranty_cards_issuer_representative' => 'Георги Представител',
        'eds_warranty_cards_issuer_address' => "гр. София\nул. Тест 1",
        'eds_warranty_cards_issuer_phone' => '+359 2 222 222',
        'eds_warranty_cards_issuer_email' => 'issuer@example.test',
        'eds_warranty_cards_issuer_website' => 'https://issuer.example.test/path',
        'eds_warranty_cards_issuer_logo_url' => 'https://issuer.example.test/logo.svg',
    ]);
    $issuerSavePage = request($settingsPath, 'admin');
    $issuerSaved = request($settingsPath, 'admin', array_merge(hiddenFields($issuerSavePage['body']), $allIssuer, [
        'eds_warranty_cards_next_number' => '00004',
        'eds_warranty_cards_terms_html' => $storedTerms,
    ]));
    $storedAllIssuer = storedIssuer($pdo);
    httpCheck($issuerSaved['status'] === 200 && $storedAllIssuer['issuer_service_name'] === 'Сервиз Юникод 😀'
        && $storedAllIssuer['issuer_legal_name'] === 'Електронни решения ООД'
        && $storedAllIssuer['issuer_address'] === "гр. София\nул. Тест 1"
        && $storedAllIssuer['issuer_logo_url'] === 'https://issuer.example.test/logo.svg', 'Admin atomically saves all issuer fields with Unicode');
    $issuerReopen = request($settingsPath, 'admin');
    httpCheck(str_contains($issuerReopen['body'], 'value="Сервиз Юникод 😀"')
        && str_contains($issuerReopen['body'], 'гр. София') && str_contains($issuerReopen['body'], 'ул. Тест 1'), 'saved issuer values reload in the settings form');

    $invalidIssuerCases = [
        ['eds_warranty_cards_issuer_email', 'invalid-email', 'валиден имейл'],
        ['eds_warranty_cards_issuer_website', '/relative-site', 'абсолютен HTTP или HTTPS'],
        ['eds_warranty_cards_issuer_logo_url', 'javascript:alert(1)', 'безопасен път'],
        ['eds_warranty_cards_issuer_logo_url', 'data:image/svg+xml,x', 'безопасен път'],
        ['eds_warranty_cards_issuer_logo_url', 'vbscript:msgbox(1)', 'безопасен път'],
        ['eds_warranty_cards_issuer_logo_url', '//evil.example/logo.svg', 'безопасен път'],
    ];
    foreach ($invalidIssuerCases as [$field, $value, $message]) {
        $invalidIssuerPage = request($settingsPath, 'admin');
        $invalidIssuer = $allIssuer;
        $invalidIssuer[$field] = $value;
        $invalidResponse = request($settingsPath, 'admin', array_merge(hiddenFields($invalidIssuerPage['body']), $invalidIssuer, [
            'eds_warranty_cards_next_number' => '00005',
            'eds_warranty_cards_terms_html' => '<p>MUST NOT SAVE</p>',
        ]));
        httpCheck($invalidResponse['status'] === 200 && str_contains($invalidResponse['body'], $message) && str_contains($invalidResponse['body'], htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')), 'invalid issuer URL or email is rejected and retained safely');
        httpCheck(storedIssuer($pdo) === $storedAllIssuer && storedTerms($pdo) === $storedTerms
            && $pdo->query('SELECT next_number FROM eds_warranty_cards_counter')->fetchColumn() === '00004', 'invalid issuer field causes no partial settings or counter write');
    }
    $htmlIssuerPage = request($settingsPath, 'admin');
    $htmlIssuer = $allIssuer;
    $htmlIssuer['eds_warranty_cards_issuer_service_name'] = '<img src=x onerror=alert(1)>';
    $htmlIssuerResponse = request($settingsPath, 'admin', array_merge(hiddenFields($htmlIssuerPage['body']), $htmlIssuer, [
        'eds_warranty_cards_next_number' => '00004',
        'eds_warranty_cards_terms_html' => $storedTerms,
    ]));
    $htmlIssuerDom = new DOMDocument(); @$htmlIssuerDom->loadHTML('<?xml encoding="UTF-8">' . $htmlIssuerResponse['body']);
    $htmlIssuerXpath = new DOMXPath($htmlIssuerDom);
    $htmlIssuerField = $htmlIssuerXpath->query('//*[@name="eds_warranty_cards_issuer_service_name"]')->item(0);
    httpCheck($htmlIssuerField && $htmlIssuerField->getAttribute('value') === '<img src=x onerror=alert(1)>'
        && $htmlIssuerXpath->query('//img[@onerror]')->length === 0, 'HTML issuer input is rejected and retained only as escaped field text');
    httpCheck(storedIssuer($pdo) === $storedAllIssuer, 'HTML issuer input cannot partially overwrite stored values');

    $blankIssuerPage = request($settingsPath, 'admin');
    $blankIssuerSave = request($settingsPath, 'admin', array_merge(hiddenFields($blankIssuerPage['body']), issuerHttpPost(), [
        'eds_warranty_cards_next_number' => '00004',
        'eds_warranty_cards_terms_html' => $storedTerms,
    ]));
    httpCheck($blankIssuerSave['status'] === 200 && storedIssuer($pdo)['issuer_service_name'] === '', 'issuer fields may be cleared without copying fallback values into storage');
    $task = request('/work-orders/view/1', 'admin');
    $authorWarrantyPosition = strpos($task['body'], '>Гаранция</h2>');
    $warrantyCardPosition = strpos($task['body'], '>Гаранционна карта</h2>');
    httpCheck($task['status'] === 200 && str_contains($task['body'], 'Подготви гаранционна карта'), 'task hook renders prepare button');
    httpCheck($authorWarrantyPosition !== false && $warrantyCardPosition !== false && $authorWarrantyPosition < $warrantyCardPosition, 'author Warranty section renders before warranty card when both modules are enabled');
    httpCheck(substr_count($task['body'], '>Гаранционна карта</h2>') === 1, 'warranty card section renders once');
    $settings->setSetting('enabled_modules', '["eds-warranty-cards"]');
    $taskWithoutWarranty = request('/work-orders/view/1', 'admin');
    httpCheck(!str_contains($taskWithoutWarranty['body'], '>Гаранция</h2>') && substr_count($taskWithoutWarranty['body'], '>Гаранционна карта</h2>') === 1, 'warranty card remains in the sidebar when author Warranty is disabled');
    $draft = request($path, 'admin');
    httpCheck($draft['status'] === 200, 'draft GET renders using real layout');
    httpCheck(str_contains($draft['headers']['cache-control'], 'no-store'), 'internal draft not cached');
    httpCheck(!str_contains($draft['body'], ' checked'), 'initial selections empty');
    httpCheck(str_contains($draft['body'], 'Смяна на хард диск') && str_contains($draft['body'], 'бройка 2 от 2'), 'labor and individual units shown');
    httpCheck(!str_contains($settings->getSetting('enabled_modules'), 'inventory') && str_contains($draft['body'], 'Добави част ръчно'), 'inventory lines and manual action work while Inventory module is disabled');
    httpCheck(str_contains($draft['body'], "container.lastElementChild") && str_contains($draft['body'], "window.crypto.getRandomValues"), 'manual add control creates distinct stable rows in the browser');
    httpCheck(str_contains($draft['body'], 'Добави дейност') && str_contains($draft['body'], 'Няма добавени гаранционни дейности.') && !str_contains($draft['body'], 'name="work_enabled"'), 'new draft has an empty repeatable work activity section without the legacy checkbox');
    $newDraftDom = new DOMDocument(); @$newDraftDom->loadHTML('<?xml encoding="UTF-8">' . $draft['body']);
    $newDraftXpath = new DOMXPath($newDraftDom);
    httpCheck($newDraftXpath->query('//form[@id="eds-delete-draft-form"]')->length === 0
        && $newDraftXpath->query('//button[@name="draft_action" and (@value="save" or @value="save_and_review")]')->length === 2, 'new unsaved draft has both save actions and no delete action');
    httpCheck($newDraftXpath->query('//form//form')->length === 0
        && $newDraftXpath->query('//button[@name="draft_action" and @form="eds-warranty-card-draft-form"]')->length === 2, 'draft action bar uses form-associated buttons without nested forms');
    httpCheck($newDraftXpath->query('//input[contains(@name,"[serial_number]") and @maxlength="128"]')->length >= 2
        && $newDraftXpath->query('//input[contains(@name,"[supplier]") and @maxlength="128"]')->length >= 2
        && $newDraftXpath->query('//input[contains(@name,"[supplier_card]") and @maxlength="128"]')->length >= 2
        && str_contains($draft['body'], 'name="manual_parts[__KEY__][name]" value=""')
        && str_contains($draft['body'], 'maxlength="128" id="eds-manual-__KEY__-name"')
        && str_contains($draft['body'], 'maxlength="250" id="eds-work-__KEY__-description"')
        && str_contains($draft['body'], 'maxlength="6" id="eds-work-__KEY__-months"'), 'real draft HTML and dynamic templates expose all field length limits');
    httpCheck(str_contains($draft['body'], 'data-max-rows="100"') && str_contains($draft['body'], 'data-max-rows="50"')
        && substr_count($draft['body'], 'window.alert(') === 2, 'real draft HTML exposes localized client-side row guards');
    httpCheck((int) $pdo->query('SELECT COUNT(*) FROM eds_warranty_cards_drafts')->fetchColumn() === 0, 'GET creates no draft');
    $pdo->exec("INSERT INTO work_order_products VALUES (503,100,'Large inventory line',101)");
    $overInventoryPath = '/work-orders/view/100/eds-warranty-card/draft';
    $overInventory = request($overInventoryPath, 'admin');
    httpCheck($overInventory['status'] === 200 && str_contains($overInventory['body'], 'повече от 100 складови бройки')
        && !str_contains($overInventory['body'], 'бройка 100 от 101'), 'inventory quantity over 100 reports a clear error without rendering a partial physical-unit list');
    $overInventorySave = request($overInventoryPath, 'admin', hiddenFields($overInventory['body']));
    httpCheck($overInventorySave['status'] === 200 && str_contains($overInventorySave['body'], 'повече от 100 складови бройки')
        && (int) $pdo->query('SELECT COUNT(*) FROM eds_warranty_cards_drafts WHERE work_order_id=100')->fetchColumn() === 0, 'oversized inventory cannot save a draft through the real route');
    $pdo->exec('DELETE FROM work_order_products WHERE id=503');
    $deleteDraftPagePath = '/work-orders/view/3/eds-warranty-card/draft';
    $deleteDraftPath = $deleteDraftPagePath . '/delete';
    $newDeleteDraft = request($deleteDraftPagePath, 'admin');
    httpCheck(!str_contains($newDeleteDraft['body'], 'id="eds-delete-draft-form"'), 'separate new draft also has no delete button');
    $deleteGet = request($deleteDraftPath, 'admin');
    httpCheck($deleteGet['status'] === 405 && ($deleteGet['headers']['allow'] ?? '') === 'POST', 'draft deletion rejects GET and advertises POST only');
    $deleteCreateFields = hiddenFields($newDeleteDraft['body']);
    $deleteCreate = $deleteCreateFields + [
        'units' => [],
        'manual_parts' => ['m_33333333333333333333333333333333' => ['name'=>'Delete-only manual part','serial_number'=>'DELETE-SN','months'=>'12','supplier'=>'Delete supplier','supplier_card'=>'Delete supplier card']],
        'work_items' => ['w_33333333333333333333333333333333' => ['description'=>'Delete-only activity','months'=>'6']],
    ];
    httpCheck(request($deleteDraftPagePath, 'admin', $deleteCreate)['status'] === 302, 'draft for deletion scenario is saved');
    $savedDeleteDraft = request($deleteDraftPagePath, 'admin');
    $savedDeleteDom = new DOMDocument(); @$savedDeleteDom->loadHTML('<?xml encoding="UTF-8">' . $savedDeleteDraft['body']);
    $savedDeleteXpath = new DOMXPath($savedDeleteDom);
    $deleteForm = $savedDeleteXpath->query('//form[@id="eds-delete-draft-form" and @method="POST"]')->item(0);
    httpCheck($deleteForm && str_ends_with($deleteForm->getAttribute('action'), '/eds-warranty-card/draft/delete')
        && str_contains($deleteForm->getAttribute('data-confirm'), 'Всички въведени части и гаранционни дейности ще бъдат премахнати'), 'saved draft shows separate confirmed delete form');
    $deleteFields = hiddenFields($savedDeleteDraft['body']);
    $deleteCounterBefore = $pdo->query('SELECT next_number FROM eds_warranty_cards_counter')->fetchColumn();
    $deleteInventoryBefore = $pdo->query('SELECT id,work_order_id,product_name,quantity FROM work_order_products ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $deleteWorkOrderBefore = $pdo->query('SELECT * FROM work_orders WHERE id=3')->fetch(PDO::FETCH_ASSOC);
    $badDeleteCsrf = array_merge($deleteFields, ['confirm_delete'=>'1']);
    $badDeleteCsrf['csrf_token'] = 'BAD';
    $badDeleteResponse = request($deleteDraftPath, 'admin', $badDeleteCsrf);
    httpCheck($badDeleteResponse['status'] === 302 && str_ends_with($badDeleteResponse['headers']['location'], '/403'), 'CSRF blocks draft deletion');
    httpCheck((int) $pdo->query('SELECT COUNT(*) FROM eds_warranty_cards_drafts WHERE work_order_id=3')->fetchColumn() === 1, 'CSRF rejection preserves draft');
    $unconfirmedDelete = request($deleteDraftPath, 'admin', array_merge($deleteFields, ['confirm_delete'=>'0']));
    httpCheck($unconfirmedDelete['status'] === 302 && str_ends_with($unconfirmedDelete['headers']['location'], '/eds-warranty-card/draft'), 'server rejects deletion without explicit confirmation');
    httpCheck((int) $pdo->query('SELECT COUNT(*) FROM eds_warranty_cards_drafts WHERE work_order_id=3')->fetchColumn() === 1, 'unconfirmed deletion changes nothing');
    login('limited');
    $limitedDeleteDraft = request($deleteDraftPagePath, 'limited');
    httpCheck(!str_contains($limitedDeleteDraft['body'], 'id="eds-delete-draft-form"'), 'Limited has no draft delete action');
    $limitedDeleteResponse = request($deleteDraftPath, 'limited', array_merge($deleteFields, ['confirm_delete'=>'1']));
    httpCheck($limitedDeleteResponse['status'] === 302 && str_ends_with($limitedDeleteResponse['headers']['location'], '/403'), 'Limited cannot delete a draft by manual request');
    login('admin');
    $staleDeleteFields = hiddenFields(request($deleteDraftPagePath, 'admin')['body']);
    $newerDeleteSave = $staleDeleteFields + [
        'manual_parts' => $deleteCreate['manual_parts'],
        'work_items' => ['w_33333333333333333333333333333333' => ['description'=>'Newer delete activity','months'=>'9']],
    ];
    httpCheck(request($deleteDraftPagePath, 'admin', $newerDeleteSave)['status'] === 302, 'draft is updated before stale deletion attempt');
    $staleDeleteResponse = request($deleteDraftPath, 'admin', array_merge($staleDeleteFields, ['confirm_delete'=>'1']));
    httpCheck($staleDeleteResponse['status'] === 302 && str_ends_with($staleDeleteResponse['headers']['location'], '/eds-warranty-card/draft'), 'stale deletion redirects back to current draft');
    $staleDeleteMessage = request($deleteDraftPagePath, 'admin');
    httpCheck(str_contains($staleDeleteMessage['body'], 'променена от друг потребител') && str_contains($staleDeleteMessage['body'], 'Newer delete activity'), 'stale deletion shows conflict and preserves newer values');
    $currentDeleteFields = hiddenFields($staleDeleteMessage['body']);
    login('technician');
    $technicianDeleteFields = hiddenFields(request($deleteDraftPagePath, 'technician')['body']);
    $successfulDelete = request($deleteDraftPath, 'technician', array_merge($technicianDeleteFields, ['confirm_delete'=>'1']));
    httpCheck($successfulDelete['status'] === 302 && str_ends_with($successfulDelete['headers']['location'], '/work-orders/view/3'), 'Technician can delete a current draft and is redirected to the work order');
    login('admin');
    $deleteSuccessPage = request('/work-orders/view/3', 'technician');
    httpCheck(str_contains($deleteSuccessPage['body'], 'Черновата на гаранционната карта е изтрита.'), 'successful deletion shows the expected message');
    httpCheck((int) $pdo->query('SELECT COUNT(*) FROM eds_warranty_cards_drafts WHERE work_order_id=3')->fetchColumn() === 0, 'successful deletion removes only the module draft row');
    httpCheck($pdo->query('SELECT next_number FROM eds_warranty_cards_counter')->fetchColumn() === $deleteCounterBefore, 'HTTP draft deletion leaves counter unchanged');
    httpCheck($pdo->query('SELECT id,work_order_id,product_name,quantity FROM work_order_products ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) === $deleteInventoryBefore, 'HTTP draft deletion leaves inventory rows and quantities unchanged');
    httpCheck($pdo->query('SELECT * FROM work_orders WHERE id=3')->fetch(PDO::FETCH_ASSOC) === $deleteWorkOrderBefore, 'HTTP draft deletion leaves work order unchanged');
    $emptyAfterDelete = request($deleteDraftPagePath, 'admin');
    $emptyAfterDeleteDom = new DOMDocument(); @$emptyAfterDeleteDom->loadHTML('<?xml encoding="UTF-8">' . $emptyAfterDelete['body']);
    $emptyAfterDeleteXpath = new DOMXPath($emptyAfterDeleteDom);
    httpCheck($emptyAfterDeleteXpath->query('//form[@id="eds-delete-draft-form"]')->length === 0
        && $emptyAfterDeleteXpath->query('//*[@id="eds-manual-parts"]/*[@data-eds-manual-part]')->length === 0
        && $emptyAfterDeleteXpath->query('//*[@id="eds-work-items"]/*[@data-eds-work-item]')->length === 0
        && $emptyAfterDeleteXpath->query('//input[@data-eds-select and @checked]')->length === 0, 'preparing after deletion opens a completely empty unsaved draft');
    $repeatDelete = request($deleteDraftPath, 'admin', array_merge($currentDeleteFields, ['confirm_delete'=>'1']));
    httpCheck($repeatDelete['status'] === 302 && str_ends_with($repeatDelete['headers']['location'], '/work-orders/view/3'), 'repeated deletion of a missing draft returns safely to the work order');
    $repeatDeleteMessage = request('/work-orders/view/3', 'admin');
    httpCheck(str_contains($repeatDeleteMessage['body'], 'Черновата вече не съществува') && $pdo->query('SELECT next_number FROM eds_warranty_cards_counter')->fetchColumn() === $deleteCounterBefore, 'repeated deletion reports missing draft and changes no counter');
    $missingDelete = request('/work-orders/view/999/eds-warranty-card/draft/delete', 'admin', array_merge($currentDeleteFields, ['confirm_delete'=>'1']));
    httpCheck($missingDelete['status'] === 302 && str_ends_with($missingDelete['headers']['location'], '/404'), 'draft deletion cannot target a missing or inaccessible work order');

    $reviewCounterBefore = $pdo->query('SELECT next_number FROM eds_warranty_cards_counter')->fetchColumn();
    $firstReviewFields = hiddenFields($emptyAfterDelete['body']);
    $firstReviewPost = $firstReviewFields + [
        'manual_parts' => ['m_44444444444444444444444444444444' => ['name'=>'First review part','serial_number'=>'FIRST-REVIEW-SN','months'=>'18','supplier'=>'First review supplier','supplier_card'=>'First review card']],
        'work_items' => ['w_44444444444444444444444444444444' => ['description'=>'First review activity','months'=>'5']],
    ];
    $firstReviewPost['draft_action'] = 'save_and_review';
    $firstReviewPost['redirect'] = 'https://evil.example/unsafe';
    $firstReviewSave = request($deleteDraftPagePath, 'admin', $firstReviewPost);
    httpCheck($firstReviewSave['status'] === 302 && str_ends_with($firstReviewSave['headers']['location'], '/work-orders/view/3/eds-warranty-card'), 'first save-and-review creates the draft and redirects to the fixed preview route');
    httpCheck(!str_contains($firstReviewSave['headers']['location'], 'evil.example')
        && (int) $pdo->query('SELECT COUNT(*) FROM eds_warranty_cards_issued WHERE work_order_id=3')->fetchColumn() === 0
        && $pdo->query('SELECT next_number FROM eds_warranty_cards_counter')->fetchColumn() === $reviewCounterBefore, 'save-and-review ignores redirect input and neither issues nor consumes a number');
    $firstReviewPreview = request('/work-orders/view/3/eds-warranty-card', 'admin');
    httpCheck($firstReviewPreview['status'] === 200 && str_contains($firstReviewPreview['body'], 'First review part')
        && str_contains($firstReviewPreview['body'], 'First review activity') && str_contains($firstReviewPreview['body'], 'Преглед преди издаване'), 'preview receives values from the first saved draft');
    httpCheck(str_contains($firstReviewPreview['body'], 'HTTP Fallback Service')
        && str_contains($firstReviewPreview['body'], 'HTTP Fallback Address')
        && str_contains($firstReviewPreview['body'], 'src="/fallback-logo.svg"')
        && !str_contains($firstReviewPreview['body'], 'Електронни решения ООД'), 'draft preview uses current general fallbacks without adding empty legal issuer fields');

    $existingReviewPage = request($deleteDraftPagePath, 'admin');
    $existingReviewFields = hiddenFields($existingReviewPage['body']);
    $existingReviewPost = $existingReviewFields + [
        'manual_parts' => ['m_44444444444444444444444444444444' => ['name'=>'Updated review part','serial_number'=>'UPDATED-REVIEW-SN','months'=>'24','supplier'=>'Updated supplier','supplier_card'=>'Updated card']],
        'work_items' => ['w_44444444444444444444444444444444' => ['description'=>'Updated review activity','months'=>'8']],
    ];
    $existingReviewPost['draft_action'] = 'save_and_review';
    $existingReviewSave = request($deleteDraftPagePath, 'admin', $existingReviewPost);
    httpCheck($existingReviewSave['status'] === 302 && str_ends_with($existingReviewSave['headers']['location'], '/work-orders/view/3/eds-warranty-card'), 'existing draft save-and-review redirects to preview');
    $updatedReviewPreview = request('/work-orders/view/3/eds-warranty-card', 'admin');
    httpCheck(str_contains($updatedReviewPreview['body'], 'Updated review part') && str_contains($updatedReviewPreview['body'], 'Updated review activity')
        && !str_contains($updatedReviewPreview['body'], 'First review part'), 'preview uses the latest successfully saved values');
    httpCheck((int) $pdo->query('SELECT COUNT(*) FROM eds_warranty_cards_issued WHERE work_order_id=3')->fetchColumn() === 0
        && $pdo->query('SELECT next_number FROM eds_warranty_cards_counter')->fetchColumn() === $reviewCounterBefore, 'editing through save-and-review still does not issue or change the counter');

    $errorReviewPage = request($deleteDraftPagePath, 'admin');
    $errorReviewFields = hiddenFields($errorReviewPage['body']);
    $storedBeforeReviewError = $pdo->query('SELECT content FROM eds_warranty_cards_drafts WHERE work_order_id=3')->fetchColumn();
    $errorReviewPost = $errorReviewFields + [
        'manual_parts' => ['m_55555555555555555555555555555555' => ['name'=>'Retained invalid part','serial_number'=>'RETAINED-SN','months'=>'-1','supplier'=>'Retained supplier','supplier_card'=>'Retained card']],
        'work_items' => ['w_55555555555555555555555555555555' => ['description'=>'Retained activity','months'=>'7']],
    ];
    $errorReviewPost['draft_action'] = 'save_and_review';
    $errorReview = request($deleteDraftPagePath, 'admin', $errorReviewPost);
    httpCheck($errorReview['status'] === 200 && !isset($errorReview['headers']['location'])
        && str_contains($errorReview['body'], 'Retained invalid part') && str_contains($errorReview['body'], 'Retained activity'), 'save error stays in the editor and retains all submitted rows');
    httpCheck($pdo->query('SELECT content FROM eds_warranty_cards_drafts WHERE work_order_id=3')->fetchColumn() === $storedBeforeReviewError
        && $pdo->query('SELECT next_number FROM eds_warranty_cards_counter')->fetchColumn() === $reviewCounterBefore, 'failed save-and-review changes neither draft nor counter');

    $staleReviewPage = request($deleteDraftPagePath, 'admin');
    $staleReviewFields = hiddenFields($staleReviewPage['body']);
    $newerReviewPost = $staleReviewFields + [
        'manual_parts' => ['m_66666666666666666666666666666666' => ['name'=>'Concurrent newer part','serial_number'=>'NEWER-SN','months'=>'30','supplier'=>'','supplier_card'=>'']],
        'work_items' => ['w_66666666666666666666666666666666' => ['description'=>'Concurrent newer activity','months'=>'9']],
    ];
    $newerReviewPost['draft_action'] = 'save';
    httpCheck(request($deleteDraftPagePath, 'admin', $newerReviewPost)['status'] === 302, 'ordinary save creates a newer draft revision');
    $staleReviewPost = $staleReviewFields + [
        'manual_parts' => ['m_77777777777777777777777777777777' => ['name'=>'Stale review part','serial_number'=>'STALE-SN','months'=>'36','supplier'=>'','supplier_card'=>'']],
        'work_items' => ['w_77777777777777777777777777777777' => ['description'=>'Stale review activity','months'=>'10']],
    ];
    $staleReviewPost['draft_action'] = 'save_and_review';
    $staleReviewResponse = request($deleteDraftPagePath, 'admin', $staleReviewPost);
    httpCheck($staleReviewResponse['status'] === 200 && !isset($staleReviewResponse['headers']['location'])
        && str_contains($staleReviewResponse['body'], 'променена от друг потребител')
        && str_contains($staleReviewResponse['body'], 'Stale review part') && str_contains($staleReviewResponse['body'], 'Stale review activity'), 'revision conflict does not open preview and retains the stale submitted values');

    $unsafeActionPage = request($deleteDraftPagePath, 'admin');
    $unsafeActionFields = hiddenFields($unsafeActionPage['body']);
    $unsafeStoredBefore = $pdo->query('SELECT revision,content FROM eds_warranty_cards_drafts WHERE work_order_id=3')->fetch(PDO::FETCH_ASSOC);
    $unsafeActionPost = $unsafeActionFields + [
        'manual_parts' => ['m_88888888888888888888888888888888' => ['name'=>'Unsafe action retained','serial_number'=>'','months'=>'12','supplier'=>'','supplier_card'=>'']],
    ];
    $unsafeActionPost['draft_action'] = 'https://evil.example/redirect';
    $unsafeActionResponse = request($deleteDraftPagePath, 'admin', $unsafeActionPost);
    $unsafeStoredAfter = $pdo->query('SELECT revision,content FROM eds_warranty_cards_drafts WHERE work_order_id=3')->fetch(PDO::FETCH_ASSOC);
    httpCheck($unsafeActionResponse['status'] === 200 && !isset($unsafeActionResponse['headers']['location'])
        && str_contains($unsafeActionResponse['body'], 'Формулярът е невалиден') && str_contains($unsafeActionResponse['body'], 'Unsafe action retained'), 'unknown draft action is rejected without a redirect and retains submitted values');
    httpCheck($unsafeStoredAfter === $unsafeStoredBefore, 'unknown draft action cannot write the draft');

    $csrfReviewPage = request($deleteDraftPagePath, 'admin');
    $csrfReviewPost = hiddenFields($csrfReviewPage['body']) + [
        'manual_parts' => ['m_99999999999999999999999999999999' => ['name'=>'CSRF review retained','serial_number'=>'','months'=>'12','supplier'=>'','supplier_card'=>'']],
        'work_items' => ['w_99999999999999999999999999999999' => ['description'=>'CSRF review activity','months'=>'11']],
    ];
    $csrfReviewPost['csrf_token'] = 'BAD';
    $csrfReviewPost['draft_action'] = 'save_and_review';
    $csrfReviewResponse = request($deleteDraftPagePath, 'admin', $csrfReviewPost);
    httpCheck($csrfReviewResponse['status'] === 200 && !isset($csrfReviewResponse['headers']['location'])
        && str_contains($csrfReviewResponse['body'], 'CSRF review retained') && str_contains($csrfReviewResponse['body'], 'CSRF review activity'), 'CSRF failure on save-and-review stays in the editor and retains values');
    httpCheck($pdo->query('SELECT next_number FROM eds_warranty_cards_counter')->fetchColumn() === $reviewCounterBefore, 'all save-and-review failures leave the counter unchanged');

    login('limited');
    $limitedReviewDraft = request($deleteDraftPagePath, 'limited');
    httpCheck(!str_contains($limitedReviewDraft['body'], 'name="draft_action"') && !str_contains($limitedReviewDraft['body'], 'id="eds-delete-draft-form"'), 'Limited has no save, continue-to-issuance or delete actions');
    login('technician');
    $technicianReviewDraft = request($deleteDraftPagePath, 'technician');
    httpCheck(substr_count($technicianReviewDraft['body'], 'name="draft_action"') === 2, 'Technician sees both draft save actions');
    login('admin');
    $cleanupReviewFields = hiddenFields(request($deleteDraftPagePath, 'admin')['body']);
    $cleanupReviewDelete = request($deleteDraftPath, 'admin', array_merge($cleanupReviewFields, ['confirm_delete'=>'1']));
    httpCheck($cleanupReviewDelete['status'] === 302 && (int) $pdo->query('SELECT COUNT(*) FROM eds_warranty_cards_drafts WHERE work_order_id=3')->fetchColumn() === 0, 'draft created through save-and-review remains deletable');
    httpCheck($pdo->query('SELECT next_number FROM eds_warranty_cards_counter')->fetchColumn() === $reviewCounterBefore, 'cleanup deletion after save-and-review leaves the counter unchanged');

    $frozenIssuerPost = issuerHttpPost([
        'eds_warranty_cards_issuer_service_name' => 'HTTP Warranty Service',
        'eds_warranty_cards_issuer_legal_name' => 'HTTP Legal Company Ltd.',
        'eds_warranty_cards_issuer_registration_number' => 'HTTP-REG-42',
        'eds_warranty_cards_issuer_representative' => 'HTTP Representative',
        'eds_warranty_cards_issuer_address' => "HTTP Issuer Address\nSecond line",
        'eds_warranty_cards_issuer_phone' => '+359 2 424 242',
        'eds_warranty_cards_issuer_email' => 'warranty@example.test',
        'eds_warranty_cards_issuer_website' => 'https://warranty.example.test',
        'eds_warranty_cards_issuer_logo_url' => '/module-warranty-logo.svg',
    ]);
    $issuerCounterBefore = $pdo->query('SELECT next_number FROM eds_warranty_cards_counter')->fetchColumn();
    $customIssuerPage = request($settingsPath, 'admin');
    $customIssuerSave = request($settingsPath, 'admin', array_merge(hiddenFields($customIssuerPage['body']), $frozenIssuerPost, [
        'eds_warranty_cards_next_number' => $issuerCounterBefore,
        'eds_warranty_cards_terms_html' => $storedTerms,
    ]));
    httpCheck($customIssuerSave['status'] === 200 && storedIssuer($pdo)['issuer_service_name'] === 'HTTP Warranty Service', 'module issuer values save before issuance');
    httpCheck($pdo->query('SELECT next_number FROM eds_warranty_cards_counter')->fetchColumn() === $issuerCounterBefore, 'saving issuer details does not alter the next card number');

    $fields = hiddenFields($draft['body']);
    $limitCounterBefore = $pdo->query('SELECT next_number FROM eds_warranty_cards_counter')->fetchColumn();
    $overlong = $fields + [
        'manual_parts' => ['m_12121212121212121212121212121212' => ['name'=>str_repeat('я', 129),'serial_number'=>'','months'=>'','supplier'=>'','supplier_card'=>'']],
    ];
    $overlongResponse = request($path, 'admin', $overlong);
    httpCheck($overlongResponse['status'] === 200 && str_contains($overlongResponse['body'], 'най-много 128 знака')
        && str_contains($overlongResponse['body'], 'value="' . str_repeat('я', 129) . '"'), 'overlong Unicode draft value is rejected with its concrete message and retained safely');
    $tooManyEmptyRows = $fields + ['manual_parts' => []];
    for ($i = 0; $i < 101; $i++) {
        $tooManyEmptyRows['manual_parts']['m_' . str_pad(dechex($i), 32, '0', STR_PAD_LEFT)] = ['name'=>''];
    }
    $tooManyRowsResponse = request($path, 'admin', $tooManyEmptyRows);
    httpCheck($tooManyRowsResponse['status'] === 200 && str_contains($tooManyRowsResponse['body'], 'най-много 100 части'), 'forged HTTP request with 101 empty manual rows is rejected before row processing');
    httpCheck((int) $pdo->query('SELECT COUNT(*) FROM eds_warranty_cards_drafts WHERE work_order_id=1')->fetchColumn() === 0
        && $pdo->query('SELECT next_number FROM eds_warranty_cards_counter')->fetchColumn() === $limitCounterBefore, 'HTTP limit rejections preserve draft absence and counter');
    $invalid = $fields + [
        'units' => ['502_1' => ['selected' => '1', 'serial_number' => 'MY-SERIAL', 'months' => '-1', 'supplier' => 'INTERNAL', 'supplier_card' => 'CARD-X']],
        'manual_parts' => ['m_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' => ['name'=>'Manual typed','serial_number'=>'MANUAL-TYPED','months'=>'-2','supplier'=>'MANUAL-INTERNAL','supplier_card'=>'MANUAL-CARD']],
        'work_items' => ['w_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' => ['description'=>'<script>MY-WORK</script>','months'=>'']]
    ];
    $invalid['draft_action'] = 'save_and_review';
    $response = request($path, 'admin', $invalid);
    httpCheck($response['status'] === 200 && !isset($response['headers']['location']) && str_contains($response['body'], 'положителен брой месеци'), 'save-and-review validation failure stays in the editor');
    httpCheck(str_contains($response['body'], 'value="MY-SERIAL"') && str_contains($response['body'], 'value="-1"'), 'error retains typed values');
    httpCheck(str_contains($response['body'], 'value="MANUAL-TYPED"') && str_contains($response['body'], 'value="Manual typed"'), 'error retains manual row values');
    httpCheck(str_contains($response['body'], '&lt;script&gt;MY-WORK&lt;/script&gt;'), 'error output escapes text');
    httpCheck((int) $pdo->query('SELECT COUNT(*) FROM eds_warranty_cards_drafts')->fetchColumn() === 0
        && $pdo->query('SELECT next_number FROM eds_warranty_cards_counter')->fetchColumn() === $reviewCounterBefore, 'validation error writes nothing and does not change the counter');
    $valid = $invalid; $valid['units']['502_1']['months'] = ''; $valid['manual_parts']['m_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa']['months'] = '';
    $response = request($path, 'admin', $valid);
    httpCheck($response['status'] === 302 && str_ends_with($response['headers']['location'], '/work-orders/view/1/eds-warranty-card'), 'incomplete draft saves all row types and redirects to preview');
    $content = $pdo->query('SELECT content FROM eds_warranty_cards_drafts WHERE work_order_id=1')->fetchColumn();
    $savedDraftContent = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    httpCheck($savedDraftContent['work_items']['w_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa']['description'] === '<script>MY-WORK</script>' && $savedDraftContent['work_items']['w_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa']['months'] === '', 'partial work activity is saved in the draft');
    $response = request($path, 'admin', $valid);
    httpCheck(str_contains($response['body'], 'променена от друг потребител'), 'stale edit rejected by controller');
    httpCheck(str_contains($response['body'], 'value="MY-SERIAL"') && str_contains($response['body'], '&lt;script&gt;MY-WORK&lt;/script&gt;'), 'conflict retains part and work activity entries');
    httpCheck(!str_contains($response['body'], '>Запази черновата</button>'), 'conflict cannot silently rebase');
    httpCheck($pdo->query('SELECT content FROM eds_warranty_cards_drafts WHERE work_order_id=1')->fetchColumn() === $content, 'conflict leaves saved draft unchanged');
    $task = request('/work-orders/view/1', 'admin');
    httpCheck(str_contains($task['body'], 'Редактирай черновата'), 'task switches to edit button');
    $fresh = request($path, 'admin'); $fields = hiddenFields($fresh['body']);
    $badCsrf = $fields; $badCsrf['csrf_token'] = 'BAD';
    $badCsrf['units'] = ['502_1' => ['selected' => '1', 'serial_number' => 'CSRF-INPUT']];
    $badCsrf['work_items'] = ['w_dddddddddddddddddddddddddddddddd' => ['description'=>'CSRF-WORK','months'=>'7']];
    $response = request($path, 'admin', $badCsrf);
    httpCheck(str_contains($response['body'], 'value="CSRF-INPUT"') && str_contains($response['body'], 'value="CSRF-WORK"'), 'CSRF error retains form');
    httpCheck($pdo->query('SELECT content FROM eds_warranty_cards_drafts WHERE work_order_id=1')->fetchColumn() === $content, 'CSRF rejects write');
    login('limited');
    $limitedSettings = request($settingsPath, 'limited');
    httpCheck($limitedSettings['status'] === 302 && str_ends_with($limitedSettings['headers']['location'], '/403'), 'Limited cannot open module settings');
    $limited = request($path, 'limited');
    httpCheck($limited['status'] === 200 && str_contains($limited['body'], '<fieldset disabled>'), 'Limited gets read-only draft');
    $settings->setSetting('enabled_modules', '["warranty","eds-warranty-cards"]');
    $limitedTask = request('/work-orders/view/1', 'limited');
    httpCheck(!str_contains($limitedTask['body'], '>Гаранция</h2>') && substr_count($limitedTask['body'], '>Гаранционна карта</h2>') === 1, 'Limited keeps warranty card when author Warranty section has nothing to show');
    $settings->setSetting('enabled_modules', '["eds-warranty-cards"]');
    httpCheck(!str_contains($limited['body'], 'id="eds-add-manual-part"'), 'Limited has no manual add control');
    $limitedDraftDom = new DOMDocument(); @$limitedDraftDom->loadHTML('<?xml encoding="UTF-8">' . $limited['body']);
    $limitedDraftXpath = new DOMXPath($limitedDraftDom);
    httpCheck($limitedDraftXpath->query('//button[@id="eds-add-work-item" or @data-eds-remove-work]')->length === 0, 'Limited has no work activity add or remove controls');
    $denied = request($path, 'limited', hiddenFields($limited['body']));
    httpCheck($denied['status'] === 302 && str_ends_with($denied['headers']['location'], '/403'), 'Limited POST denied');
    httpCheck($pdo->query('SELECT content FROM eds_warranty_cards_drafts WHERE work_order_id=1')->fetchColumn() === $content, 'Limited changes nothing');
    login('technician');
    $technicianSettings = request($settingsPath, 'technician');
    httpCheck($technicianSettings['status'] === 302 && str_ends_with($technicianSettings['headers']['location'], '/403'), 'Technician cannot open module settings');
    $tech = request($path, 'technician');
    $fields = hiddenFields($tech['body']);
    $techSave = $fields + [
        'units' => ['502_2' => ['selected' => '1', 'serial_number' => 'TECH-SERIAL', 'months' => '24', 'supplier' => 'HTTP-SECRET-SUPPLIER', 'supplier_card' => 'HTTP-SECRET-CARD']],
        'manual_parts' => [
            'm_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb' => ['name'=>'HTTP Manual Part','serial_number'=>'HTTP-MANUAL-A','months'=>'36','supplier'=>'HTTP-MANUAL-SECRET','supplier_card'=>'HTTP-MANUAL-CARD'],
            'm_cccccccccccccccccccccccccccccccc' => ['name'=>'HTTP Manual Part','serial_number'=>'HTTP-MANUAL-B','months'=>'48','supplier'=>'HTTP-SECOND-SECRET','supplier_card'=>'HTTP-SECOND-CARD'],
        ],
        'work_items' => [
            'w_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb' => ['description'=>'HTTP activity','months'=>'1'],
            'w_cccccccccccccccccccccccccccccccc' => ['description'=>'HTTP activity','months'=>'2'],
        ],
    ];
    httpCheck(request($path, 'technician', $techSave)['status'] === 302, 'Technician can edit');
    httpCheck((int) $pdo->query('SELECT revision FROM eds_warranty_cards_drafts')->fetchColumn() === 2, 'controller revision progresses');
    httpCheck($pdo->query('SELECT next_number FROM eds_warranty_cards_counter')->fetchColumn() === '00004', 'HTTP draft operations never change counter');
    httpCheck($pdo->query('SELECT description FROM work_orders WHERE id=1')->fetchColumn() === 'Problem', 'task not modified');
    httpCheck((int) $pdo->query('SELECT SUM(quantity) FROM work_order_products')->fetchColumn() === 3, 'inventory not modified');
    $pdo->exec('DROP TABLE work_order_products');
    $withoutInventory = request($path, 'admin');
    httpCheck($withoutInventory['status'] === 200 && str_contains($withoutInventory['body'], 'няма налични складови позиции'), 'missing inventory table renders a normal empty-state message');
    httpCheck(str_contains($withoutInventory['body'], 'SSD 500 GB') && str_contains($withoutInventory['body'], 'запазена в черновата'), 'saved inventory rows remain visible without inventory table');
    httpCheck(str_contains($withoutInventory['body'], 'HTTP Manual Part') && str_contains($withoutInventory['body'], 'HTTP-MANUAL-A'), 'manual rows remain visible without inventory table');
    $missingFields = hiddenFields($withoutInventory['body']);
    $saveWithoutInventory = $missingFields + [
        'units' => ['502_2' => ['selected'=>'1','serial_number'=>'TECH-SERIAL','months'=>'24','supplier'=>'HTTP-SECRET-SUPPLIER','supplier_card'=>'HTTP-SECRET-CARD']],
        'manual_parts' => $techSave['manual_parts'],
        'work_items' => $techSave['work_items'],
    ];
    httpCheck(request($path, 'admin', $saveWithoutInventory)['status'] === 302, 'existing draft saves while inventory table is missing');
    $storedWithoutInventory = json_decode($pdo->query('SELECT content FROM eds_warranty_cards_drafts WHERE work_order_id=1')->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
    httpCheck($storedWithoutInventory['units']['502_2']['selected'] && count($storedWithoutInventory['manual_parts']) === 2 && count($storedWithoutInventory['work_items']) === 2, 'stock, manual and work activity draft rows survive missing inventory data');

    $cardPath = '/work-orders/view/1/eds-warranty-card';
    $printPath = $cardPath . '/print';
    $unauthPrint = request($printPath);
    httpCheck($unauthPrint['status'] === 302 && str_ends_with($unauthPrint['headers']['location'], '/login'), 'unauthenticated print denied');
    $draftPrint = request($printPath, 'admin');
    httpCheck($draftPrint['status'] === 302 && str_ends_with($draftPrint['headers']['location'], '/404'), 'draft cannot be printed');
    $preview = request($cardPath, 'admin');
    if ($preview['status'] !== 200 || !str_contains($preview['body'], 'Преглед преди издаване')) {
        fwrite(STDERR, "Unexpected card preview response (HTTP {$preview['status']}):\n" . substr(strip_tags($preview['body']), 0, 1200) . "\n");
    }
    httpCheck($preview['status'] === 200 && str_contains($preview['body'], 'Преглед преди издаване'), 'admin sees customer preview');
    httpCheck(str_contains($preview['body'], 'Издай гаранционна карта') && str_contains($preview['body'], 'не може да се редактира'), 'admin sees issue warning and button');
    httpCheck(!str_contains($preview['body'], '>Печат<'), 'draft preview has no print button');
    httpCheck(str_contains($preview['body'], 'HTTP-SECRET-SUPPLIER'), 'admin preview includes internal data');
    httpCheck(str_contains($preview['body'], 'HTTP Manual Part') && str_contains($preview['body'], 'HTTP-MANUAL-A') && str_contains($preview['body'], 'HTTP-MANUAL-SECRET'), 'manual customer and internal data appear in staff preview');
    httpCheck(substr_count($preview['body'], 'HTTP activity') === 2 && str_contains($preview['body'], '1 месец') && str_contains($preview['body'], '2 месеца'), 'customer preview localizes singular and plural work-activity periods');
    httpCheck(str_contains($preview['body'], 'Гаранционни условия') && str_contains($preview['body'], '<strong>документа</strong>') && !str_contains($preview['body'], 'alert(1)'), 'customer preview shows current sanitized warranty terms');
    httpCheck(str_contains($preview['body'], 'HTTP Warranty Service') && str_contains($preview['body'], 'HTTP Legal Company Ltd.')
        && str_contains($preview['body'], 'HTTP-REG-42') && str_contains($preview['body'], 'HTTP Representative')
        && str_contains($preview['body'], 'src="/module-warranty-logo.svg"'), 'customer preview shows effective module issuer details and safe logo');
    $previewDom = new DOMDocument(); @$previewDom->loadHTML('<?xml encoding="UTF-8">' . $preview['body']);
    $previewXpath = new DOMXPath($previewDom);
    $previewDocument = $previewXpath->query('//*[@id="eds-warranty-client-document"]')->item(0);
    httpCheck($previewDocument && str_contains($previewDocument->textContent, 'HTTP Warranty Service')
        && !str_contains($previewDocument->textContent, 'HTTP Fallback Service'), 'module issuer values take precedence over general fallbacks inside the client document');
    httpCheck($previewDocument && str_contains($previewDocument->textContent, '№ —') && str_contains($previewDocument->textContent, 'Дата на издаване: —')
        && !str_contains($previewDocument->textContent, '00004'), 'pre-issuance document shows placeholders and does not reveal or reserve the next number');
    httpCheck($previewXpath->query('//*[@id="eds-warranty-client-document"]//*[@class="eds-warranty-items"]//tbody/tr')->length === 5
        && $previewXpath->query('//*[@id="eds-warranty-client-document"]//*[@class="eds-warranty-rule"]')->length === 1, 'preview uses the unified numbered table and header rule');
    httpCheck($previewXpath->query('//*[@id="eds-warranty-client-document" and @data-eds-warranty-pagination]/*[contains(concat(" ", normalize-space(@class), " "), " eds-warranty-document-source ")]')->length === 1
        && $previewXpath->query('//script[contains(@src, "/eds-warranty-cards/assets/warranty-card-pagination.js?v=0.10.0")]')->length === 1, 'preview exposes one progressive-enhancement source and the versioned local paginator');
    $previewBrand = $previewXpath->query('//*[@id="eds-warranty-client-document"]//*[@class="eds-warranty-header__brand"]')->item(0);
    $previewService = $previewXpath->query('//*[@id="eds-warranty-client-document"]//*[@class="eds-warranty-header__service"]')->item(0);
    $previewParties = $previewXpath->query('//*[@id="eds-warranty-client-document"]//*[@class="eds-warranty-party"]');
    httpCheck($previewBrand && str_contains($previewBrand->textContent, 'https://warranty.example.test')
        && $previewXpath->query('//*[@id="eds-warranty-client-document"]//*[@class="eds-warranty-header__brand"]//img[@src="/module-warranty-logo.svg"]')->length === 1
        && $previewService && str_contains($previewService->textContent, 'HTTP Warranty Service') && str_contains($previewService->textContent, '+359 2 424 242'), 'preview header places logo and URL left and service name and phone right');
    httpCheck($previewParties->length === 2 && str_contains($previewParties->item(0)->textContent, 'Test Customer')
        && str_contains($previewParties->item(0)->textContent, '1234567')
        && !str_contains($previewParties->item(0)->textContent, 'Фирма:') && !str_contains($previewParties->item(0)->textContent, 'Имейл:'), 'customer column omits its empty company and email fields');
    httpCheck($previewParties->length === 2 && str_contains($previewParties->item(1)->textContent, 'Издател')
        && $previewXpath->query('//*[@id="eds-warranty-client-document"]//*[@class="eds-warranty-parties"]/*[@class="eds-warranty-party"][2]/*[@class="eds-warranty-issuer-name" and normalize-space(.)="HTTP Legal Company Ltd."]')->length === 1
        && !str_contains($previewParties->item(1)->textContent, 'Юридическо име')
        && !str_contains($previewParties->item(1)->textContent, 'Уебсайт:'), 'preview shows the legal issuer name directly and does not repeat the website in its column');
    httpCheck($previewDocument && !str_contains($previewDocument->textContent, 'WO-TEST')
        && !str_contains($previewDocument->textContent, 'Problem') && !str_contains($previewDocument->textContent, 'PC')
        && !str_contains($previewDocument->textContent, 'HTTP-SECRET') && !str_contains($previewDocument->textContent, 'HTTP-MANUAL-SECRET'), 'client preview excludes work-order, device and internal supplier data');
    $limitedPreview = request($cardPath, 'limited');
    httpCheck($limitedPreview['status'] === 200 && !str_contains($limitedPreview['body'], 'Издай гаранционна карта'), 'Limited preview has no issue action');
    httpCheck(!str_contains($limitedPreview['body'], 'HTTP-SECRET'), 'Limited preview excludes internal fields');
    httpCheck(str_contains($limitedPreview['body'], 'HTTP Manual Part') && str_contains($limitedPreview['body'], 'HTTP-MANUAL-A'), 'Limited preview includes only public manual part fields');
    $limitedPost = request($cardPath, 'limited', hiddenFields($limitedPreview['body']) + ['confirm_issue'=>'1']);
    httpCheck($limitedPost['status'] === 302 && str_ends_with($limitedPost['headers']['location'], '/403'), 'Limited cannot issue');
    httpCheck((int) $pdo->query('SELECT COUNT(*) FROM eds_warranty_cards_issued')->fetchColumn() === 0, 'Limited issue writes nothing');

    $issueFields = hiddenFields($preview['body']);
    $pdo->exec("UPDATE customers SET name='HTTP Changed Customer' WHERE id=1");
    $changed = request($cardPath, 'admin', $issueFields + ['confirm_issue'=>'1']);
    httpCheck($changed['status'] === 200 && str_contains($changed['body'], 'променено след прегледа'), 'source change invalidates confirmation');
    httpCheck((int) $pdo->query('SELECT COUNT(*) FROM eds_warranty_cards_issued')->fetchColumn() === 0 && $pdo->query('SELECT next_number FROM eds_warranty_cards_counter')->fetchColumn() === '00004', 'changed preview leaves card and counter untouched');
    $issueFields = hiddenFields($changed['body']);
    $unconfirmed = request($cardPath, 'admin', $issueFields);
    httpCheck($unconfirmed['status'] === 200 && str_contains($unconfirmed['body'], 'Потвърдете окончателното'), 'server requires explicit confirmation');
    httpCheck((int) $pdo->query('SELECT COUNT(*) FROM eds_warranty_cards_issued')->fetchColumn() === 0, 'unconfirmed request writes nothing');
    $badIssueCsrf = $issueFields + ['confirm_issue'=>'1'];
    $badIssueCsrf['csrf_token'] = 'BAD';
    $csrfIssue = request($cardPath, 'admin', $badIssueCsrf);
    httpCheck($csrfIssue['status'] === 200 && (int) $pdo->query('SELECT COUNT(*) FROM eds_warranty_cards_issued')->fetchColumn() === 0, 'CSRF rejects issuance');
    $issued = request($cardPath, 'admin', $issueFields + ['confirm_issue'=>'1']);
    httpCheck($issued['status'] === 302, 'confirmed card issues with redirect');
    httpCheck($pdo->query('SELECT card_number FROM eds_warranty_cards_issued WHERE work_order_id=1')->fetchColumn() === '00004', 'controller assigns exact formatted number');
    httpCheck($pdo->query('SELECT next_number FROM eds_warranty_cards_counter')->fetchColumn() === '00005', 'controller increments counter once');
    $newIssuedSnapshot = json_decode($pdo->query('SELECT public_content FROM eds_warranty_cards_issued WHERE work_order_id=1')->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
    httpCheck(($newIssuedSnapshot['issuer']['service_name'] ?? '') === 'HTTP Warranty Service'
        && ($newIssuedSnapshot['issuer']['legal_name'] ?? '') === 'HTTP Legal Company Ltd.'
        && ($newIssuedSnapshot['issuer']['logo_url'] ?? '') === '/module-warranty-logo.svg'
        && array_keys($newIssuedSnapshot) === ['issuer', 'customer', 'parts', 'work_warranties', 'terms_html']
        && !isset($newIssuedSnapshot['company'], $newIssuedSnapshot['device'], $newIssuedSnapshot['work_order'], $newIssuedSnapshot['card_number'], $newIssuedSnapshot['issued_at']), 'new public snapshot contains only the required frozen customer-document data');
    $repeat = request($cardPath, 'admin', $issueFields + ['confirm_issue'=>'1']);
    httpCheck($repeat['status'] === 302 && $pdo->query('SELECT next_number FROM eds_warranty_cards_counter')->fetchColumn() === '00005', 'repeat POST returns existing card without increment');
    httpCheck((int) $pdo->query('SELECT COUNT(*) FROM eds_warranty_cards_issued')->fetchColumn() === 1, 'repeat POST creates no second card');
    $issuedBeforeDeleteRequest = $pdo->query('SELECT public_content FROM eds_warranty_cards_issued WHERE work_order_id=1')->fetchColumn();
    $issuedDeleteRequest = request($path . '/delete', 'admin', [
        'csrf_token' => $issueFields['csrf_token'],
        'revision' => $issueFields['revision'],
        'confirm_delete' => '1',
    ]);
    httpCheck($issuedDeleteRequest['status'] === 302 && str_ends_with($issuedDeleteRequest['headers']['location'], '/eds-warranty-card'), 'manual deletion request after issuance redirects to the immutable card');
    $issuedDeleteView = request($cardPath, 'admin');
    httpCheck(str_contains($issuedDeleteView['body'], 'Издадената гаранционна карта не може да бъде изтрита.') && !str_contains($issuedDeleteView['body'], 'id="eds-delete-draft-form"'), 'issued card shows a clear refusal and no draft delete action');
    httpCheck($pdo->query('SELECT public_content FROM eds_warranty_cards_issued WHERE work_order_id=1')->fetchColumn() === $issuedBeforeDeleteRequest
        && $pdo->query('SELECT next_number FROM eds_warranty_cards_counter')->fetchColumn() === '00005', 'issued-card deletion request preserves frozen content and counter');

    $settingsAfterIssue = request($settingsPath, 'admin');
    $settingsAfterFields = hiddenFields($settingsAfterIssue['body']);
    $changedIssuerPost = issuerHttpPost([
        'eds_warranty_cards_issuer_service_name' => 'Changed Module Service',
        'eds_warranty_cards_issuer_legal_name' => 'Changed Legal Company',
        'eds_warranty_cards_issuer_registration_number' => 'CHANGED-REG',
        'eds_warranty_cards_issuer_representative' => 'Changed Representative',
        'eds_warranty_cards_issuer_address' => 'Changed Module Address',
        'eds_warranty_cards_issuer_phone' => '999',
        'eds_warranty_cards_issuer_email' => 'changed@example.test',
        'eds_warranty_cards_issuer_website' => 'https://changed.example.test',
        'eds_warranty_cards_issuer_logo_url' => '/changed-logo.svg',
    ]);
    $changedSettings = request($settingsPath, 'admin', array_merge($settingsAfterFields, $changedIssuerPost, ['eds_warranty_cards_next_number'=>'00005','eds_warranty_cards_terms_html'=>'<p>НОВИ ТЕКУЩИ УСЛОВИЯ</p>']));
    foreach (['company_name'=>'Changed General Service','company_address'=>'Changed General Address','company_phone'=>'888','company_email'=>'general-changed@example.test','company_website'=>'https://general-changed.example.test','company_logo_url'=>'/general-changed.svg'] as $key => $value) {
        $settings->setSetting($key, $value);
    }
    httpCheck($changedSettings['status'] === 200 && storedTerms($pdo) === '<p>НОВИ ТЕКУЩИ УСЛОВИЯ</p>'
        && storedIssuer($pdo)['issuer_service_name'] === 'Changed Module Service', 'Admin can change terms and issuer settings after issuance');

    $limitedIssued = request($cardPath, 'limited');
    httpCheck(str_contains($limitedIssued['body'], '00004') && str_contains($limitedIssued['body'], 'HTTP Changed Customer'), 'Limited sees issued customer snapshot');
    httpCheck(!str_contains($limitedIssued['body'], 'HTTP-SECRET'), 'Limited issued view excludes internal snapshot');
    $adminIssued = request($cardPath, 'admin');
    httpCheck(str_contains($adminIssued['body'], 'HTTP-SECRET-SUPPLIER'), 'authorized staff sees issued internal snapshot');
    httpCheck(str_contains($adminIssued['body'], 'HTTP-MANUAL-SECRET'), 'authorized staff sees frozen manual internal data');
    httpCheck(str_contains($adminIssued['body'], '<strong>документа</strong>') && !str_contains($adminIssued['body'], 'НОВИ ТЕКУЩИ УСЛОВИЯ'), 'issued view keeps frozen terms after setting change');
    $adminIssuedDom = new DOMDocument(); @$adminIssuedDom->loadHTML('<?xml encoding="UTF-8">' . $adminIssued['body']);
    $adminIssuedXpath = new DOMXPath($adminIssuedDom);
    $adminIssuedDocument = $adminIssuedXpath->query('//*[@id="eds-warranty-client-document"]')->item(0);
    httpCheck($adminIssuedDocument && str_contains($adminIssuedDocument->textContent, 'HTTP Warranty Service')
        && str_contains($adminIssuedDocument->textContent, 'HTTP Legal Company Ltd.')
        && !str_contains($adminIssuedDocument->textContent, 'Changed Module Service')
        && !str_contains($adminIssuedDocument->textContent, 'Changed General Service')
        && $adminIssuedXpath->query('//*[@id="eds-warranty-client-document"]//img[@src="/module-warranty-logo.svg"]')->length === 1, 'issued view keeps the frozen effective issuer after module and general settings change');
    httpCheck(str_contains($adminIssued['body'], '>Печат<'), 'Admin sees print button for issued card');
    httpCheck(!str_contains($limitedIssued['body'], '>Печат<'), 'Limited has no administrative print button');
    $technicianIssued = request($cardPath, 'technician');
    httpCheck(str_contains($technicianIssued['body'], '>Печат<'), 'Technician sees print button');
    $pdo->exec("UPDATE customers SET name='After Issue Customer' WHERE id=1");
    $pdo->exec("UPDATE work_orders SET computer='After Issue PC' WHERE id=1");
    $frozen = request($cardPath, 'admin');
    httpCheck(str_contains($frozen['body'], 'HTTP Changed Customer') && !str_contains($frozen['body'], 'After Issue Customer'), 'issued view ignores later customer changes');
    httpCheck(!str_contains($frozen['body'], 'After Issue PC'), 'issued view ignores later device changes');

    $settings->setSetting('print_language', 'bg-bg');
    $printAdmin = request($printPath, 'admin');
    httpCheck($printAdmin['status'] === 200 && str_contains($printAdmin['body'], '>ГАРАНЦИОННА КАРТА</h1>'), 'issued card has Bulgarian print view');
    httpCheck(str_contains($printAdmin['headers']['cache-control'], 'no-store') && ($printAdmin['headers']['x-robots-tag'] ?? '') === 'noindex, nofollow', 'print response is private and noindex');
    httpCheck(str_contains($printAdmin['body'], '00004') && str_contains($printAdmin['body'], 'HTTP Changed Customer'), 'print uses frozen issued number and customer');
    httpCheck(str_contains($printAdmin['body'], 'HTTP Manual Part') && str_contains($printAdmin['body'], 'HTTP-MANUAL-A') && str_contains($printAdmin['body'], 'HTTP-MANUAL-B'), 'print includes frozen manual parts');
    $printAdminDom = new DOMDocument(); @$printAdminDom->loadHTML('<?xml encoding="UTF-8">' . $printAdmin['body']);
    $printAdminXpath = new DOMXPath($printAdminDom);
    $printAdminDocument = $printAdminXpath->query('//*[@id="eds-warranty-client-document"]')->item(0);
    httpCheck(substr_count($printAdmin['body'], 'HTTP activity') === 2
        && str_contains($printAdmin['body'], '1 месец') && str_contains($printAdmin['body'], '2 месеца')
        && $printAdminXpath->query('//*[@class="eds-warranty-items"]//tbody/tr')->length === 5, 'print includes all parts and localized work activities in the unified table');
    httpCheck(str_contains($printAdmin['body'], 'Гаранционни условия') && str_contains($printAdmin['body'], '<strong>документа</strong>') && !str_contains($printAdmin['body'], 'НОВИ ТЕКУЩИ УСЛОВИЯ'), 'print uses frozen sanitized terms');
    httpCheck(str_contains($printAdmin['body'], 'HTTP Warranty Service') && str_contains($printAdmin['body'], 'HTTP Legal Company Ltd.')
        && str_contains($printAdmin['body'], 'HTTP-REG-42') && str_contains($printAdmin['body'], 'HTTP Representative')
        && str_contains($printAdmin['body'], 'src="/module-warranty-logo.svg"')
        && !str_contains($printAdmin['body'], 'Changed Module Service') && !str_contains($printAdmin['body'], 'Changed General Service'), 'repeat print uses only the frozen issuer snapshot');
    httpCheck(!str_contains($printAdmin['body'], 'After Issue Customer') && !str_contains($printAdmin['body'], 'After Issue PC'), 'repeat print ignores later source changes');
    httpCheck($printAdminDocument && !str_contains($printAdminDocument->textContent, 'WO-TEST')
        && !str_contains($printAdminDocument->textContent, 'Problem') && !str_contains($printAdminDocument->textContent, 'After Issue PC'), 'print customer document contains no work-order or device fields');
    httpCheck(!str_contains($printAdmin['body'], 'HTTP-SECRET') && !str_contains($printAdmin['body'], 'HTTP-MANUAL-SECRET') && !str_contains($printAdmin['body'], 'HTTP-SECOND-SECRET') && !preg_match('/<(button|form|nav)\b/i', $printAdmin['body']), 'print HTML has no internal data or controls');
    httpCheck($printAdminXpath->query('//link[contains(@href, "/eds-warranty-cards/assets/warranty-card-document.css")]')->length === 1
        && $printAdminXpath->query('//script[contains(@src, "/eds-warranty-cards/assets/warranty-card-pagination.js?v=0.10.0")]')->length === 1
        && $printAdminXpath->query('//*[@class="eds-warranty-signatures"]')->length === 1
        && str_contains($printAdmin['body'], 'window.edsWarrantyPaginationReady'), 'print uses shared local pagination, waits for it and includes the signature block');
    $printLimited = request($printPath, 'limited');
    httpCheck($printLimited['status'] === 200 && hash('sha256', $printLimited['body']) === hash('sha256', $printAdmin['body']), 'Limited receives the same public print document');
    $printPost = request($printPath, 'admin', []);
    httpCheck($printPost['status'] === 405, 'print route accepts GET only');
    $missingPrint = request('/work-orders/view/999/eds-warranty-card/print', 'admin');
    httpCheck($missingPrint['status'] === 302 && str_ends_with($missingPrint['headers']['location'], '/404'), 'missing or foreign card gets safe response');
    $settings->setSetting('print_language', 'en-us');
    $printEnglish = request($printPath, 'admin');
    httpCheck(str_contains($printEnglish['body'], '>WARRANTY CARD</h1>') && str_contains($printEnglish['body'], 'Issue date:')
        && str_contains($printEnglish['body'], '1 month') && str_contains($printEnglish['body'], '2 months'), 'configured English print language and month pluralization applied');
    httpCheck(str_contains($printEnglish['body'], 'Warranty terms') && str_contains($printEnglish['body'], 'Пазете <strong>документа</strong>'), 'English print translates label without translating terms content');
    $settings->setSetting('print_language', 'bg-bg');

    $issuedPublic = json_decode($pdo->query('SELECT public_content FROM eds_warranty_cards_issued WHERE work_order_id=1')->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
    unset($issuedPublic['terms_html']);
    unset($issuedPublic['issuer']);
    $issuedPublic['company'] = [
        'company_name' => 'HTTP Legacy Frozen Service',
        'company_address' => 'HTTP Legacy Frozen Address',
        'company_phone' => '777',
        'company_email' => 'legacy-frozen@example.test',
        'company_website' => 'https://legacy-frozen.example.test',
        'company_logo_url' => '',
    ];
    $issuedPublic['device'] = [
        'computer' => 'LEGACY DEVICE SECRET', 'model' => 'LEGACY MODEL SECRET',
        'serial_number' => 'LEGACY SERIAL SECRET', 'imei' => 'LEGACY IMEI SECRET',
        'remarks' => 'LEGACY REMARK SECRET', 'accessories' => ['LEGACY ACCESSORY SECRET'],
    ];
    $issuedPublic['work_order'] = [
        'number' => 'LEGACY ORDER SECRET', 'description' => 'LEGACY PROBLEM SECRET',
        'resolution' => 'LEGACY RESOLUTION SECRET', 'status' => 'LEGACY STATUS SECRET',
        'priority' => 'LEGACY PRIORITY SECRET', 'created_at' => '2020-01-01 00:00:00',
    ];
    $issuedPublic['work_warranty'] = $issuedPublic['work_warranties'][0];
    unset($issuedPublic['work_warranties']);
    $issuedPublic['card_number'] = '900000';
    $issuedPublic['issued_at'] = '2026-09-19 12:00:00';
    $legacyInsert = $pdo->prepare('INSERT INTO eds_warranty_cards_issued (work_order_id,card_number,number_key,issued_at,issued_by,public_content,internal_content) VALUES (2,?,?,?,?,?,?)');
    $legacyInsert->execute(['900000', hash('sha256', '900000', true), '2026-09-19 12:00:00', 1, json_encode($issuedPublic, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), '{"parts":[]}']);
    $legacyView = request('/work-orders/view/2/eds-warranty-card', 'admin');
    httpCheck($legacyView['status'] === 200 && substr_count($legacyView['body'], 'HTTP activity') === 1
        && str_contains($legacyView['body'], 'HTTP Legacy Frozen Service')
        && !str_contains($legacyView['body'], 'Changed Legal Company') && !str_contains($legacyView['body'], 'CHANGED-REG')
        && !preg_match('/LEGACY (?:DEVICE|MODEL|SERIAL|IMEI|REMARK|ACCESSORY|ORDER|PROBLEM|RESOLUTION|STATUS|PRIORITY) SECRET/', $legacyView['body']), 'legacy issued-card view ignores old task/device data and current module legal issuer fields');
    $legacyPrint = request('/work-orders/view/2/eds-warranty-card/print', 'admin');
    httpCheck($legacyPrint['status'] === 200 && substr_count($legacyPrint['body'], 'HTTP activity') === 1
        && str_contains($legacyPrint['body'], 'HTTP Legacy Frozen Service') && str_contains($legacyPrint['body'], 'HTTP Legacy Frozen Address')
        && !str_contains($legacyPrint['body'], 'Changed Module Service') && !str_contains($legacyPrint['body'], 'Changed Legal Company')
        && !preg_match('/LEGACY (?:DEVICE|MODEL|SERIAL|IMEI|REMARK|ACCESSORY|ORDER|PROBLEM|RESOLUTION|STATUS|PRIORITY) SECRET/', $legacyPrint['body'])
        && !str_contains($legacyPrint['body'], 'id="warranty-terms"') && !str_contains($legacyPrint['body'], 'НОВИ ТЕКУЩИ УСЛОВИЯ'), 'legacy issued card uses only its old frozen company and receives no current issuer or terms automatically');
    $legacyStored = json_decode($pdo->query('SELECT public_content FROM eds_warranty_cards_issued WHERE work_order_id=2')->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
    httpCheck(isset($legacyStored['work_warranty']) && !isset($legacyStored['work_warranties']) && !isset($legacyStored['issuer']), 'reading and printing a legacy issued card never rewrites its frozen content');

    // The registry reads only frozen issued snapshots. Populate exactly 51 issued
    // rows in this disposable database to exercise SQL search and pagination.
    $pdo->exec("UPDATE eds_warranty_cards_issued SET issued_at='2026-10-01 12:00:00', public_content=JSON_SET(public_content, '$.customer.phone', 'REGSEARCH-0001') WHERE work_order_id=1");
    $pdo->exec("UPDATE eds_warranty_cards_issued SET issued_at='2026-09-30 12:00:00', public_content=JSON_SET(public_content, '$.customer.phone', 'REGSEARCH-0002') WHERE work_order_id=2");
    $registryWorkOrder = $pdo->prepare('INSERT INTO work_orders (id,work_order_number,customer_id,computer,description,created_by,created_at) VALUES (?,?,?,?,?,?,?)');
    $registryCard = $pdo->prepare('INSERT INTO eds_warranty_cards_issued (work_order_id,card_number,number_key,issued_at,issued_by,public_content,internal_content) VALUES (?,?,?,?,?,?,?)');
    $registryHugeValue = str_repeat('9', 1000);
    $registryHugeFormatted = '000' . $registryHugeValue;
    for ($workOrderId = 4; $workOrderId <= 52; $workOrderId++) {
        $number = match ($workOrderId) {
            4 => '0006',
            5 => $registryHugeFormatted,
            default => (string) (700000 + $workOrderId),
        };
        $customer = $workOrderId === 52
            ? ['phone' => 'REGSEARCH-0052 SECRET-PHONE-0052']
            : [
                'name' => sprintf('Registry Customer %02d', $workOrderId),
                'phone' => sprintf('REGSEARCH-%04d SECRET-PHONE-%04d', $workOrderId, $workOrderId),
                'company' => 'REGISTRY-HIDDEN-COMPANY-' . $workOrderId,
            ];
        $public = [
            'issuer' => [],
            'customer' => $customer,
            'parts' => [],
            'work_warranties' => [],
            'terms_html' => '',
        ];
        $issuedAt = date('Y-m-d H:i:s', strtotime('2020-01-02 00:00:00') - ($workOrderId * 60));
        $registryWorkOrder->execute([$workOrderId, 'REGISTRY-WO-' . $workOrderId, 1, 'REGISTRY DEVICE SECRET', 'REGISTRY PROBLEM SECRET', 1, $issuedAt]);
        $registryCard->execute([
            $workOrderId,
            $number,
            hash('sha256', ltrim($number, '0'), true),
            $issuedAt,
            1,
            json_encode($public, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            json_encode(['parts' => [['supplier' => 'REGISTRY SUPPLIER SECRET']]], JSON_THROW_ON_ERROR),
        ]);
        if ($workOrderId === 51) {
            $registryExactlyFifty = request($registryPath, 'admin');
            $registryExactlyFiftyDom = new DOMDocument(); @$registryExactlyFiftyDom->loadHTML('<?xml encoding="UTF-8">' . $registryExactlyFifty['body']);
            $registryExactlyFiftyXpath = new DOMXPath($registryExactlyFiftyDom);
            httpCheck($registryExactlyFiftyXpath->query('//table[contains(@class,"eds-warranty-registry__table")]/tbody/tr')->length === 50
                && $registryExactlyFiftyXpath->query('//nav[contains(@class,"eds-warranty-registry__pagination")]')->length === 0, 'exactly fifty issued cards render one page without pagination');
        }
    }
    $pdo->prepare('INSERT INTO eds_warranty_cards_drafts (work_order_id,revision,content,created_at,updated_at) VALUES (3,1,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE revision=VALUES(revision),content=VALUES(content),updated_at=NOW()')
        ->execute([json_encode(['manual_items'=>[['name'=>'DRAFT ONLY REGISTRY SECRET']]], JSON_THROW_ON_ERROR)]);

    $registryPageOne = request($registryPath, 'admin');
    $registryPageOneDom = new DOMDocument(); @$registryPageOneDom->loadHTML('<?xml encoding="UTF-8">' . $registryPageOne['body']);
    $registryPageOneXpath = new DOMXPath($registryPageOneDom);
    $registryHeaders = $registryPageOneXpath->query('//table[contains(@class,"eds-warranty-registry__table")]/thead/tr/th');
    $registryRows = $registryPageOneXpath->query('//table[contains(@class,"eds-warranty-registry__table")]/tbody/tr');
    $headerTexts = [];
    foreach ($registryHeaders as $header) { $headerTexts[] = trim($header->textContent); }
    httpCheck($registryPageOne['status'] === 200 && $headerTexts === ['№', 'Клиент', 'Дата', 'Действия'], 'registry table has only the four agreed columns');
    httpCheck($registryRows->length === 50 && $registryPageOneXpath->query('//nav[contains(@class,"eds-warranty-registry__pagination")]')->length === 1, 'fifty-one issued cards render fifty rows and pagination on page one');
    httpCheck($registryPageOneXpath->query('//td[contains(concat(" ",normalize-space(@class)," ")," eds-warranty-registry__actions ")]/span[contains(concat(" ",normalize-space(@class)," ")," eds-warranty-registry__action-buttons ")]')->length === 50
        && $registryPageOneXpath->query('//span[contains(concat(" ",normalize-space(@class)," ")," eds-warranty-registry__action-buttons ")]/a')->length === 100, 'each rendered row groups exactly its two existing action links');
    httpCheck(trim($registryRows->item(0)->getElementsByTagName('td')->item(0)->textContent) === '00004', 'newest issued card is first with its exact padded number');
    httpCheck(!str_contains($registryPageOne['body'], 'DRAFT ONLY REGISTRY SECRET')
        && !str_contains($registryPageOne['body'], 'REGISTRY DEVICE SECRET')
        && !str_contains($registryPageOne['body'], 'REGISTRY PROBLEM SECRET')
        && !str_contains($registryPageOne['body'], 'REGISTRY SUPPLIER SECRET')
        && !str_contains($registryPageOne['body'], 'REGISTRY-HIDDEN-COMPANY')
        && !str_contains($registryPageOne['body'], 'SECRET-PHONE-'), 'registry excludes drafts, task, device, supplier, company and phone content');
    $registryPrintLink = $registryPageOneXpath->query('//a[contains(@href,"/work-orders/view/1/eds-warranty-card/print")]')->item(0);
    httpCheck($registryPageOneXpath->query('//a[@href="' . $base . '/work-orders/view/1/eds-warranty-card"]')->length === 1
        && $registryPrintLink instanceof DOMElement && $registryPrintLink->getAttribute('target') === '_blank'
        && str_contains($registryPrintLink->getAttribute('rel'), 'noopener'), 'registry actions reuse the existing issued view and safe print route');

    $registryPageTwo = request($registryPath . '?page=2', 'admin');
    $registryPageTwoDom = new DOMDocument(); @$registryPageTwoDom->loadHTML('<?xml encoding="UTF-8">' . $registryPageTwo['body']);
    $registryPageTwoRows = (new DOMXPath($registryPageTwoDom))->query('//table[contains(@class,"eds-warranty-registry__table")]/tbody/tr');
    httpCheck($registryPageTwo['status'] === 200 && $registryPageTwoRows->length === 1
        && str_contains($registryPageTwo['body'], '/work-orders/view/52/eds-warranty-card'), 'second registry page contains the correct one remaining issued card');

    $registryNameSearch = request($registryPath . '?q=' . rawurlencode('registry customer 10'), 'admin');
    $registryNameSearchDom = new DOMDocument(); @$registryNameSearchDom->loadHTML('<?xml encoding="UTF-8">' . $registryNameSearch['body']);
    $registryNameSearchXpath = new DOMXPath($registryNameSearchDom);
    httpCheck(str_contains($registryNameSearch['body'], 'Registry Customer 10') && !str_contains($registryNameSearch['body'], 'Registry Customer 11')
        && $registryNameSearchXpath->query('//form[@role="search"]//*[@name="page"]')->length === 0
        && $registryNameSearchXpath->query('//form[@role="search"]/a[@href="' . $base . '/eds-warranty-cards"]')->length === 1, 'name search starts from page one and exposes a compact clear action');
    $registryPhoneSearch = request($registryPath . '?q=' . rawurlencode('PHONE-0010'), 'admin');
    httpCheck(str_contains($registryPhoneSearch['body'], 'Registry Customer 10')
        && !str_contains($registryPhoneSearch['body'], 'SECRET-PHONE-0010'), 'registry searches partial frozen phone without exposing the stored phone');
    $registryNumberSearch = request($registryPath . '?q=6', 'admin');
    $registryNumberDom = new DOMDocument(); @$registryNumberDom->loadHTML('<?xml encoding="UTF-8">' . $registryNumberSearch['body']);
    $registryNumberRows = (new DOMXPath($registryNumberDom))->query('//table[contains(@class,"eds-warranty-registry__table")]/tbody/tr');
    httpCheck($registryNumberRows->length >= 1
        && trim($registryNumberRows->item(0)->getElementsByTagName('td')->item(0)->textContent) === '0006', 'numeric query 6 finds and prioritizes exact formatted number 0006');
    $registryHugeSearch = request($registryPath . '?q=' . rawurlencode($registryHugeValue), 'admin');
    httpCheck(str_contains($registryHugeSearch['body'], $registryHugeFormatted), 'arbitrarily large numeric query finds the exact padded number without integer conversion');

    $registrySearchPagination = request($registryPath . '?q=REGSEARCH', 'admin');
    httpCheck(substr_count($registrySearchPagination['body'], '<tr>') >= 51
        && str_contains($registrySearchPagination['body'], '?q=REGSEARCH&amp;page=2'), 'active search remains in registry pagination links');
    $registryMissingName = request($registryPath . '?q=' . rawurlencode('REGSEARCH-0052'), 'admin');
    httpCheck($registryMissingName['status'] === 200 && str_contains($registryMissingName['body'], '—'), 'older snapshot with missing customer name renders safely');
    $registryNoResults = request($registryPath . '?q=' . rawurlencode('NO SUCH WARRANTY CARD'), 'admin');
    httpCheck(str_contains($registryNoResults['body'], 'Няма гаранционни карти, отговарящи на търсенето.')
        && !str_contains($registryNoResults['body'], 'Няма издадени гаранционни карти.'), 'empty registry and no-search-results use different messages');
    $registryAttack = "%_' OR 1=1 -- <script>alert(1)</script>";
    $registryAttackResponse = request($registryPath . '?q=' . rawurlencode($registryAttack), 'admin');
    $registryAttackDom = new DOMDocument(); @$registryAttackDom->loadHTML('<?xml encoding="UTF-8">' . $registryAttackResponse['body']);
    $registryAttackXpath = new DOMXPath($registryAttackDom);
    $registryAttackInput = $registryAttackXpath->query('//input[@name="q"]')->item(0);
    httpCheck($registryAttackResponse['status'] === 200 && $registryAttackXpath->query('//script[contains(.,"alert(1)")]')->length === 0
        && $registryAttackInput instanceof DOMElement && $registryAttackInput->getAttribute('value') === $registryAttack, 'HTML and SQL special characters remain escaped literal search text');
    foreach (['?page=abc', '?page=0', '?page=' . str_repeat('9', 1000)] as $invalidPageQuery) {
        httpCheck(request($registryPath . $invalidPageQuery, 'admin')['status'] === 200, 'invalid or excessive registry page is handled safely');
    }
    $technicianRegistry = request($registryPath, 'technician');
    httpCheck($technicianRegistry['status'] === 200 && str_contains($technicianRegistry['body'], 'Registry Customer'), 'Technician can open populated registry');
    $limitedPopulatedRegistry = request($registryPath, 'limited');
    httpCheck($limitedPopulatedRegistry['status'] === 302 && str_ends_with($limitedPopulatedRegistry['headers']['location'], '/403'), 'Limited remains denied after registry is populated');

    $lockedDraft = request($path, 'admin');
    httpCheck($lockedDraft['status'] === 302 && str_ends_with($lockedDraft['headers']['location'], '/eds-warranty-card'), 'issued draft redirects to immutable card');
    $task = request('/work-orders/view/1', 'admin');
    httpCheck(str_contains($task['body'], 'Издадена') && str_contains($task['body'], '00004') && substr_count($task['body'], '>Гаранционна карта</h2>') === 1, 'task section shows issued status number and date once');
    $deleteFields = hiddenFields($task['body']);
    $delete = request('/work-orders/delete/1', 'admin', ['csrf_token'=>$deleteFields['csrf_token']]);
    httpCheck($delete['status'] === 302 && str_ends_with($delete['headers']['location'], '/work-orders/view/1'), 'issued card hook redirects blocked deletion');
    httpCheck((int) $pdo->query('SELECT COUNT(*) FROM work_orders WHERE id=1')->fetchColumn() === 1, 'blocked deletion preserves work order');
    $deleteMessage = request('/work-orders/view/1', 'admin');
    httpCheck(str_contains($deleteMessage['body'], 'не може да бъде изтрита') && str_contains($deleteMessage['body'], 'издадена гаранционна карта'), 'blocked deletion shows clear message');

    $settings->setSetting('enabled_modules', '[]');
    httpCheck(request($path, 'admin')['status'] === 404, 'disabled module has no draft route');
    $disabledNavigation = request('/work-orders', 'admin');
    httpCheck(request($registryPath, 'admin')['status'] === 404
        && !str_contains($disabledNavigation['body'], 'href="' . $base . '/eds-warranty-cards"')
        && !str_contains($disabledNavigation['body'], '/eds-warranty-cards/assets/warranty-card-nav.js'), 'disabled module removes registry route, navigation link and JavaScript request');
    echo "PASS: $checks HTTP checks with real Motherboard routing, auth, CSRF and layout.\n";
} finally {
    proc_terminate($process);
    proc_close($process);
    // Only fixture files/symlinks; never recursively traverse linked app directories.
    foreach (glob($fixture . '/sessions/*') ?: [] as $file) { unlink($file); }
    rmdir($fixture . '/sessions');
    foreach (glob($fixture . '/*') ?: [] as $file) { unlink($file); }
    rmdir($fixture);
}
