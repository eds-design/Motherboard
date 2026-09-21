<?php
// Browser-only navigation enhancement check. It does not load the application or a database.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$moduleRoot = dirname(__DIR__);
$javascript = file_get_contents($moduleRoot . '/assets/warranty-card-nav.js');
$chromium = trim((string) shell_exec('command -v chromium 2>/dev/null || command -v chromium-browser 2>/dev/null || command -v google-chrome 2>/dev/null'));
if ($chromium === '') {
    fwrite(STDERR, "SKIP: Chromium is not available for navigation layout checks.\n");
    exit(0);
}

function navigationFixture(string $links, string $javascript): string {
    return '<!doctype html><html><head><meta charset="utf-8"></head><body><nav id="navigation">'
        . $links . '</nav><script>' . $javascript . '</script></body></html>';
}

function dumpNavigationDom(string $chromium, string $directory, string $name, string $html): DOMDocument {
    $fixture = $directory . '/' . $name . '.html';
    $profile = $directory . '/' . $name . '-profile';
    file_put_contents($fixture, $html);
    $command = escapeshellarg($chromium)
        . ' --headless --no-sandbox --disable-gpu --disable-dev-shm-usage --disable-background-networking'
        . ' --user-data-dir=' . escapeshellarg($profile)
        . ' --virtual-time-budget=1000 --dump-dom ' . escapeshellarg('file://' . $fixture) . ' 2>/dev/null';
    $output = (string) shell_exec($command);
    if ($output === '') {
        throw new RuntimeException('Chromium did not return navigation DOM.');
    }
    $document = new DOMDocument();
    @$document->loadHTML($output);
    return $document;
}

function navigationIds(DOMDocument $document): array {
    $ids = [];
    foreach ((new DOMXPath($document))->query('//*[@id="navigation"]/a') as $link) {
        $ids[] = $link->getAttribute('id');
    }
    return $ids;
}

$directory = __DIR__ . '/.navigation-' . bin2hex(random_bytes(6));
if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
    throw new RuntimeException('Cannot create navigation test directory.');
}

try {
    $registry = '<a id="eds-warranty-cards-nav-link" href="https://example.test/app/eds-warranty-cards" data-work-orders-url="https://example.test/app/work-orders">Warranty cards</a>';
    $main = dumpNavigationDom($chromium, $directory, 'main', navigationFixture(
        '<a id="home" href="https://example.test/app/">Home</a>'
        . '<a id="work-orders" href="https://example.test/app/work-orders">Work orders</a>'
        . '<a id="customers" href="https://example.test/app/customers">Customers</a>'
        . $registry
        . '<a id="logout" href="https://example.test/app/logout">Logout</a>',
        $javascript
    ));
    $mainXpath = new DOMXPath($main);
    $mainIds = navigationIds($main);
    if ($mainIds !== ['home', 'work-orders', 'eds-warranty-cards-nav-link', 'customers', 'logout']
        || $mainXpath->query('//*[@id="eds-warranty-cards-nav-link"]')->length !== 1) {
        throw new RuntimeException('Navigation link was not moved exactly once after Work orders: ' . json_encode($mainIds));
    }

    $fallback = dumpNavigationDom($chromium, $directory, 'fallback', navigationFixture(
        '<a id="home" href="https://example.test/app/">Home</a>'
        . '<a id="customers" href="https://example.test/app/customers">Customers</a>'
        . $registry
        . '<a id="logout" href="https://example.test/app/logout">Logout</a>',
        $javascript
    ));
    $fallbackXpath = new DOMXPath($fallback);
    $fallbackIds = navigationIds($fallback);
    if ($fallbackIds !== ['home', 'customers', 'eds-warranty-cards-nav-link', 'logout']
        || $fallbackXpath->query('//*[@id="eds-warranty-cards-nav-link"]')->length !== 1) {
        throw new RuntimeException('Missing Work orders target changed or duplicated the fallback link: ' . json_encode($fallbackIds));
    }
} finally {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($directory);
}

echo "PASS: navigation link moves after Work orders and keeps its safe fallback.\n";
