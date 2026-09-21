<?php
$documentContent = is_array($documentContent ?? null) ? $documentContent : [];
$documentCardNumber = trim((string) ($documentCardNumber ?? ''));
$documentIssuedAt = trim((string) ($documentIssuedAt ?? ''));
$documentMode = ($documentMode ?? 'screen') === 'print' ? 'print' : 'screen';
$issuer = EdsWarrantyCardIssuer::fromPublicContent($documentContent);
$customer = is_array($documentContent['customer'] ?? null) ? $documentContent['customer'] : [];
$parts = is_array($documentContent['parts'] ?? null) ? $documentContent['parts'] : [];
$workWarranties = EdsWarrantyCardIssued::workWarranties($documentContent);
$termsHtml = EdsWarrantyCardTerms::forDisplay($documentContent['terms_html'] ?? '');
$optional = static fn(array $source, string $key): string => is_scalar($source[$key] ?? null) ? trim((string) $source[$key]) : '';
$serviceName = $optional($issuer, 'service_name');
$issuerName = $optional($issuer, 'legal_name');
if ($issuerName === '') {
    $issuerName = $serviceName;
}
$displayNumber = $documentCardNumber !== '' ? $documentCardNumber : '—';
$displayDate = $documentIssuedAt !== '' ? ldate($documentIssuedAt, 'd.m.Y') : '—';
$rows = [];
foreach ($parts as $part) {
    if (!is_array($part)) {
        continue;
    }
    $rows[] = [
        'name' => $optional($part, 'name'),
        'serial_number' => $optional($part, 'serial_number'),
        'months' => $optional($part, 'months'),
    ];
}
foreach ($workWarranties as $workWarranty) {
    $rows[] = [
        'name' => trim($workWarranty['description']),
        'serial_number' => '',
        'months' => trim($workWarranty['months']),
    ];
}
?>
<div id="eds-warranty-client-document" class="eds-warranty-document-set" data-eds-warranty-pagination>
<article class="eds-warranty-document eds-warranty-document-source eds-warranty-document--<?= eds_warranty_cards_escape($documentMode) ?>">
    <header class="eds-warranty-header">
        <div class="eds-warranty-header__brand">
            <?php if ($issuer['logo_url'] !== ''): ?>
                <img class="eds-warranty-logo" src="<?= eds_warranty_cards_escape($issuer['logo_url']) ?>" alt="<?= eds_warranty_cards_escape($serviceName !== '' ? $serviceName : t('eds_warranty_cards.document_issuer')) ?>">
            <?php endif; ?>
            <?php if ($issuer['website'] !== ''): ?><p class="eds-warranty-header__website"><?= eds_warranty_cards_escape($issuer['website']) ?></p><?php endif; ?>
        </div>
        <div class="eds-warranty-header__service">
            <?php if ($serviceName !== ''): ?><p class="eds-warranty-service-name"><?= eds_warranty_cards_escape($serviceName) ?></p><?php endif; ?>
            <?php if ($issuer['phone'] !== ''): ?><p><?= eds_warranty_cards_escape($issuer['phone']) ?></p><?php endif; ?>
        </div>
    </header>

    <div class="eds-warranty-rule" aria-hidden="true"></div>

    <section class="eds-warranty-title-block" aria-labelledby="eds-warranty-document-title">
        <h1 id="eds-warranty-document-title"><?= eds_warranty_cards_escape(t('eds_warranty_cards.document_title')) ?></h1>
        <div class="eds-warranty-meta">
            <span><?= eds_warranty_cards_escape(t('eds_warranty_cards.document_number', ['number' => $displayNumber])) ?></span>
            <span><?= eds_warranty_cards_escape(t('eds_warranty_cards.document_issue_date', ['date' => $displayDate])) ?></span>
        </div>
    </section>

    <section class="eds-warranty-parties">
        <div class="eds-warranty-party">
            <h2><?= eds_warranty_cards_escape(t('eds_warranty_cards.document_customer')) ?></h2>
            <?php $value = $optional($customer, 'name'); if ($value !== ''): ?><p><span><?= eds_warranty_cards_escape(t('eds_warranty_cards.document_name')) ?>:</span> <?= eds_warranty_cards_escape($value) ?></p><?php endif; ?>
            <?php $value = $optional($customer, 'company'); if ($value !== ''): ?><p><span><?= eds_warranty_cards_escape(t('eds_warranty_cards.document_company')) ?>:</span> <?= eds_warranty_cards_escape($value) ?></p><?php endif; ?>
            <?php $value = $optional($customer, 'phone'); if ($value !== ''): ?><p><span><?= eds_warranty_cards_escape(t('eds_warranty_cards.document_phone')) ?>:</span> <?= eds_warranty_cards_escape($value) ?></p><?php endif; ?>
            <?php $value = $optional($customer, 'email'); if ($value !== ''): ?><p><span><?= eds_warranty_cards_escape(t('eds_warranty_cards.document_email')) ?>:</span> <?= eds_warranty_cards_escape($value) ?></p><?php endif; ?>
        </div>
        <div class="eds-warranty-party">
            <h2><?= eds_warranty_cards_escape(t('eds_warranty_cards.document_issuer')) ?></h2>
            <?php if ($issuerName !== ''): ?><p class="eds-warranty-issuer-name"><?= eds_warranty_cards_escape($issuerName) ?></p><?php endif; ?>
            <?php if ($issuer['registration_number'] !== ''): ?><p><span><?= eds_warranty_cards_escape(t('eds_warranty_cards.issuer_registration_number')) ?>:</span> <?= eds_warranty_cards_escape($issuer['registration_number']) ?></p><?php endif; ?>
            <?php if ($issuer['representative'] !== ''): ?><p><span><?= eds_warranty_cards_escape(t('eds_warranty_cards.issuer_representative')) ?>:</span> <?= eds_warranty_cards_escape($issuer['representative']) ?></p><?php endif; ?>
            <?php if ($issuer['address'] !== ''): ?><p><span><?= eds_warranty_cards_escape(t('eds_warranty_cards.document_address')) ?>:</span> <span class="eds-warranty-pre-line"><?= eds_warranty_cards_escape($issuer['address']) ?></span></p><?php endif; ?>
            <?php if ($issuer['phone'] !== ''): ?><p><span><?= eds_warranty_cards_escape(t('eds_warranty_cards.document_phone')) ?>:</span> <?= eds_warranty_cards_escape($issuer['phone']) ?></p><?php endif; ?>
            <?php if ($issuer['email'] !== ''): ?><p><span><?= eds_warranty_cards_escape(t('eds_warranty_cards.document_email')) ?>:</span> <?= eds_warranty_cards_escape($issuer['email']) ?></p><?php endif; ?>
        </div>
    </section>

    <section class="eds-warranty-items" aria-label="<?= eds_warranty_cards_escape(t('eds_warranty_cards.document_items')) ?>">
        <table>
            <colgroup><col class="eds-warranty-col-number"><col class="eds-warranty-col-name"><col class="eds-warranty-col-serial"><col class="eds-warranty-col-period"></colgroup>
            <thead><tr>
                <th scope="col"><?= eds_warranty_cards_escape(t('eds_warranty_cards.document_row_number')) ?></th>
                <th scope="col"><?= eds_warranty_cards_escape(t('eds_warranty_cards.document_name_service')) ?></th>
                <th scope="col"><?= eds_warranty_cards_escape(t('eds_warranty_cards.document_serial_number')) ?></th>
                <th scope="col"><?= eds_warranty_cards_escape(t('eds_warranty_cards.document_warranty')) ?></th>
            </tr></thead>
            <tbody>
                <?php foreach ($rows as $index => $row): ?>
                    <tr>
                        <td><?= (int) $index + 1 ?></td>
                        <td><?= eds_warranty_cards_escape($row['name']) ?></td>
                        <td><?= eds_warranty_cards_escape($row['serial_number'] !== '' ? $row['serial_number'] : '—') ?></td>
                        <td><?= eds_warranty_cards_escape($row['months'] !== '' ? eds_warranty_cards_format_months($row['months']) : '—') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <?php if ($termsHtml !== ''): ?>
        <section id="warranty-terms" class="eds-warranty-terms">
            <h2 class="eds-warranty-terms__title"><?= eds_warranty_cards_escape(t('eds_warranty_cards.document_terms')) ?></h2>
            <div class="eds-warranty-terms__content"><?= $termsHtml ?></div>
        </section>
    <?php endif; ?>

    <section class="eds-warranty-signatures" aria-label="<?= eds_warranty_cards_escape(t('eds_warranty_cards.document_signatures')) ?>">
        <div class="eds-warranty-signature">
            <p><?= eds_warranty_cards_escape(t('eds_warranty_cards.document_recipient')) ?></p>
            <p class="eds-warranty-signature__line"><?= eds_warranty_cards_escape(t('eds_warranty_cards.document_recipient_signature')) ?></p>
        </div>
        <div class="eds-warranty-signature">
            <p><?= eds_warranty_cards_escape(t('eds_warranty_cards.document_issuer')) ?></p>
            <p class="eds-warranty-signature__line"><?= eds_warranty_cards_escape(t('eds_warranty_cards.document_issuer_signature')) ?></p>
        </div>
    </section>
</article>
</div>
