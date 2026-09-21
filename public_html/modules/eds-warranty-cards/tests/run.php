<?php
// CLI only. Database tests use an explicitly supplied, isolated Unix socket.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__, 3) . '/core/I18n.php';
require_once dirname(__DIR__) . '/models/NumberCounter.php';
require_once dirname(__DIR__) . '/models/Terms.php';
require_once dirname(__DIR__) . '/models/Issuer.php';
require_once dirname(__DIR__) . '/models/DraftLimits.php';
require_once dirname(__DIR__) . '/schema.php';
require_once dirname(__DIR__) . '/models/Draft.php';
require_once dirname(__DIR__) . '/models/IssuedCard.php';
require_once dirname(__DIR__) . '/models/Registry.php';
I18n::getInstance()->merge(require dirname(__DIR__) . '/lang/bg-bg.php');
$checks = 0;
function check(bool $condition, string $label): void {
    global $checks;
    if (!$condition) {
        throw new RuntimeException('FAILED: ' . $label);
    }
    $checks++;
}
function rejects(callable $operation, string $message): void {
    try {
        $operation();
    } catch (InvalidArgumentException $error) {
        check($error->getMessage() === $message, 'validation message');
        return;
    }
    throw new RuntimeException('Expected validation failure.');
}

$definition = require dirname(__DIR__) . '/index.php';
check($definition['default_enabled'] === false, 'disabled by default, side-effect-free discovery');
check($definition['version'] === '0.12.1', 'Git-visible local Quill relocation uses a module patch version');
check(!isset($definition['dependencies']), 'no required Inventory or Warranty module dependency');
$bg = require dirname(__DIR__) . '/lang/bg-bg.php';
$en = require dirname(__DIR__) . '/lang/en-us.php';
check(array_keys($bg) === array_keys($en), 'translation coverage');
$moduleRoot = dirname(__DIR__);
$boot = file_get_contents($moduleRoot . '/boot.php');
$draftController = file_get_contents($moduleRoot . '/controllers/DraftController.php');
$settingsView = file_get_contents($moduleRoot . '/views/settings.php');
$draftView = file_get_contents($moduleRoot . '/views/draft.php');
$workOrderSection = file_get_contents($moduleRoot . '/views/work-order-section.php');
$issuerModel = file_get_contents($moduleRoot . '/models/Issuer.php');
$issuedModel = file_get_contents($moduleRoot . '/models/IssuedCard.php');
$cardView = file_get_contents($moduleRoot . '/views/card.php');
$printView = file_get_contents($moduleRoot . '/views/print.php');
$clientDocumentView = file_get_contents($moduleRoot . '/views/client-document.php');
$documentStyles = file_get_contents($moduleRoot . '/assets/warranty-card-document.css');
$paginationScript = file_get_contents($moduleRoot . '/assets/warranty-card-pagination.js');
$assetController = file_get_contents($moduleRoot . '/controllers/AssetController.php');
$editorScript = file_get_contents($moduleRoot . '/assets/warranty-terms-editor.js');
$editorStyles = file_get_contents($moduleRoot . '/assets/warranty-terms-editor.css');
$registryModel = file_get_contents($moduleRoot . '/models/Registry.php');
$registryController = file_get_contents($moduleRoot . '/controllers/RegistryController.php');
$registryView = file_get_contents($moduleRoot . '/views/registry.php');
$registryStyles = file_get_contents($moduleRoot . '/assets/warranty-card-registry.css');
$navigationScript = file_get_contents($moduleRoot . '/assets/warranty-card-nav.js');
$quillReadme = file_get_contents($moduleRoot . '/assets/quill/README.md');
$quillLicense = file_get_contents($moduleRoot . '/assets/quill/LICENSE');
$quillFiles = array_map('basename', glob($moduleRoot . '/assets/quill/*') ?: []);
sort($quillFiles);
$legacyQuillPath = $moduleRoot . '/assets/' . 'vendor' . '/quill';
check($quillFiles === ['LICENSE', 'README.md', 'quill.js', 'quill.js.LICENSE.txt', 'quill.js.map', 'quill.snow.css', 'quill.snow.css.map']
    && !file_exists($legacyQuillPath), 'all seven Quill files use the Git-visible module directory and the ignored legacy path is absent');
check(substr_count($settingsView, 'name="eds_warranty_cards_terms_html"') === 1 && preg_match('/<textarea[^>]+rows="(?:1[0-9]|[2-9][0-9])"/i', $settingsView) === 1, 'one normal textarea with at least ten rows');
$issuerFieldNames = [
    'eds_warranty_cards_issuer_service_name', 'eds_warranty_cards_issuer_legal_name',
    'eds_warranty_cards_issuer_registration_number', 'eds_warranty_cards_issuer_representative',
    'eds_warranty_cards_issuer_address', 'eds_warranty_cards_issuer_phone',
    'eds_warranty_cards_issuer_email', 'eds_warranty_cards_issuer_website',
    'eds_warranty_cards_issuer_logo_url',
];
foreach ($issuerFieldNames as $issuerFieldName) {
    check(substr_count($settingsView, 'name="' . $issuerFieldName . '"') === 1, 'issuer settings field is namespaced and rendered once');
}
check(preg_match('/<textarea[^>]+maxlength="2000"[^>]+name="eds_warranty_cards_issuer_address"|<textarea[^>]+name="eds_warranty_cards_issuer_address"[^>]+maxlength="2000"/', $settingsView) === 1, 'issuer address is a bounded multiline field');
check(strpos($settingsView, 'id="eds-warranty-cards-issuer-heading"') < strpos($settingsView, 'id="eds-warranty-cards-number-heading"')
    && strpos($settingsView, 'id="eds-warranty-cards-number-heading"') < strpos($settingsView, 'id="eds-warranty-cards-terms-heading"'), 'issuer, counter and terms sections have the required order');
check(str_contains($settingsView, 'sm:grid-cols-2') && str_contains($settingsView, 'sm:col-span-2'), 'issuer settings use responsive two-column layout without fixed widths');
check(str_contains($boot, 'EdsWarrantyCardTerms::write($pdo, $terms);') && str_contains($boot, 'EdsWarrantyCardIssuer::write($pdo, $issuer);')
    && preg_match('/advanceTo\(.*?static function \(PDO \$pdo\).*?Terms::write.*?Issuer::write/s', $boot) === 1, 'issuer, terms and counter use the same transactional settings callback');
check(!preg_match('/\b(?:curl_|file_get_contents\s*\(\s*\$|fopen\s*\(\s*\$|stream_socket|fsockopen)\b/i', $issuerModel), 'issuer resolution performs no server-side HTTP or arbitrary resource fetch');
check(str_contains($issuedModel, "'issuer' => \$issuer") && !str_contains($issuedModel, 'COMPANY_KEYS')
    && str_contains($issuedModel, 'EdsWarrantyCardIssuer::effective'), 'new issuance resolves and stores the module issuer snapshot');
check(str_contains($cardView, "include __DIR__ . '/client-document.php'") && str_contains($printView, "include __DIR__ . '/client-document.php'")
    && substr_count($clientDocumentView, 'id="eds-warranty-client-document"') === 1, 'preview and print use one shared customer-document template');
check(!str_contains($clientDocumentView, "['device']") && !str_contains($clientDocumentView, "['work_order']")
    && !str_contains($issuedModel, "'device' =>") && !str_contains($issuedModel, "'work_order' =>"), 'new snapshot and shared template contain no device or work-order sections');
check(str_contains($assetController, "'warranty-card-document.css'") && str_contains($assetController, "'warranty-card-pagination.js'")
    && str_contains($cardView, "BASE_URL . '/eds-warranty-cards/assets'")
    && str_contains($printView, "BASE_URL . '/eds-warranty-cards/assets'")
    && str_contains($cardView, '/warranty-card-document.css') && str_contains($printView, '/warranty-card-document.css')
    && str_contains($cardView, '/warranty-card-pagination.js') && str_contains($printView, '/warranty-card-pagination.js'),
    'shared document pagination assets use the module allowlist route');
check(str_contains($assetController, "'quill.js' => ['quill/quill.js'")
    && str_contains($assetController, "'quill.js.map' => ['quill/quill.js.map'")
    && str_contains($assetController, "'quill.snow.css' => ['quill/quill.snow.css'")
    && str_contains($assetController, "'quill.snow.css.map' => ['quill/quill.snow.css.map'")
    && !str_contains($assetController, 'vendor' . '/quill'), 'unchanged public Quill names resolve through the allowlist to the Git-visible local directory');
check(str_contains($clientDocumentView, '<thead>') && str_contains($clientDocumentView, 'eds-warranty-signatures')
    && str_contains($documentStyles, 'display: table-header-group') && str_contains($documentStyles, 'size: A4 portrait'), 'shared document contains tabular rows, signatures and A4 print rules');
check(str_contains($clientDocumentView, 'data-eds-warranty-pagination')
    && str_contains($clientDocumentView, 'eds-warranty-document-source')
    && str_contains($paginationScript, 'eds-warranty-page-shell')
    && str_contains($paginationScript, 'eds-warranty-page__content'), 'shared source document is progressively enhanced into explicit A4 pages');
check(str_contains($paginationScript, 'document.fonts.ready') && str_contains($paginationScript, "image.addEventListener('load'")
    && str_contains($paginationScript, "window.addEventListener('resize'")
    && str_contains($paginationScript, 'source.cloneNode(true)'), 'pagination waits for layout assets and safely rebuilds from one pristine source on resize');
check(str_contains($paginationScript, 'appendTable') && str_contains($paginationScript, 'cloneTextRange')
    && str_contains($paginationScript, 'appendListByItems') && str_contains($paginationScript, 'eds-warranty-signatures') === false,
    'pagination has dedicated table and terms splitting while other blocks remain atomic');
check(!preg_match('#https?://|//cdn#i', $paginationScript) && !str_contains($paginationScript, '.innerHTML'), 'pagination uses no external runtime resources or HTML string injection');
check(str_contains($printView, 'window.edsWarrantyPaginationReady')
    && strpos($printView, 'ready.then') < strpos($printView, 'window.print()'), 'automatic print waits for pagination and retains a no-JavaScript fallback');
check(preg_match('/\.eds-warranty-page\s*\{[^}]*width:\s*210mm;[^}]*height:\s*297mm;[^}]*padding:\s*13mm/s', $documentStyles) === 1
    && preg_match('/\.eds-warranty-pages\s*\{[^}]*gap:\s*12mm/s', $documentStyles) === 1
    && preg_match('/\.eds-warranty-page-shell:last-child\s*\{[^}]*break-after:\s*auto/s', $documentStyles) === 1,
    'screen preview uses separated A4 pages and the last printed page has no trailing break');
check(preg_match('/\.eds-warranty-terms__content p,\s*\.eds-warranty-terms__content ul,\s*\.eds-warranty-terms__content ol,\s*\.eds-warranty-terms__content li\s*\{[^}]*font-size:\s*12px;[^}]*line-height:\s*1\.45/s', $documentStyles) === 1
    && preg_match('/\.eds-warranty-terms__content\s*\{[^}]*overflow-wrap:\s*break-word;[^}]*word-break:\s*normal/s', $documentStyles) === 1,
    'only warranty terms use the requested compact text and natural word wrapping');
check(!str_contains($settingsView, 'contenteditable=') && !str_contains($settingsView, 'data-eds-command') && !str_contains($settingsView, 'eds_warranty_cards_terms_plain'), 'legacy editor fields and controls removed');
check(substr_count($settingsView, 'class="ql-list"') === 2 && substr_count($settingsView, 'class="ql-bold"') === 1, 'toolbar has only bold and two list controls');
check(str_contains($editorScript, "formats: ['bold', 'list']") && !preg_match('/(?:italic|underline|strike|link|image|video|formula|header|color|font|size|align|indent|blockquote|code)/', $editorScript), 'Quill formats are restricted');
check(str_contains($editorScript, "typeof window.Quill !== 'function'") && str_contains($editorScript, '!stylesheet.sheet'), 'Quill starts only after local resources load');
check(strpos($editorScript, 'new window.Quill') < strpos($editorScript, 'textarea.hidden = true'), 'textarea hides only after Quill initializes');
check(str_contains($editorScript, 'textarea.value = quill.getSemanticHTML') && !str_contains($editorScript, 'execCommand'), 'Quill synchronizes semantic HTML without execCommand');
check(preg_match('/#eds_warranty_cards_terms_html\[hidden\],\s*#eds-warranty-cards-quill\[hidden\]\s*\{[^}]*display:\s*none\s*!important;/s', $editorStyles) === 1, 'hidden textarea and Quill shell override display utility classes');
check(str_contains($editorStyles, 'min-height: 300px') && str_contains($editorStyles, 'min-height: 280px'), 'module CSS provides desktop and mobile editor heights');
check(str_contains($settingsView, "BASE_URL . '/eds-warranty-cards/assets'") && str_contains($settingsView, "'/quill.js'") && str_contains($settingsView, "'/quill.snow.css'") && !preg_match('#(?:src|href)="https?://#i', $settingsView), 'editor template uses only module-local resources');
check(hash_file('sha256', $moduleRoot . '/assets/quill/quill.js') === 'f6157c72ac9b3f51cdead426335688a027b12405d9d6a4daadd38a676b2d7ff2', 'official Quill JavaScript is unchanged');
check(hash_file('sha256', $moduleRoot . '/assets/quill/quill.snow.css') === '1c7948cd13aa92fac6390319bc1e5e461823da171519d3a768db56164f871636', 'official Quill Snow CSS is unchanged');
check(str_contains($quillReadme, 'Quill 2.0.3') && str_contains($quillReadme, 'quill-2.0.3.tgz') && str_contains($quillReadme, '3a8a6cb4383b65e93552ea3da79a796eb0be5d2ca08390f1add0208fb23e6f42'), 'Quill version and official source documented');
check(str_contains($quillLicense, 'BSD') === false && str_contains($quillLicense, 'Redistribution and use in source and binary forms') && str_contains($quillLicense, 'Neither the name of the copyright holder'), 'original BSD-3-Clause license included');
check(str_contains($boot, "addRoute('/eds-warranty-cards', 'EdsWarrantyCardRegistryController', 'index'")
    && str_contains($boot, "Hooks::addAction('layout.nav'")
    && str_contains($boot, "['Admin', 'Technician']"), 'registry route and role-limited adaptive navigation are module-owned');
check(str_contains($boot, 'id="eds-warranty-cards-nav-link"')
    && str_contains($boot, 'data-work-orders-url=')
    && str_contains($boot, "BASE_URL . '/work-orders'")
    && str_contains($boot, '/warranty-card-nav.js?v=0.11.1'), 'navigation link exposes one exact module ID and escaped Work orders URL to its local script');
check(str_contains($navigationScript, "document.getElementById('eds-warranty-cards-nav-link')")
    && str_contains($navigationScript, "candidate.getAttribute('href') === workOrdersUrl")
    && str_contains($navigationScript, "insertAdjacentElement('afterend', registryLink)")
    && !str_contains($navigationScript, 'cloneNode') && !str_contains($navigationScript, 'innerHTML')
    && !preg_match('/href\s*\$=|registry_nav|Warranty cards|Гаранционни карти/', $navigationScript), 'navigation enhancement uses exact URL matching and moves rather than clones the existing link');
check(str_contains($registryController, '$this->requireTechnician()')
    && str_contains($registryController, "\$_SERVER['REQUEST_METHOD'] !== 'GET'")
    && str_contains($registryController, 'EdsWarrantyCardRegistry::PAGE_SIZE'), 'registry controller enforces staff access, GET and fixed page size');
check(str_contains($registryModel, 'JSON_EXTRACT') && str_contains($registryModel, 'public_content')
    && !str_contains($registryModel, 'internal_content') && !preg_match('/\bJOIN\s+customers\b/i', $registryModel), 'registry searches only frozen public snapshots and never loads internal or current customer data');
check(str_contains($registryModel, 'number_key = ?') && str_contains($registryModel, 'EdsWarrantyCardNumber::identityKey')
    && str_contains($registryModel, 'LIMIT ? OFFSET ?') && str_contains($registryModel, 'COUNT(*)')
    && str_contains($registryModel, 'issued_at DESC, i.work_order_id DESC'), 'registry uses exact arbitrary-length number identity and database pagination with stable ordering');
check(substr_count($registryView, '<th scope="col">') === 4
    && str_contains($registryView, "'/work-orders/view/' . \$card['work_order_id'] . '/eds-warranty-card'")
    && str_contains($registryView, 'target="_blank" rel="noopener noreferrer"')
    && substr_count($registryView, 'class="eds-warranty-registry__action-buttons"') === 1, 'registry renders exactly four columns and groups its existing view and print routes safely');
check(!str_contains($registryView, "['customer_phone']") && !str_contains($registryView, 'internal_content')
    && !str_contains($registryView, "['customer_company']") && !str_contains($registryView, 'work_order_number'), 'registry view receives no hidden phone, internal, customer-company or work-order details');
check(str_contains($assetController, "'warranty-card-registry.css'")
    && str_contains($assetController, "'warranty-card-nav.js'")
    && str_contains($registryStyles, '@media (max-width: 640px)')
    && str_contains($registryStyles, 'width: 100%'), 'allowlisted registry styles stay compact and adaptive');
check(preg_match('/@media \(min-width: 641px\).*?th:last-child\s*\{[^}]*text-align:\s*right;.*?\.eds-warranty-registry__actions\s*\{[^}]*flex-wrap:\s*nowrap;[^}]*justify-content:\s*flex-end;/s', $registryStyles) === 1,
    'desktop-only registry CSS aligns the Actions heading and buttons right without changing mobile flow');
check(preg_match('/@media \(max-width: 640px\).*?box-sizing:\s*border-box;/s', $registryStyles) === 1,
    'mobile registry rows include padding inside their responsive width');
check(preg_match('/@media \(max-width: 640px\).*?\.eds-warranty-registry__search\s*\{[^}]*width:\s*100%;[^}]*flex:\s*0 0 auto;[^}]*flex-direction:\s*column;/s', $registryStyles) === 1
    && preg_match('/@media \(max-width: 640px\).*?\.eds-warranty-registry__search input\s*\{[^}]*height:\s*auto;[^}]*min-height:\s*2\.5rem;[^}]*flex:\s*0 0 auto;/s', $registryStyles) === 1,
    'mobile search overrides the large desktop flex basis and keeps a normal single-line input');
check(str_contains($registryStyles, '.eds-warranty-registry__action-buttons')
    && preg_match('/@media \(max-width: 640px\).*?\.eds-warranty-registry__actions\s*\{[^}]*display:\s*grid;[^}]*grid-template-columns:\s*5\.5rem minmax\(0, 1fr\);.*?\.eds-warranty-registry__action-buttons\s*\{[^}]*grid-column:\s*2;[^}]*justify-content:\s*flex-start;/s', $registryStyles) === 1
    && preg_match('/\.eds-warranty-registry__actions a\s*\{[^}]*flex:\s*0 0 auto;[^}]*width:\s*auto;/s', $registryStyles) === 1,
    'mobile action wrapper keeps both compact buttons together after the label');
check(preg_match('/Hooks::addAction\(\'work_order\.view\.after_customer_info\'.*?\}, 20\);/s', $boot) === 1, 'work-order card uses the right-column hook at priority 20');
check(!str_contains($boot, "Hooks::addAction('work_order.view.before_attachments'"), 'old before-attachments hook is not registered');
check(str_contains($boot, "'/work-orders/view/{id}/eds-warranty-card/draft/delete'") && str_contains($boot, "'EdsWarrantyCardDraftController', 'delete'"), 'draft deletion has a separate module-owned route');
check(str_contains($workOrderSection, 'flex flex-wrap gap-2') && !str_contains($workOrderSection, 'ml-2 inline-flex'), 'sidebar actions wrap without fixed spacing');
check(str_contains($draftView, 'name="work_items[') && str_contains($draftView, 'id="eds-work-item-template"') && str_contains($draftView, "var key = 'w_'"), 'work activities use repeatable fields, a template and stable random keys');
check(str_contains($draftView, 'data-eds-remove-work') && str_contains($draftView, "document.getElementById('eds-no-work-items').hidden"), 'work activity removal updates the empty-state message');
check(substr_count($draftView, 'maxlength="<?= $draftFieldMax[$field] ?>"') === 3
    && substr_count($draftView, 'maxlength="<?= EdsWarrantyCardDraftLimits::WORK_DESCRIPTION_LENGTH ?>"') === 2
    && substr_count($draftView, 'maxlength="<?= EdsWarrantyCardDraftLimits::MONTHS_DIGITS ?>"') === 2,
    'saved, inventory and template draft fields expose the centralized maxlength limits');
check(str_contains($draftView, 'data-max-rows="<?= EdsWarrantyCardDraftLimits::MANUAL_ROWS ?>"')
    && str_contains($draftView, 'data-max-rows="<?= EdsWarrantyCardDraftLimits::WORK_ITEMS ?>"')
    && substr_count($draftView, 'window.alert(') === 2,
    'dynamic row controls stop at the localized manual-part and work-activity limits');
check(!str_contains($draftView, 'name="work_enabled"') && !str_contains($draftView, 'name="work_description"') && !str_contains($draftView, '<textarea id="eds-work-description"'), 'legacy checkbox and multiline work field are removed');
check(str_contains($draftView, '<input type="text" maxlength="<?= EdsWarrantyCardDraftLimits::WORK_DESCRIPTION_LENGTH ?>"')
    && str_contains($draftView, '<input type="text" inputmode="numeric" maxlength="<?= EdsWarrantyCardDraftLimits::MONTHS_DIGITS ?>"'),
    'work activity fields are single-line text inputs with numeric input mode for months');
check(str_contains($draftView, 'id="eds-delete-draft-form"') && str_contains($draftView, "window.confirm(deleteDraftForm.dataset.confirm)")
    && str_contains($draftView, 'event.preventDefault()') && str_contains($draftView, '[data-eds-delete-confirmed]'), 'saved draft deletion requires an explicit confirmation and cancellation stops submission');
check(substr_count($draftView, 'name="draft_action"') === 2
    && str_contains($draftView, 'value="save"') && str_contains($draftView, 'value="save_and_review"'), 'draft editor exposes only the two explicit save actions');
check(substr_count($draftView, 'form="eds-warranty-card-draft-form"') === 2
    && str_contains($draftView, 'class="eds-draft-actions"') && str_contains($draftView, '.eds-draft-save-actions'), 'separate draft forms share a responsive external action bar');
check(str_contains($draftController, "in_array(\$requestedAction, ['save', 'save_and_review'], true)")
    && str_contains($draftController, "\$draftAction === 'save_and_review'")
    && str_contains($draftController, "'/work-orders/view/' . \$id . '/eds-warranty-card'")
    && !str_contains($draftController, "redirect(\$_POST") && !str_contains($draftController, "redirectWithFlash(\$_POST"), 'draft action is allowlisted and can select only fixed module destinations');
check(EdsWarrantyCardNumber::validate('00004') === '00004', 'leading zero preservation');
foreach ([null, [], 1, '', '0', '000', '-1', '+1', '1.5', '1e3', ' 1', '1 ', "1\n", '１２', '<script>'] as $invalid) {
    rejects(fn() => EdsWarrantyCardNumber::validate($invalid), t('eds_warranty_cards.invalid_number'));
}
foreach ([['00003', '3', 0], ['00004', '4', 0], ['4', '00004', 0], ['00004', '00003', 1], ['00004', '10004', -1], ['00003', '00002', 1], ['00003', '00005', -1], ['00003', '00023', -1], ['00003', '10000', -1], ['9999999999', '10000000000', -1], ['9007199254740993', '9007199254740992', 1], ['18446744073709551616', '18446744073709551615', 1]] as [$left, $right, $expected]) {
    check(EdsWarrantyCardNumber::compare($left, $right) === $expected, 'exact comparison');
}
foreach (['1' => '2', '4' => '5', '00003' => '00004', '00004' => '00005', '00009' => '00010', '09999' => '10000', '99999' => '100000', '1099' => '1100', '9999999999' => '10000000000', '9007199254740992' => '9007199254740993', '18446744073709551615' => '18446744073709551616'] as $input => $expected) {
    check(EdsWarrantyCardNumber::increment((string) $input) === $expected, 'exact increment');
}
$huge = str_repeat('9', 1000);
check(EdsWarrantyCardNumber::increment($huge) === '1' . str_repeat('0', 1000), '1000-digit carry');
check(EdsWarrantyCardNumber::increment('000' . $huge) === '001' . str_repeat('0', 1000), '1003-digit padded carry');
check(EdsWarrantyCardNumber::compare('000' . $huge, $huge) === 0, '1000-digit numeric equality');
check(eds_warranty_cards_format_months('1') === '1 месец'
    && eds_warranty_cards_format_months('0001') === '0001 месец', 'month singular uses numeric string value and preserves leading zeros');
check(eds_warranty_cards_format_months('2') === '2 месеца'
    && eds_warranty_cards_format_months($huge) === $huge . ' месеца', 'month plural preserves ordinary and arbitrarily large numeric strings exactly');
$fieldBoundaries = [
    ['name', EdsWarrantyCardDraftLimits::MANUAL_PART_NAME_LENGTH, 'eds_warranty_cards.part_name_too_long'],
    ['serial_number', EdsWarrantyCardDraftLimits::SERIAL_NUMBER_LENGTH, 'eds_warranty_cards.serial_number_too_long'],
    ['supplier', EdsWarrantyCardDraftLimits::SUPPLIER_LENGTH, 'eds_warranty_cards.supplier_too_long'],
    ['supplier_card', EdsWarrantyCardDraftLimits::SUPPLIER_CARD_LENGTH, 'eds_warranty_cards.supplier_card_too_long'],
    ['description', EdsWarrantyCardDraftLimits::WORK_DESCRIPTION_LENGTH, 'eds_warranty_cards.work_description_too_long'],
];
foreach ($fieldBoundaries as [$field, $limit, $messageKey]) {
    $exact = str_repeat('я', $limit);
    check(EdsWarrantyCardDraftLimits::normalizeField($field, $exact) === $exact, $field . ' accepts its exact Unicode-character limit');
    rejects(fn() => EdsWarrantyCardDraftLimits::normalizeField($field, $exact . 'я'), t($messageKey));
}
check(EdsWarrantyCardDraftLimits::normalizeField('supplier', '  Доставчик  ') === 'Доставчик', 'draft text trims only external spacing');
foreach (["bad\xFF", "line\nbreak", "line\rbreak", "tab\tvalue", "zero\x00byte", "direction\u{202E}mark"] as $invalidDraftText) {
    rejects(fn() => EdsWarrantyCardDraftLimits::normalizeField('serial_number', $invalidDraftText), t('eds_warranty_cards.invalid_draft_text'));
}
check(EdsWarrantyCardDraftLimits::normalizeMonths('') === ''
    && EdsWarrantyCardDraftLimits::normalizeMonths('000001') === '000001'
    && EdsWarrantyCardDraftLimits::normalizeMonths('999999') === '999999', 'empty and six-digit positive draft periods are accepted without stripping zeroes');
rejects(fn() => EdsWarrantyCardDraftLimits::normalizeMonths('1000000'), t('eds_warranty_cards.months_too_long'));
foreach (['0', '000000', '-1', '+1', '1.5', '１２'] as $invalidDraftMonths) {
    rejects(fn() => EdsWarrantyCardDraftLimits::normalizeMonths($invalidDraftMonths), t('eds_warranty_cards.invalid_months'));
}
$hundredRows = array_fill(0, 100, []);
$fiftyRows = array_fill(0, 50, []);
EdsWarrantyCardDraftLimits::assertRawCounts($hundredRows, $hundredRows, $fiftyRows);
check(true, 'raw row counts at the exact limits are accepted');
rejects(fn() => EdsWarrantyCardDraftLimits::assertRawCounts(array_fill(0, 101, []), [], []), t('eds_warranty_cards.too_many_parts'));
rejects(fn() => EdsWarrantyCardDraftLimits::assertRawCounts([], array_fill(0, 101, []), []), t('eds_warranty_cards.too_many_parts'));
rejects(fn() => EdsWarrantyCardDraftLimits::assertRawCounts([], [], array_fill(0, 51, [])), t('eds_warranty_cards.too_many_work_items'));
check(EdsWarrantyCardDraftLimits::inventoryUnitCount([['quantity' => '100']]) === 100, 'exact inventory physical-unit limit is accepted without expansion');
rejects(fn() => EdsWarrantyCardDraftLimits::inventoryUnitCount([['quantity' => str_repeat('9', 1000)]]), t('eds_warranty_cards.too_many_inventory_units'));
rejects(fn() => EdsWarrantyCardDraftLimits::inventoryUnitCount([['quantity' => '101']]), t('eds_warranty_cards.too_many_inventory_units'));
EdsWarrantyCardDraftLimits::assertJsonSize(str_repeat('x', EdsWarrantyCardDraftLimits::JSON_BYTES));
check(true, 'canonical JSON at exactly 256 KiB is accepted');
rejects(fn() => EdsWarrantyCardDraftLimits::assertJsonSize(str_repeat('x', EdsWarrantyCardDraftLimits::JSON_BYTES + 1)), t('eds_warranty_cards.draft_too_large'));
$tooManyManualPost = ['revision' => '0', 'inventory_token' => 'token', 'form_complete' => '1', 'manual_parts' => []];
for ($i = 0; $i < 101; $i++) {
    $tooManyManualPost['manual_parts']['m_' . str_pad(dechex($i), 32, '0', STR_PAD_LEFT)] = EdsWarrantyCardDraftForm::blankManual();
}
$tooManyManualParsed = EdsWarrantyCardDraftForm::read($tooManyManualPost);
check($tooManyManualParsed['invalid'] && $tooManyManualParsed['manual_parts'] === []
    && $tooManyManualParsed['validation_error'] === t('eds_warranty_cards.too_many_parts'), 'oversized forged empty manual-row POST is rejected before row traversal');
$tooManyWorkPost = ['revision' => '0', 'inventory_token' => 'token', 'form_complete' => '1', 'work_items' => []];
for ($i = 0; $i < 51; $i++) {
    $tooManyWorkPost['work_items']['w_' . str_pad(dechex($i), 32, '0', STR_PAD_LEFT)] = EdsWarrantyCardDraftForm::blankWorkItem();
}
$tooManyWorkParsed = EdsWarrantyCardDraftForm::read($tooManyWorkPost);
check($tooManyWorkParsed['invalid'] && $tooManyWorkParsed['work_items'] === []
    && $tooManyWorkParsed['validation_error'] === t('eds_warranty_cards.too_many_work_items'), 'oversized forged empty work-row POST is rejected before row traversal');
require __DIR__ . '/terms.php';
require __DIR__ . '/issuer.php';

$socket = getenv('EDS_WARRANTY_CARDS_TEST_SOCKET');
if (!$socket) {
    echo "PASS: $checks checks; database checks skipped (no isolated test socket).\n";
    exit;
}
if (!str_starts_with($socket, '/tmp/') || !is_file(dirname($socket) . '/isolated-test-server')) {
    throw new RuntimeException('Use only a marked isolated server in /tmp.');
}
function connection(): PDO {
    global $socket;
    return new PDO('mysql:unix_socket=' . $socket . ';dbname=eds_warranty_cards_test;charset=utf8mb4', 'root', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}
$admin = new PDO('mysql:unix_socket=' . $socket, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
// Fixed disposable test database only; never reads application configuration.
$admin->exec('CREATE DATABASE IF NOT EXISTS eds_warranty_cards_test');
unset($admin);
$pdo = connection();
$pdo->exec('DROP TABLE IF EXISTS eds_warranty_cards_test_issued');
$pdo->exec('DROP TABLE IF EXISTS eds_warranty_cards_issued');
$pdo->exec('DROP TABLE IF EXISTS eds_warranty_cards_drafts');
$pdo->exec('DROP TABLE IF EXISTS eds_warranty_cards_settings');
$pdo->exec('DROP TABLE IF EXISTS eds_warranty_cards_counter');
$pdo->exec('DROP TABLE IF EXISTS work_order_products');
$pdo->exec('DROP TABLE IF EXISTS work_orders');
$pdo->exec('DROP TABLE IF EXISTS customers');
$pdo->exec('DROP TABLE IF EXISTS users');
$pdo->exec('DROP TABLE IF EXISTS settings');
$pdo->exec('CREATE TABLE users (id INT PRIMARY KEY) ENGINE=InnoDB');
$pdo->exec('CREATE TABLE customers (id INT PRIMARY KEY, name VARCHAR(100) NULL, company VARCHAR(100) NULL, email VARCHAR(100) NULL, phone VARCHAR(100) NULL) ENGINE=InnoDB');
$pdo->exec('CREATE TABLE settings (id INT AUTO_INCREMENT PRIMARY KEY, setting_key VARCHAR(100) NOT NULL UNIQUE, setting_value TEXT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL) ENGINE=InnoDB');
$pdo->exec('CREATE TABLE work_orders (
    id INT PRIMARY KEY, work_order_number VARCHAR(100) NULL, customer_id INT NULL,
    computer VARCHAR(100) NULL, model VARCHAR(100) NULL, serial_number VARCHAR(100) NULL,
    imei VARCHAR(100) NULL, remarks TEXT NULL, accessories TEXT NULL, description TEXT NULL,
    resolution TEXT NULL, status VARCHAR(50) NULL, priority VARCHAR(50) NULL, created_at DATETIME NULL
) ENGINE=InnoDB');
$pdo->exec("CREATE TABLE eds_warranty_cards_settings (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    terms_html LONGTEXT NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("INSERT INTO eds_warranty_cards_settings VALUES (1, '<p>Legacy preserved terms</p>', NOW())");
check(eds_warranty_cards_needs_migration($pdo), 'missing schema detected');
eds_warranty_cards_migrate($pdo);
check(!eds_warranty_cards_needs_migration($pdo), 'physical schema ready');
check($pdo->query("SHOW COLUMNS FROM eds_warranty_cards_settings LIKE 'terms_html'")->fetch(PDO::FETCH_ASSOC)['Type'] === 'longtext', 'module-owned terms storage supports full UTF-8 limit');
check(EdsWarrantyCardTerms::read($pdo) === '<p>Legacy preserved terms</p>', 'issuer migration preserves existing warranty terms');
foreach (['issuer_service_name', 'issuer_legal_name', 'issuer_registration_number', 'issuer_representative', 'issuer_address', 'issuer_phone', 'issuer_email', 'issuer_website', 'issuer_logo_url'] as $issuerColumn) {
    check((bool) $pdo->query("SHOW COLUMNS FROM eds_warranty_cards_settings LIKE " . $pdo->quote($issuerColumn))->fetch(PDO::FETCH_ASSOC), 'issuer schema column exists');
}
$fourByteLimit = str_repeat('😀', 20000);
EdsWarrantyCardTerms::write($pdo, EdsWarrantyCardTerms::sanitizeHtml($fourByteLimit));
check(EdsWarrantyCardTerms::read($pdo) === $fourByteLimit, 'database stores 20000 four-byte UTF-8 characters exactly');
$generalSetting = $pdo->prepare('INSERT INTO settings (setting_key,setting_value,created_at,updated_at) VALUES (?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_at=NOW()');
foreach (['company_name'=>'Fallback Service','company_address'=>'Fallback Address','company_phone'=>'111','company_email'=>'fallback@example.test','company_website'=>'https://fallback.example.test','company_logo_url'=>'/fallback-logo.svg'] as $key => $value) {
    $generalSetting->execute([$key, $value]);
}
check(EdsWarrantyCardIssuer::read($pdo) === EdsWarrantyCardIssuer::blank(), 'empty module issuer fields remain empty in storage');
$fallbackIssuer = EdsWarrantyCardIssuer::effective($pdo);
check($fallbackIssuer['service_name'] === 'Fallback Service' && $fallbackIssuer['address'] === 'Fallback Address'
    && $fallbackIssuer['phone'] === '111' && $fallbackIssuer['email'] === 'fallback@example.test'
    && $fallbackIssuer['website'] === 'https://fallback.example.test' && $fallbackIssuer['logo_url'] === '/fallback-logo.svg'
    && $fallbackIssuer['legal_name'] === '' && $fallbackIssuer['registration_number'] === '' && $fallbackIssuer['representative'] === '', 'empty module issuer fields use only the agreed general fallbacks');
check(EdsWarrantyCardIssuer::read($pdo) === EdsWarrantyCardIssuer::blank(), 'effective fallback values are not copied into module settings');
$counter = new EdsWarrantyCardCounter($pdo);
check($counter->getNextNumber() === '1', 'initial next number');
$counter->advanceTo('00003');
check($counter->getNextNumber() === '00003', 'stored format preserved');
$counter->advanceTo('3');
check($counter->getNextNumber() === '3', 'same value accepts shorter format');
rejects(fn() => $counter->advanceTo('00002'), t('eds_warranty_cards.number_cannot_decrease'));
check($counter->getNextNumber() === '3', 'decrease changes nothing');
$counter->advanceTo('00005');
check($counter->getNextNumber() === '00005', 'increase preserves padding');
$counter->advanceTo('00023');
$counter->advanceTo('10000');
eds_warranty_cards_migrate($pdo);
check($counter->getNextNumber() === '10000', 'repeated migration preserves counter');
$pdo->exec('DELETE FROM eds_warranty_cards_counter WHERE id = 1');
check(eds_warranty_cards_needs_migration($pdo), 'missing singleton detected');
eds_warranty_cards_migrate($pdo);
$counter = new EdsWarrantyCardCounter($pdo);
$atomicNumber = $counter->getNextNumber();
$atomicIssuer = EdsWarrantyCardIssuer::fromPost(issuerPost([
    'eds_warranty_cards_issuer_service_name' => 'Атомен сервиз',
    'eds_warranty_cards_issuer_legal_name' => 'Атомна фирма ООД',
    'eds_warranty_cards_issuer_registration_number' => 'BG-ATOMIC',
    'eds_warranty_cards_issuer_representative' => 'Атомен управител',
    'eds_warranty_cards_issuer_address' => 'Атомен адрес',
    'eds_warranty_cards_issuer_phone' => '+359 111',
    'eds_warranty_cards_issuer_email' => 'atomic@example.test',
    'eds_warranty_cards_issuer_website' => 'https://atomic.example.test',
    'eds_warranty_cards_issuer_logo_url' => '/atomic-logo.svg',
]));
$counter->advanceTo($atomicNumber, static function (PDO $pdo) use ($atomicIssuer): void {
    EdsWarrantyCardTerms::write($pdo, '<p>Atomic terms</p>');
    EdsWarrantyCardIssuer::write($pdo, $atomicIssuer);
});
check(EdsWarrantyCardTerms::read($pdo) === '<p>Atomic terms</p>' && EdsWarrantyCardIssuer::read($pdo) === $atomicIssuer
    && $counter->getNextNumber() === $atomicNumber, 'issuer, terms and unchanged formatted counter save together');
try {
    $counter->advanceTo($atomicNumber, static function (PDO $pdo): void {
        EdsWarrantyCardTerms::write($pdo, '<p>Must roll back</p>');
        EdsWarrantyCardIssuer::write($pdo, EdsWarrantyCardIssuer::fromPost(issuerPost(['eds_warranty_cards_issuer_service_name' => 'Must roll back'])));
        throw new RuntimeException('settings rollback');
    });
    throw new RuntimeException('Expected settings rollback.');
} catch (RuntimeException $error) {
    check($error->getMessage() === 'settings rollback', 'settings failure propagated');
}
check(EdsWarrantyCardTerms::read($pdo) === '<p>Atomic terms</p>' && EdsWarrantyCardIssuer::read($pdo) === $atomicIssuer
    && $counter->getNextNumber() === $atomicNumber, 'issuer, terms and counter roll back together');
eds_warranty_cards_migrate($pdo);
check(EdsWarrantyCardTerms::read($pdo) === '<p>Atomic terms</p>' && EdsWarrantyCardIssuer::read($pdo) === $atomicIssuer
    && $counter->getNextNumber() === $atomicNumber && (int) $pdo->query('SELECT COUNT(*) FROM eds_warranty_cards_settings')->fetchColumn() === 1, 'repeated migration preserves issuer, terms and singleton row');
$pdo->exec('CREATE TABLE eds_warranty_cards_test_issued (card_id INT PRIMARY KEY, number VARCHAR(2048) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE) ENGINE=InnoDB');
function persistTestCard(int $cardId): callable {
    return function (string $number, PDO $pdo) use ($cardId): bool {
        $existing = $pdo->prepare('SELECT number FROM eds_warranty_cards_test_issued WHERE card_id = ? FOR UPDATE');
        $existing->execute([$cardId]);
        if ($existing->fetchColumn() !== false) {
            return false;
        }
        $stmt = $pdo->prepare('INSERT INTO eds_warranty_cards_test_issued VALUES (?, ?)');
        $stmt->execute([$cardId, $number]);
        return true;
    };
}
$counter->advanceTo('4');
$counter->advanceTo('00004');
check($counter->getNextNumber() === '00004', 'existing plain counter accepts equal padded format');
rejects(fn() => $counter->advanceTo('00003'), t('eds_warranty_cards.number_cannot_decrease'));
check($counter->getNextNumber() === '00004', 'rejected decrease preserves exact format');
eds_warranty_cards_migrate($pdo);
check($counter->getNextNumber() === '00004', 'repeated migration preserves padding');
check($counter->issue(persistTestCard(10)) === '00004', 'issued number retains format');
check($counter->getNextNumber() === '00005', 'issued counter retains width');
$counter->advanceTo('5');
check($counter->getNextNumber() === '5', 'equal value changes next format after issuance');
check($pdo->query('SELECT number FROM eds_warranty_cards_test_issued WHERE card_id = 10')->fetchColumn() === '00004', 'issued number unchanged after format change');
check($counter->issue(persistTestCard(10)) === null, 'reissue after format change consumes nothing');
check($counter->getNextNumber() === '5', 'reissue preserves new next format');
check($counter->issue(persistTestCard(11)) === '5', 'unformatted issuance adds no padding');
check($counter->getNextNumber() === '6', 'unformatted counter stays unformatted');
$counter->advanceTo('00006');
$counter->advanceTo('10004');
check($counter->getNextNumber() === '10004', 'increase may replace padded format');
$counter->advanceTo('9999999999');
check($counter->issue(persistTestCard(1)) === '9999999999', 'assign current number');
check($counter->getNextNumber() === '10000000000', 'cross old maximum');
check($counter->issue(persistTestCard(1)) === null, 'repeated issuance does not allocate');
check($counter->getNextNumber() === '10000000000', 'repeat preserves counter');
try {
    $counter->issue(function (string $number, PDO $pdo): bool {
        persistTestCard(2)($number, $pdo);
        throw new RuntimeException('simulated failure');
    });
    throw new RuntimeException('Expected rollback.');
} catch (RuntimeException $error) {
    check($error->getMessage() === 'simulated failure', 'issuance failure propagated');
}
check($counter->getNextNumber() === '10000000000', 'rollback preserves counter');
check((int) $pdo->query('SELECT COUNT(*) FROM eds_warranty_cards_test_issued WHERE card_id = 2')->fetchColumn() === 0, 'rollback removes issued card');
$counter->advanceTo('000' . $huge);
check($counter->getNextNumber() === '000' . $huge, '1003-digit padded storage');
check($counter->issue(persistTestCard(2)) === '000' . $huge, '1003-digit padded issuance');
check($counter->getNextNumber() === '001' . str_repeat('0', 1000), 'large padded counter preserves width');

if (!function_exists('pcntl_fork')) {
    throw new RuntimeException('pcntl required for concurrent database checks.');
}
// Close all parent connections before fork; each worker creates its own session.
unset($counter, $pdo);
$workers = [];
for ($i = 0; $i < 12; $i++) {
    $pid = pcntl_fork();
    if ($pid === -1) {
        throw new RuntimeException('fork failed');
    }
    if ($pid === 0) {
        try {
            (new EdsWarrantyCardCounter(connection()))->issue(persistTestCard(3 + intdiv($i, 2)));
            exit(0);
        } catch (Throwable $error) {
            fwrite(STDERR, $error->getMessage() . "\n");
            exit(1);
        }
    }
    $workers[] = $pid;
}
foreach ($workers as $pid) {
    pcntl_waitpid($pid, $status);
    check(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0, 'concurrent worker completed');
}
$pdo = connection();
$counter = new EdsWarrantyCardCounter($pdo);
$after = '001' . str_repeat('0', 999) . '6';
check($counter->getNextNumber() === $after, 'six distinct cards consume six numbers');
check((int) $pdo->query('SELECT COUNT(*) FROM eds_warranty_cards_test_issued')->fetchColumn() === 10, 'no duplicate cards');
check((int) $pdo->query('SELECT COUNT(DISTINCT number) FROM eds_warranty_cards_test_issued')->fetchColumn() === 10, 'no duplicate numbers');

// Hold the issuance lock while a settings save tries the previously rendered value.
$signal = tempnam('/tmp', 'eds-warranty-cards-lock-');
unlink($signal);
unset($counter, $pdo);
$pid = pcntl_fork();
if ($pid === -1) {
    throw new RuntimeException('fork failed');
}
if ($pid === 0) {
    try {
        (new EdsWarrantyCardCounter(connection()))->issue(function (string $number, PDO $pdo) use ($signal): bool {
            file_put_contents($signal, 'locked');
            usleep(300000);
            return persistTestCard(9)($number, $pdo);
        });
        exit(0);
    } catch (Throwable $error) {
        fwrite(STDERR, $error->getMessage() . "\n");
        exit(1);
    }
}
$deadline = microtime(true) + 5;
while (!is_file($signal) && microtime(true) < $deadline) {
    usleep(10000);
}
check(is_file($signal), 'issuance lock acquired');
$pdo = connection();
$counter = new EdsWarrantyCardCounter($pdo);
$termsBeforeStaleSave = EdsWarrantyCardTerms::read($pdo);
$issuerBeforeStaleSave = EdsWarrantyCardIssuer::read($pdo);
rejects(fn() => $counter->advanceTo('000' . $after, static function (PDO $pdo): void {
    EdsWarrantyCardTerms::write($pdo, '<p>Stale terms</p>');
    EdsWarrantyCardIssuer::write($pdo, EdsWarrantyCardIssuer::fromPost(issuerPost(['eds_warranty_cards_issuer_service_name' => 'Stale issuer'])));
}), t('eds_warranty_cards.number_cannot_decrease'));
pcntl_waitpid($pid, $status);
unlink($signal);
check(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0, 'lock holder committed');
check($counter->getNextNumber() === EdsWarrantyCardNumber::increment($after), 'settings cannot rewind issuance');
check(EdsWarrantyCardTerms::read($pdo) === $termsBeforeStaleSave && EdsWarrantyCardIssuer::read($pdo) === $issuerBeforeStaleSave, 'rejected concurrent settings save cannot change terms or issuer');

// Two settings requests: the stale lower value must see the committed higher one.
$raised = EdsWarrantyCardNumber::increment($counter->getNextNumber());
unset($counter, $pdo);
$pid = pcntl_fork();
if ($pid === -1) {
    throw new RuntimeException('fork failed');
}
if ($pid === 0) {
    try {
        (new EdsWarrantyCardCounter(connection()))->advanceTo('000' . $raised, static function (PDO $pdo): void {
            EdsWarrantyCardTerms::write($pdo, '<p>Raised terms</p>');
            EdsWarrantyCardIssuer::write($pdo, EdsWarrantyCardIssuer::fromPost(issuerPost(['eds_warranty_cards_issuer_service_name' => 'Raised issuer'])));
        });
        exit(0);
    } catch (Throwable $error) {
        exit(1);
    }
}
pcntl_waitpid($pid, $status);
check(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0, 'settings increase committed');
$pdo = connection();
$counter = new EdsWarrantyCardCounter($pdo);
rejects(fn() => $counter->advanceTo($after, static function (PDO $pdo): void {
    EdsWarrantyCardTerms::write($pdo, '<p>Rewound terms</p>');
    EdsWarrantyCardIssuer::write($pdo, EdsWarrantyCardIssuer::fromPost(issuerPost(['eds_warranty_cards_issuer_service_name' => 'Rewound issuer'])));
}), t('eds_warranty_cards.number_cannot_decrease'));
check($counter->getNextNumber() === '000' . $raised, 'stale settings cannot rewind settings or change format');
check(EdsWarrantyCardTerms::read($pdo) === '<p>Raised terms</p>' && EdsWarrantyCardIssuer::read($pdo)['service_name'] === 'Raised issuer', 'stale settings cannot overwrite newer warranty terms or issuer');
require __DIR__ . '/drafts.php';
require __DIR__ . '/issuance.php';
require __DIR__ . '/print.php';
require __DIR__ . '/registry.php';
echo "PASS: $checks checks including isolated MariaDB transactions and concurrent issuance/settings.\n";
