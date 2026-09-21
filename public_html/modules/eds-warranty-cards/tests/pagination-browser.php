<?php
// Optional layout check using a locally installed Chromium. It does not load the application or a database.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$moduleRoot = dirname(__DIR__);
$css = file_get_contents($moduleRoot . '/assets/warranty-card-document.css');
$javascript = file_get_contents($moduleRoot . '/assets/warranty-card-pagination.js');
$chromium = trim((string) shell_exec('command -v chromium 2>/dev/null || command -v chromium-browser 2>/dev/null || command -v google-chrome 2>/dev/null'));
if ($chromium === '') {
    fwrite(STDERR, "SKIP: Chromium is not available for pagination layout checks.\n");
    exit(0);
}

function paginationDocument(string $id, string $content, string $mode = 'screen'): string {
    return '<div class="eds-warranty-document-stage"><div id="' . $id . '" class="eds-warranty-document-set" data-eds-warranty-pagination>'
        . '<article class="eds-warranty-document eds-warranty-document-source eds-warranty-document--' . $mode . '">'
        . $content . '</article></div></div>';
}

function paginationTable(int $rows): string {
    $body = '';
    for ($index = 1; $index <= $rows; $index++) {
        $body .= '<tr data-row="' . $index . '"><td>' . $index . '</td><td>Part ' . $index . '</td><td>SERIAL-' . $index . '</td><td>24 months</td></tr>';
    }
    return '<section class="eds-warranty-items"><table><colgroup><col class="eds-warranty-col-number"><col class="eds-warranty-col-name"><col class="eds-warranty-col-serial"><col class="eds-warranty-col-period"></colgroup>'
        . '<thead><tr><th>No.</th><th>Name / service</th><th>Serial</th><th>Warranty</th></tr></thead><tbody>' . $body . '</tbody></table></section>';
}

$short = '<header class="eds-warranty-header"><div>Logo</div><div class="eds-warranty-header__service">Service</div></header>'
    . '<div class="eds-warranty-rule"></div><section class="eds-warranty-title-block"><h1>Warranty card</h1></section>'
    . '<section class="eds-warranty-parties"><div class="eds-warranty-party">Customer</div><div class="eds-warranty-party">Issuer</div></section>'
    . paginationTable(2)
    . '<section class="eds-warranty-signatures"><div class="eds-warranty-signature"><p>Recipient</p><p>Signature</p></div><div class="eds-warranty-signature"><p>Issuer</p><p>Signature</p></div></section>';

$manyRows = paginationTable(95) . '<section class="eds-warranty-signatures"><div>Recipient signature</div><div>Issuer signature</div></section>';
$oversizedRowText = 'OVERSIZED-ROW-BEGIN ' . str_repeat('very tall table row wording ', 1800) . ' OVERSIZED-ROW-END';
$oversizedRow = '<section class="eds-warranty-items"><table><colgroup><col class="eds-warranty-col-number"><col class="eds-warranty-col-name"><col class="eds-warranty-col-serial"><col class="eds-warranty-col-period"></colgroup>'
    . '<thead><tr><th>No.</th><th>Name / service</th><th>Serial</th><th>Warranty</th></tr></thead><tbody><tr data-oversized-row><td>1</td><td>' . $oversizedRowText . '</td><td>SERIAL-LONG</td><td>24 months</td></tr></tbody></table></section>';
$paragraphs = '<div class="test-fill test-fill--paragraphs">FILL-PARAGRAPHS</div><section id="warranty-terms" class="eds-warranty-terms">'
    . '<h2 class="eds-warranty-terms__title">Warranty terms</h2><div class="eds-warranty-terms__content">'
    . 'DIRECT SAFE TEXT <strong>DIRECT BOLD TEXT</strong><br>DIRECT NEXT LINE'
    . '<p data-paragraph="one">FIRST PARAGRAPH ' . str_repeat('ordinary words ', 50) . '</p>'
    . '<p data-paragraph="two">SECOND PARAGRAPH ' . str_repeat('more ordinary words ', 160) . '</p>'
    . '<p data-paragraph="three">LAST PARAGRAPH ' . str_repeat('final ordinary words ', 180) . '</p></div></section>';

$hugeText = 'HUGE-BEGIN ' . str_repeat('naturally separated warranty wording ', 1600) . ' HUGE-END';
$hugeParagraph = '<section id="warranty-terms" class="eds-warranty-terms"><h2 class="eds-warranty-terms__title">Warranty terms</h2>'
    . '<div class="eds-warranty-terms__content"><p data-huge-paragraph>' . $hugeText . '</p></div></section>';

$listItems = '';
for ($index = 1; $index <= 70; $index++) {
    $listItems .= '<li data-list-item="u' . $index . '">UL-' . $index . ' ' . str_repeat('list wording ', 8) . '</li>';
}
$orderedItems = '';
for ($index = 1; $index <= 70; $index++) {
    $orderedItems .= '<li data-list-item="o' . $index . '">OL-' . $index . ' ' . str_repeat('ordered wording ', 8) . '</li>';
}
$lists = '<section id="warranty-terms" class="eds-warranty-terms"><h2 class="eds-warranty-terms__title">Warranty terms</h2>'
    . '<div class="eds-warranty-terms__content"><ul>' . $listItems . '</ul><ol>' . $orderedItems . '</ol></div></section>';

$longValue = str_repeat('SERIALVALUE', 1800);
$unbroken = '<section id="warranty-terms" class="eds-warranty-terms"><h2 class="eds-warranty-terms__title">Warranty terms</h2>'
    . '<div class="eds-warranty-terms__content"><p data-unbroken>' . $longValue . '</p></div></section>';

$signatures = '<div class="test-fill test-fill--signatures">FILL-SIGNATURES</div>'
    . '<section class="eds-warranty-signatures" data-signatures><div><p>Recipient</p><p>Signature line</p></div><div><p>Issuer</p><p>Signature line</p></div></section>';

$fixture = '<!doctype html><html><head><meta charset="utf-8"><style>' . $css . "\n"
    . '.test-fill--paragraphs{height:205mm}.test-fill--signatures{height:245mm}'
    . '@media print{.eds-warranty-document-stage:not(:last-of-type) .eds-warranty-page-shell:last-child{break-after:page;page-break-after:always}}'
    . '</style></head><body>'
    . paginationDocument('short', $short)
    . paginationDocument('many-rows', $manyRows)
    . paginationDocument('oversized-row', $oversizedRow)
    . paginationDocument('paragraphs', $paragraphs)
    . paginationDocument('huge-paragraph', $hugeParagraph)
    . paginationDocument('lists', $lists)
    . paginationDocument('unbroken', $unbroken)
    . paginationDocument('signatures', $signatures)
    . paginationDocument('same-screen', $short, 'screen')
    . paginationDocument('same-print', $short, 'print')
    . '<script>' . $javascript . '</script><script>'
    . <<<'JS'
var paginationTestKeepAlive = window.setInterval(function () {
    document.documentElement.toggleAttribute('data-pagination-test-alive');
}, 50);

window.edsWarrantyPaginationReady.then(function () {
    var failures = [];
    function assert(condition, label) {
        if (!condition) { failures.push(label); }
    }
    function root(id) { return document.getElementById(id); }
    function pages(id) { return root(id).querySelectorAll('.eds-warranty-page'); }
    function normalized(value) { return value.replace(/\s+/g, ' ').trim(); }

    assert(pages('short').length === 1, 'short card is not one page');

    var rowPages = pages('many-rows');
    var renderedRows = root('many-rows').querySelectorAll('tbody > tr');
    assert(rowPages.length > 1, 'many table rows do not span pages');
    assert(renderedRows.length === 95, 'table rows were lost or duplicated');
    Array.from(root('many-rows').querySelectorAll('.eds-warranty-items')).forEach(function (section) {
        assert(section.querySelectorAll('thead').length === 1, 'a continued table has no single repeated header');
    });
    assert(Array.from(renderedRows).every(function (row, index) { return row.dataset.row === String(index + 1); }), 'table row order changed');

    var oversizedRowFragments = root('oversized-row').querySelectorAll('[data-oversized-row]');
    assert(pages('oversized-row').length > 1 && oversizedRowFragments.length > 1, 'a row taller than A4 was not split');
    assert(normalized(Array.from(oversizedRowFragments).map(function (row) { return row.children[1].textContent; }).join(' '))
        === normalized('OVERSIZED-ROW-BEGIN ' + 'very tall table row wording '.repeat(1800) + ' OVERSIZED-ROW-END'), 'oversized table row text changed');
    assert(root('oversized-row').querySelectorAll('thead').length === pages('oversized-row').length, 'oversized row pages do not repeat the table header');

    ['one', 'two', 'three'].forEach(function (key) {
        assert(root('paragraphs').querySelectorAll('[data-paragraph="' + key + '"]').length === 1, 'normal paragraph was split or duplicated: ' + key);
    });
    assert((root('paragraphs').textContent.match(/DIRECT SAFE TEXT/g) || []).length === 1
        && (root('paragraphs').textContent.match(/DIRECT BOLD TEXT/g) || []).length === 1
        && root('paragraphs').querySelectorAll('strong').length === 1, 'safe inline terms content was lost or duplicated');
    var lastParagraph = root('paragraphs').querySelector('[data-paragraph="three"]');
    assert(lastParagraph && lastParagraph.closest('.eds-warranty-page') !== pages('paragraphs')[0], 'last fitting paragraph was not moved whole');

    var hugePieces = root('huge-paragraph').querySelectorAll('[data-huge-paragraph]');
    assert(pages('huge-paragraph').length > 1 && hugePieces.length > 1, 'oversized paragraph was not split across pages');
    assert(normalized(Array.from(hugePieces).map(function (node) { return node.textContent; }).join(' ')) === normalized('HUGE-BEGIN ' + 'naturally separated warranty wording '.repeat(1600) + ' HUGE-END'), 'oversized paragraph text changed');
    assert(Array.from(hugePieces).slice(0, -1).every(function (node) { return /\s$/.test(node.textContent); }), 'ordinary words were split in the middle');
    var termsTitle = root('huge-paragraph').querySelector('.eds-warranty-terms__title');
    assert(termsTitle && termsTitle.closest('.eds-warranty-page') === hugePieces[0].closest('.eds-warranty-page'), 'terms title was orphaned from its first content');

    assert(root('lists').querySelectorAll('ul').length > 1, 'long unordered list was not split');
    assert(root('lists').querySelectorAll('ol').length > 1, 'long ordered list was not split');
    assert(root('lists').querySelectorAll('[data-list-item]').length === 140, 'list items were lost or duplicated');

    var unbrokenPieces = root('unbroken').querySelectorAll('[data-unbroken]');
    assert(unbrokenPieces.length > 1, 'very long unbroken value was not split');
    assert(Array.from(unbrokenPieces).map(function (node) { return node.textContent; }).join('') === 'SERIALVALUE'.repeat(1800), 'unbroken value changed');
    assert(Array.from(pages('unbroken')).every(function (page) { return page.scrollWidth <= page.clientWidth + 1; }), 'unbroken value overflows page width');

    var signatureBlock = root('signatures').querySelectorAll('[data-signatures]');
    assert(signatureBlock.length === 1 && signatureBlock[0].closest('.eds-warranty-page') === pages('signatures')[1], 'signatures did not move together to the next page');

    document.querySelectorAll('.eds-warranty-document-set').forEach(function (set) {
        var setPages = set.querySelectorAll('.eds-warranty-page');
        assert(setPages.length > 0, 'pagination produced no pages');
        assert(Array.from(setPages).every(function (page) {
            var content = page.querySelector('.eds-warranty-page__content');
            return content && content.childElementCount > 0;
        }), 'pagination produced an empty page');
    });

    assert(normalized(root('same-screen').textContent) === normalized(root('same-print').textContent), 'preview and print content order differs');

    var pageCountBeforeResize = document.querySelectorAll('.eds-warranty-page').length;
    window.dispatchEvent(new Event('resize'));
    window.setTimeout(function () {
        window.clearInterval(paginationTestKeepAlive);
        document.documentElement.removeAttribute('data-pagination-test-alive');
        assert(document.querySelectorAll('.eds-warranty-page').length === pageCountBeforeResize, 'resize changed the page count unexpectedly');
        assert(root('many-rows').querySelectorAll('tbody > tr').length === 95, 'resize lost or duplicated table rows');
        var result = failures.length ? 'FAIL: ' + failures.join(' | ') : 'PASS: browser pagination layout; pages=' + pageCountBeforeResize;
        if (!new URLSearchParams(window.location.search).has('pdf')) {
            document.body.innerHTML = '<pre id="pagination-result">' + result + '</pre>';
        } else {
            document.documentElement.setAttribute('data-pagination-result', result);
        }
    }, 300);
});
JS
    . '</script></body></html>';

$directory = __DIR__ . '/.pagination-' . bin2hex(random_bytes(6));
if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
    throw new RuntimeException('Cannot create pagination test directory.');
}
$fixturePath = $directory . '/fixture.html';
$profilePath = $directory . '/chromium-profile';
file_put_contents($fixturePath, $fixture);

$command = escapeshellarg($chromium)
    . ' --headless --no-sandbox --disable-gpu --disable-dev-shm-usage --disable-background-networking'
    . ' --user-data-dir=' . escapeshellarg($profilePath)
    . ' --virtual-time-budget=60000 --dump-dom ' . escapeshellarg('file://' . $fixturePath) . ' 2>/dev/null';
$output = (string) shell_exec($command);

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);
foreach ($iterator as $entry) {
    $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
}
rmdir($directory);

if (!preg_match('/<pre id="pagination-result">PASS: browser pagination layout; pages=(\d+)<\/pre>/', $output, $pageMatch)) {
    if (preg_match('/FAIL:[^<]*/', $output, $match)) {
        fwrite(STDERR, html_entity_decode($match[0], ENT_QUOTES | ENT_HTML5, 'UTF-8') . "\n");
    } else {
        fwrite(STDERR, "FAIL: Chromium did not finish the pagination layout check (output bytes: " . strlen($output) . ").\n");
    }
    exit(1);
}

$pdfInfo = trim((string) shell_exec('command -v pdfinfo 2>/dev/null'));
if ($pdfInfo !== '') {
    $pdfPath = $directory . '/pagination.pdf';
    // The directory was removed after the DOM pass; recreate only the fixture needed for PDF verification.
    mkdir($directory, 0700, true);
    file_put_contents($fixturePath, $fixture);
    $pdfCommand = escapeshellarg($chromium)
        . ' --headless --no-sandbox --disable-gpu --disable-dev-shm-usage --disable-background-networking'
        . ' --user-data-dir=' . escapeshellarg($profilePath)
        . ' --virtual-time-budget=60000 --no-pdf-header-footer --print-to-pdf=' . escapeshellarg($pdfPath)
        . ' ' . escapeshellarg('file://' . $fixturePath . '?pdf=1') . ' 2>/dev/null';
    shell_exec($pdfCommand);
    $pdfOutput = is_file($pdfPath) ? (string) shell_exec(escapeshellarg($pdfInfo) . ' ' . escapeshellarg($pdfPath) . ' 2>/dev/null') : '';
    preg_match('/^Pages:\s+(\d+)$/m', $pdfOutput, $pdfMatch);

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($directory);

    if (!isset($pdfMatch[1]) || (int) $pdfMatch[1] !== (int) $pageMatch[1]) {
        fwrite(STDERR, 'FAIL: PDF page count does not match preview (' . ($pdfMatch[1] ?? 'missing') . ' vs ' . $pageMatch[1] . ").\n");
        exit(1);
    }
}

echo "PASS: browser pagination layout and matching PDF page count.\n";
