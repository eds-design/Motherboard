<?php
if (PHP_SAPI !== 'cli' || !isset($issuedModel, $checks)) { exit; }

function renderWarrantyPrint(array $card): string {
    $clientCard = $card;
    ob_start();
    include dirname(__DIR__) . '/views/print.php';
    return ob_get_clean();
}

function renderWarrantyDocument(array $content, string $number = '', string $issuedAt = '', string $mode = 'screen'): string {
    $documentContent = $content;
    $documentCardNumber = $number;
    $documentIssuedAt = $issuedAt;
    $documentMode = $mode;
    ob_start();
    include dirname(__DIR__) . '/views/client-document.php';
    return ob_get_clean();
}

function warrantyDocumentXpath(string $html): array {
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    return [$dom, new DOMXPath($dom)];
}

$clientCard = $issuedModel->clientContent(201);
check($clientCard !== null, 'issued customer projection available for print');
$first = renderWarrantyPrint($clientCard);
$second = renderWarrantyPrint($issuedModel->clientContent(201));
check(hash('sha256', $first) === hash('sha256', $second), 'repeat print renders the same frozen data');
[$printDom, $printXpath] = warrantyDocumentXpath($first);
$document = $printXpath->query('//*[@id="eds-warranty-client-document"]')->item(0);
check($document instanceof DOMElement, 'print contains the shared customer document');
check($printXpath->query('//link[contains(@href, "/eds-warranty-cards/assets/warranty-card-document.css")]')->length === 1, 'print loads the module-owned shared document stylesheet');
check($printXpath->query('//script[contains(@src, "/eds-warranty-cards/assets/warranty-card-pagination.js?v=0.10.0")]')->length === 1
    && $printXpath->query('//*[@id="eds-warranty-client-document" and @data-eds-warranty-pagination]/*[contains(concat(" ", normalize-space(@class), " "), " eds-warranty-document-source ")]')->length === 1,
    'print loads the local paginator while retaining one visible no-JavaScript source');
check(str_contains($first, '<h1 id="eds-warranty-document-title">ГАРАНЦИОННА КАРТА</h1>'), 'centered Bulgarian document title rendered');
check(str_contains($document->textContent, '№ 00004') && str_contains($document->textContent, 'Дата на издаване:'), 'issued number and date render from immutable card columns');
check($printXpath->query('//*[@class="eds-warranty-header__brand"]//img[@src="https://service.example/logo.png"]')->length === 1
    && str_contains($printXpath->query('//*[@class="eds-warranty-header__brand"]')->item(0)->textContent, 'https://service.example'), 'logo and website render in the left header column');
check(str_contains($printXpath->query('//*[@class="eds-warranty-header__service"]')->item(0)->textContent, 'Original Service')
    && str_contains($printXpath->query('//*[@class="eds-warranty-header__service"]')->item(0)->textContent, '222'), 'service name and phone render in the right header column');
check($printXpath->query('//*[@class="eds-warranty-rule"]')->length === 1, 'full-width header rule is present');
check(str_contains($first, 'Original Customer') && str_contains($first, 'Original Company') && str_contains($first, 'original@example.test'), 'customer fields render on separate labeled lines');
check(str_contains($first, 'Original Service Legal Ltd.') && str_contains($first, 'REG-201') && str_contains($first, 'Original Representative') && str_contains($first, 'Original Address'), 'complete frozen issuer renders in its own column');
$issuerParty = $printXpath->query('//*[@class="eds-warranty-parties"]/*[@class="eds-warranty-party"][2]')->item(0);
check($issuerParty && str_contains($issuerParty->textContent, 'Издател')
    && $printXpath->query('//*[@class="eds-warranty-parties"]/*[@class="eds-warranty-party"][2]/*[@class="eds-warranty-issuer-name" and normalize-space(.)="Original Service Legal Ltd."]')->length === 1
    && !str_contains($issuerParty->textContent, 'Юридическо име'), 'issuer column shows its title and direct legal name without an extra label');
check($issuerParty && !str_contains($issuerParty->textContent, 'Уебсайт:')
    && str_contains($printXpath->query('//*[@class="eds-warranty-header__brand"]')->item(0)->textContent, 'https://service.example'), 'website is omitted from issuer column and remains below the logo');

$rows = $printXpath->query('//*[@class="eds-warranty-items"]//tbody/tr');
check($rows->length === 5, 'parts and work activities share one table');
$rowTexts = [];
foreach ($rows as $row) { $rowTexts[] = trim(preg_replace('/\s+/u', ' ', $row->textContent)); }
check(str_starts_with($rowTexts[0], '1 ') && str_contains($rowTexts[0], 'SSD 500 GB') && str_contains($rowTexts[0], 'PART-SN') && str_contains($rowTexts[0], '24 месеца'), 'first part keeps its name, serial and warranty period');
check(str_starts_with($rowTexts[1], '2 ') && str_contains($rowTexts[1], 'MANUAL-A')
    && str_starts_with($rowTexts[2], '3 ') && str_contains($rowTexts[2], 'MANUAL-B'), 'manual parts keep their saved order and numbering');
check(str_starts_with($rowTexts[3], '4 ') && str_contains($rowTexts[3], 'Performed work') && str_contains($rowTexts[3], '—') && str_contains($rowTexts[3], '6 месеца')
    && str_starts_with($rowTexts[4], '5 ') && str_contains($rowTexts[4], '12 месеца'), 'work activities follow parts with continuous numbering and no serial');
check(str_contains($first, 'Гаранционни условия') && str_contains($first, '<strong>warranty terms</strong>') && str_contains($first, 'Keep receipt'), 'frozen sanitized terms render after the table');
$termsPosition = strpos($first, 'id="warranty-terms"');
$signaturesPosition = strpos($first, 'class="eds-warranty-signatures"');
check($termsPosition !== false && $signaturesPosition > $termsPosition
    && str_contains($first, 'Подпис на получателя') && str_contains($first, 'Подпис на издателя'), 'indivisible signature block follows warranty terms');
check(!str_contains($first, 'SECRET SUPPLIER') && !str_contains($first, 'SECRET CARD') && !str_contains($first, 'MANUAL SECRET'), 'all supplier data remains absent from customer print HTML');
check(!preg_match('/<(button|form|nav)\b/i', $first) && str_contains($first, 'window.print()'), 'print has no administrative controls and still opens the browser print dialog');

$forbiddenLegacyText = [
    'WO-201', 'Original PC', 'Original Model', 'DEVICE-SN', 'IMEI-X',
    'Device remarks', 'Charger', 'Original problem', 'Original resolution',
];
foreach ($forbiddenLegacyText as $forbidden) {
    check(!str_contains($document->textContent, $forbidden), 'customer document excludes work-order and device value');
}

$previewHtml = renderWarrantyDocument($clientCard['content']);
check(str_contains($previewHtml, '№ —') && str_contains($previewHtml, 'Дата на издаване: —')
    && !str_contains($previewHtml, '00005'), 'pre-issuance shared document shows placeholders without exposing the counter');

$partsOnly = $clientCard['content'];
$partsOnly['work_warranties'] = [];
$partsOnlyHtml = renderWarrantyDocument($partsOnly);
[, $partsOnlyXpath] = warrantyDocumentXpath($partsOnlyHtml);
check($partsOnlyXpath->query('//*[@class="eds-warranty-items"]//tbody/tr')->length === 3, 'parts-only document renders only part rows');
$partWithoutSerial = $partsOnly;
$partWithoutSerial['parts'][0]['serial_number'] = '';
$partWithoutSerialHtml = renderWarrantyDocument($partWithoutSerial);
[, $partWithoutSerialXpath] = warrantyDocumentXpath($partWithoutSerialHtml);
$firstPartCells = $partWithoutSerialXpath->query('//*[@class="eds-warranty-items"]//tbody/tr[1]/td');
check($firstPartCells->length === 4 && trim($firstPartCells->item(2)->textContent) === '—', 'part without a serial number displays an em dash');
$servicesOnly = $clientCard['content'];
$servicesOnly['parts'] = [];
$servicesOnlyHtml = renderWarrantyDocument($servicesOnly);
[, $servicesOnlyXpath] = warrantyDocumentXpath($servicesOnlyHtml);
check($servicesOnlyXpath->query('//*[@class="eds-warranty-items"]//tbody/tr')->length === 2
    && substr_count($servicesOnlyHtml, '>—</td>') === 2, 'services-only document renders activities with em-dash serials');

$localizedPeriods = $clientCard['content'];
$localizedPeriods['parts'] = [['name'=>'One-month part','serial_number'=>'ONE','months'=>'1']];
$localizedPeriods['work_warranties'] = [['description'=>'Two-month service','months'=>'2'], ['description'=>'Padded one-month service','months'=>'0001']];
$localizedBg = renderWarrantyDocument($localizedPeriods);
check(str_contains($localizedBg, 'One-month part') && str_contains($localizedBg, '1 месец')
    && str_contains($localizedBg, 'Two-month service') && str_contains($localizedBg, '2 месеца')
    && str_contains($localizedBg, '0001 месец'), 'Bulgarian part and service periods use exact string values with numeric singular detection');

$partial = $clientCard['content'];
$partial['customer'] = ['name' => 'Only Customer', 'company' => '', 'phone' => '', 'email' => ''];
$partial['issuer'] = array_replace(EdsWarrantyCardIssuer::blank(), ['service_name' => 'Only Service']);
$partialHtml = renderWarrantyDocument($partial);
check(str_contains($partialHtml, 'Only Customer') && str_contains($partialHtml, 'Only Service')
    && str_contains($partialHtml, 'class="eds-warranty-issuer-name">Only Service</p>')
    && !str_contains($partialHtml, 'Юридическо име') && !str_contains($partialHtml, 'Телефон:</span> </p>'), 'partial customer and issuer omit empty rows and use the service name directly as issuer name');
$emptyEffective = EdsWarrantyCardIssuer::fromPublicContent(['issuer' => EdsWarrantyCardIssuer::blank()]);
check($emptyEffective['service_name'] === '', 'an empty frozen issuer does not gain an application-name fallback');

$long = str_repeat('МногоДългаСтойностБезИнтервали', 30);
$many = $clientCard;
$many['content']['issuer']['address'] = $long;
$many['content']['parts'] = [];
for ($i = 1; $i <= 80; $i++) {
    $many['content']['parts'][] = [
        'name' => 'Part ' . $i . ' ' . $long,
        'serial_number' => $i % 2 === 0 ? 'SERIAL-' . $i . '-' . $long : '',
        'months' => (string) (12 + $i),
    ];
}
$many['content']['work_warranties'] = [];
for ($i = 1; $i <= 40; $i++) {
    $many['content']['work_warranties'][] = ['description' => 'Work activity ' . $i . ' ' . $long, 'months' => (string) $i];
}
$many['content']['terms_html'] = str_repeat('<p>Дълго условие за гаранцията.</p><ul><li>Кратък елемент</li></ul>', 250);
$manyHtml = renderWarrantyPrint($many);
[, $manyXpath] = warrantyDocumentXpath($manyHtml);
check($manyXpath->query('//*[@class="eds-warranty-items"]//tbody/tr')->length === 120, 'many part and service rows render without truncation');
check(substr_count($manyHtml, 'Дълго условие за гаранцията.') === 250 && str_contains($manyHtml, $long), 'long values and multi-page terms remain complete');

$styles = file_get_contents(dirname(__DIR__) . '/assets/warranty-card-document.css');
check(str_contains($styles, '@page') && str_contains($styles, 'size: A4 portrait') && str_contains($styles, 'margin: 13mm'), 'shared CSS configures A4 portrait with print margins');
check(preg_match('/\.eds-warranty-title-block\s*\{[^}]*text-align:\s*center/s', $styles) === 1
    && preg_match('/\.eds-warranty-rule\s*\{[^}]*width:\s*100%/s', $styles) === 1, 'document title is centered below a full-width horizontal rule');
check(str_contains($styles, 'display: table-header-group') && preg_match('/\.eds-warranty-items tr\s*\{[^}]*break-inside:\s*avoid/s', $styles) === 1, 'table repeats its header and avoids splitting rows');
check(preg_match('/\.eds-warranty-terms\s*\{[^}]*break-inside:\s*auto/s', $styles) === 1
    && preg_match('/\.eds-warranty-terms__title\s*\{[^}]*break-after:\s*avoid/s', $styles) === 1
    && preg_match('/\.eds-warranty-terms__content li\s*\{[^}]*break-inside:\s*avoid/s', $styles) === 1, 'terms can span pages while title and short list items stay together where possible');
check(preg_match('/\.eds-warranty-signatures\s*\{[^}]*break-inside:\s*avoid/s', $styles) === 1
    && strpos($styles, '.eds-warranty-signatures') !== false, 'signature block is protected from page splitting');
check(str_contains($styles, 'table-layout: fixed') && str_contains($styles, 'overflow-wrap: anywhere')
    && str_contains($styles, '.eds-warranty-col-name { width: 50%; }'), 'table and long content remain inside the A4 width');
check(preg_match('/\.eds-warranty-page\s*\{[^}]*width:\s*210mm;[^}]*height:\s*297mm;[^}]*padding:\s*13mm/s', $styles) === 1
    && preg_match('/\.eds-warranty-page-shell\s*\{[^}]*width:\s*calc\(210mm \* var\(--eds-warranty-page-scale\)\);[^}]*height:\s*calc\(297mm \* var\(--eds-warranty-page-scale\)\)/s', $styles) === 1
    && preg_match('/\.eds-warranty-page-shell:last-child\s*\{[^}]*page-break-after:\s*auto/s', $styles) === 1,
    'preview and print use explicit scaled A4 sheets without a trailing blank-page break');
check(preg_match('/\.eds-warranty-terms__content p,\s*\.eds-warranty-terms__content ul,\s*\.eds-warranty-terms__content ol,\s*\.eds-warranty-terms__content li\s*\{[^}]*font-size:\s*12px;[^}]*line-height:\s*1\.45/s', $styles) === 1
    && preg_match('/\.eds-warranty-terms__content\s*\{[^}]*overflow-wrap:\s*break-word;[^}]*word-break:\s*normal;[^}]*hyphens:\s*none/s', $styles) === 1,
    'warranty terms alone use 12px text, 1.45 line height and natural word boundaries');

$legacy = $clientCard;
unset($legacy['content']['issuer']);
$legacy['content']['company'] = [
    'company_name' => 'Legacy Frozen Service',
    'company_address' => 'Legacy Frozen Address',
    'company_phone' => '555',
    'company_email' => 'legacy@example.test',
    'company_website' => 'https://legacy.example.test',
    'company_logo_url' => '',
];
$legacy['content']['device'] = ['computer'=>'LEGACY DEVICE SECRET','model'=>'LEGACY MODEL SECRET','serial_number'=>'LEGACY SERIAL SECRET','imei'=>'LEGACY IMEI SECRET','accessories'=>['LEGACY ACCESSORY SECRET']];
$legacy['content']['work_order'] = ['number'=>'LEGACY ORDER SECRET','description'=>'LEGACY PROBLEM SECRET','resolution'=>'LEGACY RESOLUTION SECRET','status'=>'LEGACY STATUS SECRET','priority'=>'LEGACY PRIORITY SECRET','created_at'=>'2020-01-01'];
unset($legacy['content']['terms_html']);
$legacy['content']['work_warranty'] = $legacy['content']['work_warranties'][0];
unset($legacy['content']['work_warranties']);
$legacyHtml = renderWarrantyPrint($legacy);
check(str_contains($legacyHtml, 'Legacy Frozen Service') && str_contains($legacyHtml, 'Legacy Frozen Address')
    && !preg_match('/LEGACY (?:DEVICE|MODEL|SERIAL|IMEI|ACCESSORY|ORDER|PROBLEM|RESOLUTION|STATUS|PRIORITY) SECRET/', $legacyHtml), 'legacy snapshot renders old customer fields but ignores all old task and device data');
check(substr_count($legacyHtml, 'Performed work') === 1 && !str_contains($legacyHtml, 'id="warranty-terms"'), 'legacy single work warranty remains visible without adding current terms');
[, $legacyXpath] = warrantyDocumentXpath($legacyHtml);
$legacyIssuerParty = $legacyXpath->query('//*[@class="eds-warranty-parties"]/*[@class="eds-warranty-party"][2]')->item(0);
check($legacyIssuerParty && $legacyXpath->query('//*[@class="eds-warranty-issuer-name" and normalize-space(.)="Legacy Frozen Service"]')->length === 1
    && !str_contains($legacyIssuerParty->textContent, 'Юридическо име') && !str_contains($legacyIssuerParty->textContent, 'Уебсайт:'), 'legacy card uses service name directly and omits the repeated website label');

$unsafeLogo = $clientCard;
$unsafeLogo['content']['issuer']['logo_url'] = 'javascript:alert(1)';
$unsafeLogoHtml = renderWarrantyPrint($unsafeLogo);
check(!str_contains($unsafeLogoHtml, 'javascript:') && !str_contains($unsafeLogoHtml, 'class="eds-warranty-logo"'), 'unsafe frozen logo is omitted');
$emptyTerms = $clientCard;
$emptyTerms['content']['terms_html'] = '<p></p><script>bad</script>';
check(!str_contains(renderWarrantyPrint($emptyTerms), 'id="warranty-terms"'), 'empty or unsafe terms create no section');

I18n::getInstance()->merge(require dirname(__DIR__) . '/lang/en-us.php');
$english = renderWarrantyPrint($clientCard);
check(str_contains($english, '>WARRANTY CARD</h1>') && str_contains($english, 'Issue date:')
    && str_contains($english, 'Name / service') && str_contains($english, '24 months'), 'English document labels and periods render');
check(str_contains($english, 'Original <strong>warranty terms</strong>'), 'print language does not translate frozen terms content');
$localizedEn = renderWarrantyDocument($localizedPeriods);
check(str_contains($localizedEn, '1 month') && str_contains($localizedEn, '2 months') && str_contains($localizedEn, '0001 month'), 'English part and service periods use singular and plural keys without integer conversion');
I18n::getInstance()->merge(require dirname(__DIR__) . '/lang/bg-bg.php');
