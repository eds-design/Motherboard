<?php
// Browser-only responsive registry check. It does not load the application or a database.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$css = file_get_contents(dirname(__DIR__) . '/assets/warranty-card-registry.css');
$chromium = trim((string) shell_exec('command -v chromium 2>/dev/null || command -v chromium-browser 2>/dev/null || command -v google-chrome 2>/dev/null'));
if ($chromium === '') {
    fwrite(STDERR, "SKIP: Chromium is not available for registry layout checks.\n");
    exit(0);
}

$fixture = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>'
    . $css . '</style></head><body><div id="test-viewport"><div class="eds-warranty-registry">'
    . '<div class="eds-warranty-registry__heading"><h1>Warranty cards</h1><form class="eds-warranty-registry__search">'
    . '<input id="search-input" type="search" value="active query"><button id="search-button" type="submit">Търси</button><a id="search-clear" href="#clear">Изчисти</a>'
    . '</form></div><div class="eds-warranty-registry__table-wrap">'
    . '<table class="eds-warranty-registry__table"><thead><tr><th>No.</th><th>Customer</th><th>Date</th><th id="actions-heading">Actions</th></tr></thead><tbody><tr>'
    . '<td data-label="No."><strong>000000000000000000000000000000000006</strong></td>'
    . '<td data-label="Customer">Very long customer name that must stay inside the responsive registry card without horizontal overflow</td>'
    . '<td data-label="Date">21.09.2026</td>'
    . '<td data-label="Actions" id="actions-cell" class="eds-warranty-registry__actions"><span id="action-buttons" class="eds-warranty-registry__action-buttons"><a id="view-action" href="#view">Преглед</a><a id="print-action" href="#print">Печат</a></span></td>'
    . '</tr></tbody></table></div></div></div><script>'
    . <<<'JS'
var requestedWidth = Number(new URLSearchParams(window.location.search).get('width'));
var viewport = document.getElementById('test-viewport');
if (requestedWidth > 0) {
    viewport.style.width = Math.min(requestedWidth, document.documentElement.clientWidth - 16) + 'px';
}
window.requestAnimationFrame(function () {
    var heading = document.getElementById('actions-heading');
    var actions = document.getElementById('actions-cell');
    var buttons = document.getElementById('action-buttons');
    var view = document.getElementById('view-action').getBoundingClientRect();
    var print = document.getElementById('print-action').getBoundingClientRect();
    var search = document.querySelector('.eds-warranty-registry__search').getBoundingClientRect();
    var input = document.getElementById('search-input').getBoundingClientRect();
    var searchButton = document.getElementById('search-button').getBoundingClientRect();
    var clear = document.getElementById('search-clear').getBoundingClientRect();
    var root = document.documentElement;
    root.setAttribute('data-mobile', String(window.matchMedia('(max-width: 640px)').matches));
    root.setAttribute('data-heading-align', getComputedStyle(heading).textAlign);
    root.setAttribute('data-actions-align', getComputedStyle(actions).justifyContent);
    root.setAttribute('data-actions-wrap', getComputedStyle(actions).flexWrap);
    root.setAttribute('data-buttons-wrap', getComputedStyle(buttons).flexWrap);
    root.setAttribute('data-actions-same-row', String(Math.abs(view.top - print.top) < 1));
    root.setAttribute('data-buttons-auto-width', String(view.width < buttons.getBoundingClientRect().width && print.width < buttons.getBoundingClientRect().width));
    root.setAttribute('data-search-height', String(Math.round(search.height)));
    root.setAttribute('data-input-height', String(Math.round(input.height)));
    root.setAttribute('data-search-full-width', String(Math.abs(input.width - search.width) < 2 && Math.abs(searchButton.width - search.width) < 2 && Math.abs(clear.width - search.width) < 2));
    root.setAttribute('data-search-stacked', String(searchButton.top >= input.bottom && clear.top >= searchButton.bottom));
    root.setAttribute('data-no-overflow', String(viewport.scrollWidth <= viewport.clientWidth + 1));
    root.setAttribute('data-scroll-width', String(viewport.scrollWidth));
    root.setAttribute('data-client-width', String(viewport.clientWidth));
});
JS
    . '</script></body></html>';

function registryLayoutDom(string $chromium, string $directory, string $fixturePath, string $profileName, string $size, int $contentWidth = 0): DOMElement {
    $profile = $directory . '/' . $profileName;
    $command = escapeshellarg($chromium)
        . ' --headless --no-sandbox --disable-gpu --disable-dev-shm-usage --disable-background-networking'
        . ' --force-device-scale-factor=1 --window-size=' . escapeshellarg($size)
        . ' --user-data-dir=' . escapeshellarg($profile)
        . ' --virtual-time-budget=1000 --dump-dom ' . escapeshellarg('file://' . $fixturePath . ($contentWidth > 0 ? '?width=' . $contentWidth : '')) . ' 2>/dev/null';
    $output = (string) shell_exec($command);
    $document = new DOMDocument();
    @$document->loadHTML($output);
    $root = $document->documentElement;
    if (!$root instanceof DOMElement) {
        throw new RuntimeException('Chromium did not return registry layout DOM.');
    }
    return $root;
}

$directory = __DIR__ . '/.registry-layout-' . bin2hex(random_bytes(6));
if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
    throw new RuntimeException('Cannot create registry layout test directory.');
}
$fixturePath = $directory . '/fixture.html';
file_put_contents($fixturePath, $fixture);

try {
    $desktop = registryLayoutDom($chromium, $directory, $fixturePath, 'desktop-profile', '1200,800');
    if ($desktop->getAttribute('data-mobile') !== 'false'
        || $desktop->getAttribute('data-heading-align') !== 'right'
        || $desktop->getAttribute('data-actions-align') !== 'flex-end'
        || $desktop->getAttribute('data-actions-wrap') !== 'nowrap'
        || $desktop->getAttribute('data-actions-same-row') !== 'true'
        || $desktop->getAttribute('data-no-overflow') !== 'true') {
        throw new RuntimeException('Desktop Actions alignment is not compact and right-aligned.');
    }

    foreach ([640, 390, 320] as $width) {
        $mobile = registryLayoutDom($chromium, $directory, $fixturePath, 'mobile-' . $width . '-profile', $width . ',800', $width);
        if ($mobile->getAttribute('data-mobile') !== 'true'
            || $mobile->getAttribute('data-actions-wrap') !== 'wrap'
            || $mobile->getAttribute('data-buttons-wrap') !== 'wrap'
            || $mobile->getAttribute('data-actions-align') === 'flex-end'
            || $mobile->getAttribute('data-actions-same-row') !== 'true'
            || $mobile->getAttribute('data-buttons-auto-width') !== 'true'
            || $mobile->getAttribute('data-search-full-width') !== 'true'
            || $mobile->getAttribute('data-search-stacked') !== 'true'
            || (int) $mobile->getAttribute('data-search-height') >= 160
            || (int) $mobile->getAttribute('data-input-height') >= 60
            || $mobile->getAttribute('data-no-overflow') !== 'true') {
            throw new RuntimeException('Mobile registry layout failed at ' . $width . 'px: ' . json_encode([
                'mobile' => $mobile->getAttribute('data-mobile'),
                'align' => $mobile->getAttribute('data-actions-align'),
                'action_wrap' => $mobile->getAttribute('data-actions-wrap'),
                'button_wrap' => $mobile->getAttribute('data-buttons-wrap'),
                'same_row' => $mobile->getAttribute('data-actions-same-row'),
                'button_width' => $mobile->getAttribute('data-buttons-auto-width'),
                'search_width' => $mobile->getAttribute('data-search-full-width'),
                'search_stacked' => $mobile->getAttribute('data-search-stacked'),
                'search_height' => $mobile->getAttribute('data-search-height'),
                'input_height' => $mobile->getAttribute('data-input-height'),
                'overflow' => $mobile->getAttribute('data-no-overflow'),
                'scroll' => $mobile->getAttribute('data-scroll-width'),
                'client' => $mobile->getAttribute('data-client-width'),
            ]));
        }
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

echo "PASS: registry search and Actions are compact at 640, 390 and 320px and remain right-aligned on desktop.\n";
