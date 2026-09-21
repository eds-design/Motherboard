<?php
$title = t('eds_warranty_cards.card') . ' - ' . ($companyName ?? APP_NAME);
$taskUrl = BASE_URL . '/work-orders/view/' . $workOrderId;
$draftUrl = $taskUrl . '/eds-warranty-card/draft';
$documentAssetBaseUrl = BASE_URL . '/eds-warranty-cards/assets';
$documentAssetVersion = '0.10.0';
ob_start();
?>
<link rel="stylesheet" href="<?= eds_warranty_cards_escape($documentAssetBaseUrl . '/warranty-card-document.css?v=' . $documentAssetVersion) ?>">
<script src="<?= eds_warranty_cards_escape($documentAssetBaseUrl . '/warranty-card-pagination.js?v=' . $documentAssetVersion) ?>" defer></script>
<div class="py-8">
    <div class="mb-6 sm:flex sm:items-start sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900"><?= eds_warranty_cards_escape(t('eds_warranty_cards.card')) ?><?= $card ? ' № ' . eds_warranty_cards_escape($card['card_number']) : ' — ' . eds_warranty_cards_escape(t('eds_warranty_cards.preview')) ?></h1>
            <?php if ($card): ?><p class="mt-1 text-sm text-gray-600"><?= eds_warranty_cards_escape(t('eds_warranty_cards.issued_on', ['date' => ldate($card['issued_at'], 'd.m.Y')])) ?></p><?php endif; ?>
        </div>
        <div class="mt-4 sm:mt-0 flex flex-wrap gap-2">
            <a href="<?= eds_warranty_cards_escape($taskUrl) ?>" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50"><?= eds_warranty_cards_escape(t('eds_warranty_cards.back_to_task')) ?></a>
            <?php if ($card && $canIssue): ?><a href="<?= eds_warranty_cards_escape($taskUrl . '/eds-warranty-card/print') ?>" target="_blank" rel="noopener" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-primary-600 hover:bg-primary-700"><?= eds_warranty_cards_escape(t('eds_warranty_cards.print_button')) ?></a><?php endif; ?>
        </div>
    </div>

    <?php if ($error !== ''): ?><div class="mb-6 bg-red-50 border border-red-200 rounded-md p-4" role="alert"><p class="text-sm text-red-600"><?= eds_warranty_cards_escape($error) ?></p></div><?php endif; ?>
    <?php if ($message !== ''): ?><div class="mb-6 bg-green-50 border border-green-200 rounded-md p-4"><p class="text-sm text-green-600"><?= eds_warranty_cards_escape($message) ?></p></div><?php endif; ?>
    <?php if ($validationError !== ''): ?><div class="mb-6 bg-red-50 border border-red-200 rounded-md p-4" role="alert"><p class="text-sm text-red-600"><?= eds_warranty_cards_escape($validationError) ?></p><a href="<?= eds_warranty_cards_escape($draftUrl) ?>" class="mt-2 inline-flex text-sm text-primary-600"><?= eds_warranty_cards_escape(t('eds_warranty_cards.return_to_edit')) ?></a></div><?php endif; ?>

    <section class="mb-6" aria-labelledby="eds-client-content">
        <h2 id="eds-client-content" class="sr-only"><?= eds_warranty_cards_escape(t('eds_warranty_cards.client_content')) ?></h2>
        <div class="eds-warranty-document-stage">
            <?php
            $documentContent = $publicContent;
            $documentCardNumber = $card ? (string) $card['card_number'] : '';
            $documentIssuedAt = $card ? (string) $card['issued_at'] : '';
            $documentMode = 'screen';
            include __DIR__ . '/client-document.php';
            ?>
        </div>
    </section>

    <?php if ($internalContent): ?>
        <section class="bg-yellow-50 border border-yellow-200 rounded-lg mb-6">
            <div class="px-6 py-4">
                <h2 class="text-lg font-medium text-gray-900"><?= eds_warranty_cards_escape(t('eds_warranty_cards.internal_information')) ?></h2>
                <p class="text-sm text-gray-600 mb-4"><?= eds_warranty_cards_escape(t('eds_warranty_cards.internal_not_for_customer')) ?></p>
                <?php foreach ($internalContent['parts'] as $index => $internal): $part = $publicContent['parts'][$index] ?? null; if ($part): ?>
                    <div class="mb-3">
                        <p class="text-sm font-medium text-gray-900"><?= eds_warranty_cards_escape($part['name']) ?></p>
                        <p class="text-sm text-gray-700"><?= eds_warranty_cards_escape(t('eds_warranty_cards.supplier')) ?>: <?= eds_warranty_cards_escape($internal['supplier']) ?></p>
                        <p class="text-sm text-gray-700"><?= eds_warranty_cards_escape(t('eds_warranty_cards.supplier_card')) ?>: <?= eds_warranty_cards_escape($internal['supplier_card']) ?></p>
                    </div>
                <?php endif; endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if (!$card && $canIssue && $validationError === ''): ?>
        <div class="bg-red-50 border border-red-200 rounded-lg p-6">
            <h2 class="text-lg font-medium text-red-900"><?= eds_warranty_cards_escape(t('eds_warranty_cards.issue_warning_title')) ?></h2>
            <p class="mt-2 text-sm text-red-800"><?= eds_warranty_cards_escape(t('eds_warranty_cards.issue_warning')) ?></p>
            <form method="POST" action="<?= eds_warranty_cards_escape($taskUrl . '/eds-warranty-card') ?>" class="mt-4">
                <input type="hidden" name="csrf_token" value="<?= eds_warranty_cards_escape($csrf_token) ?>">
                <input type="hidden" name="revision" value="<?= eds_warranty_cards_escape($preview['revision']) ?>">
                <input type="hidden" name="preview_token" value="<?= eds_warranty_cards_escape($preview['token']) ?>">
                <label class="flex items-start"><input type="checkbox" name="confirm_issue" value="1" required class="h-4 w-4 mt-0.5 text-red-600 border-gray-300 rounded"><span class="ml-2 text-sm text-red-900"><?= eds_warranty_cards_escape(t('eds_warranty_cards.issue_confirmation')) ?></span></label>
                <button type="submit" class="mt-4 inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-red-600 hover:bg-red-700"><?= eds_warranty_cards_escape(t('eds_warranty_cards.issue_button')) ?></button>
            </form>
        </div>
    <?php elseif (!$card && !$canIssue): ?>
        <p class="text-sm text-gray-600"><?= eds_warranty_cards_escape(t('eds_warranty_cards.read_only')) ?></p>
    <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
include ROOT_PATH . '/views/layout.php';
