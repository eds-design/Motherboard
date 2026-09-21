<?php
if (PHP_SAPI !== 'cli' || !isset($checks)) { exit; }

function issuerPost(array $overrides = []): array {
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

$validIssuer = EdsWarrantyCardIssuer::fromPost(issuerPost([
    'eds_warranty_cards_issuer_service_name' => '  Сервиз „Експерт“  ',
    'eds_warranty_cards_issuer_legal_name' => '  Експерт Сървис ООД  ',
    'eds_warranty_cards_issuer_registration_number' => '  BG-ЕИК-123  ',
    'eds_warranty_cards_issuer_representative' => '  Иван Иванов  ',
    'eds_warranty_cards_issuer_address' => "  София\r\nул. Тест 1  ",
    'eds_warranty_cards_issuer_phone' => '  +359 2 123 456  ',
    'eds_warranty_cards_issuer_email' => '  office@example.test  ',
    'eds_warranty_cards_issuer_website' => '  https://example.test/service  ',
    'eds_warranty_cards_issuer_logo_url' => '  /uploads/лого.svg  ',
]));
check($validIssuer === [
    'service_name' => 'Сервиз „Експерт“',
    'legal_name' => 'Експерт Сървис ООД',
    'registration_number' => 'BG-ЕИК-123',
    'representative' => 'Иван Иванов',
    'address' => "София\nул. Тест 1",
    'phone' => '+359 2 123 456',
    'email' => 'office@example.test',
    'website' => 'https://example.test/service',
    'logo_url' => '/uploads/лого.svg',
], 'issuer fields trim and preserve valid Unicode exactly');

foreach (['not-an-email', 'name@example', 'name @example.test'] as $email) {
    rejects(fn() => EdsWarrantyCardIssuer::fromPost(issuerPost(['eds_warranty_cards_issuer_email' => $email])), t('eds_warranty_cards.issuer_invalid_email'));
}
foreach (['example.test', '/relative', '//example.test', 'javascript:alert(1)', 'data:text/plain,x', 'vbscript:msgbox(1)', 'ftp://example.test/file'] as $website) {
    rejects(fn() => EdsWarrantyCardIssuer::fromPost(issuerPost(['eds_warranty_cards_issuer_website' => $website])), t('eds_warranty_cards.issuer_invalid_website'));
}
foreach (['javascript:alert(1)', 'data:image/png;base64,AAAA', 'vbscript:msgbox(1)', '//example.test/logo.png', 'ftp://example.test/logo.png', '/\\example.test/logo.png'] as $logo) {
    rejects(fn() => EdsWarrantyCardIssuer::fromPost(issuerPost(['eds_warranty_cards_issuer_logo_url' => $logo])), t('eds_warranty_cards.issuer_invalid_logo_url'));
}
foreach (['http://example.test/logo.png', 'https://example.test/logo.png?size=2', '/uploads/logo.png'] as $logo) {
    check(EdsWarrantyCardIssuer::fromPost(issuerPost(['eds_warranty_cards_issuer_logo_url' => $logo]))['logo_url'] === $logo, 'safe absolute or root-relative logo accepted');
}

$fieldRules = [
    'eds_warranty_cards_issuer_service_name' => [255, 'eds_warranty_cards.issuer_service_name'],
    'eds_warranty_cards_issuer_legal_name' => [255, 'eds_warranty_cards.issuer_legal_name'],
    'eds_warranty_cards_issuer_registration_number' => [100, 'eds_warranty_cards.issuer_registration_number'],
    'eds_warranty_cards_issuer_representative' => [255, 'eds_warranty_cards.issuer_representative'],
    'eds_warranty_cards_issuer_address' => [2000, 'eds_warranty_cards.issuer_address'],
    'eds_warranty_cards_issuer_phone' => [100, 'eds_warranty_cards.issuer_phone'],
    'eds_warranty_cards_issuer_email' => [254, 'eds_warranty_cards.issuer_email'],
    'eds_warranty_cards_issuer_website' => [2048, 'eds_warranty_cards.issuer_website'],
    'eds_warranty_cards_issuer_logo_url' => [2048, 'eds_warranty_cards.issuer_logo_url'],
];
foreach ($fieldRules as $postName => [$max, $label]) {
    $attack = '<img src=x onerror=alert(1)>';
    rejects(
        fn() => EdsWarrantyCardIssuer::fromPost(issuerPost([$postName => $attack])),
        t('eds_warranty_cards.issuer_invalid_text', ['field' => t($label)])
    );
    rejects(
        fn() => EdsWarrantyCardIssuer::fromPost(issuerPost([$postName => str_repeat('я', $max + 1)])),
        t('eds_warranty_cards.issuer_too_long', ['field' => t($label), 'max' => $max])
    );
}
rejects(
    fn() => EdsWarrantyCardIssuer::fromPost(issuerPost(['eds_warranty_cards_issuer_service_name' => "\xC3\x28"])),
    t('eds_warranty_cards.issuer_invalid_text', ['field' => t('eds_warranty_cards.issuer_service_name')])
);

check(!EdsWarrantyCardIssuer::showLegalName(['service_name' => 'СЕРВИЗ ЕООД', 'legal_name' => 'сервиз еоод']), 'equal issuer names are compared case-insensitively for display');
check(EdsWarrantyCardIssuer::showLegalName(['service_name' => 'Сервиз', 'legal_name' => 'Сервиз ЕООД']), 'different legal name remains visible');
$legacyIssuer = EdsWarrantyCardIssuer::fromPublicContent(['company' => [
    'company_name' => 'Legacy Service', 'company_address' => 'Legacy Address',
    'company_phone' => '123', 'company_email' => 'legacy@example.test',
    'company_website' => 'https://legacy.example.test', 'company_logo_url' => 'javascript:alert(1)',
]]);
check($legacyIssuer['service_name'] === 'Legacy Service' && $legacyIssuer['legal_name'] === '' && $legacyIssuer['logo_url'] === '', 'legacy company snapshot maps without current legal data and unsafe logo fails closed');
check(EdsWarrantyCardIssuer::fromPublicContent(['issuer' => EdsWarrantyCardIssuer::blank()])['service_name'] === '', 'empty issuer snapshot remains empty instead of gaining application data');

$issuerSource = file_get_contents(dirname(__DIR__) . '/models/Issuer.php');
check(!preg_match('/\b(?:curl_|file_get_contents|fopen|stream_socket|Guzzle|HttpClient)\b/i', $issuerSource), 'issuer and logo handling performs no server-side network request');
