<?php
$documentContent = is_array($clientCard['content'] ?? null) ? $clientCard['content'] : [];
$documentCardNumber = (string) ($clientCard['card_number'] ?? '');
$documentIssuedAt = (string) ($clientCard['issued_at'] ?? '');
$documentMode = 'print';
$title = t('eds_warranty_cards.print_title') . ' № ' . $documentCardNumber;
$documentAssetBaseUrl = BASE_URL . '/eds-warranty-cards/assets';
$documentAssetVersion = '0.10.0';
?>
<!DOCTYPE html>
<html lang="<?= eds_warranty_cards_escape(I18n::getInstance()->getLocale()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title><?= eds_warranty_cards_escape($title) ?></title>
    <link rel="stylesheet" href="<?= eds_warranty_cards_escape($documentAssetBaseUrl . '/warranty-card-document.css?v=' . $documentAssetVersion) ?>">
    <script src="<?= eds_warranty_cards_escape($documentAssetBaseUrl . '/warranty-card-pagination.js?v=' . $documentAssetVersion) ?>" defer></script>
</head>
<body class="eds-warranty-print">
<main class="eds-warranty-document-stage">
    <?php include __DIR__ . '/client-document.php'; ?>
</main>
<script>
window.addEventListener('load', function () {
    var ready = window.edsWarrantyPaginationReady;
    if (ready && typeof ready.then === 'function') {
        ready.then(function () { window.print(); }, function () { window.print(); });
        return;
    }
    window.print();
});
</script>
</body>
</html>
