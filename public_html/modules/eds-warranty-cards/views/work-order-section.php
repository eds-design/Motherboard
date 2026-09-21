<?php
$draftUrl = BASE_URL . '/work-orders/view/' . (int) $workOrder['id'] . '/eds-warranty-card/draft';
$cardUrl = BASE_URL . '/work-orders/view/' . (int) $workOrder['id'] . '/eds-warranty-card';
?>
<div class="mt-6 bg-white shadow rounded-lg">
    <div class="px-6 py-4 border-b border-gray-200">
        <h2 class="text-lg font-medium text-gray-900"><?= eds_warranty_cards_escape(t('eds_warranty_cards.card')) ?></h2>
    </div>
    <div class="px-6 py-4">
        <?php if ($card): ?>
            <p class="text-sm text-gray-600"><?= eds_warranty_cards_escape(t('eds_warranty_cards.issued_status', ['number' => $card['card_number'], 'date' => ldate($card['issued_at'], 'd.m.Y')])) ?></p>
            <div class="mt-4 flex flex-wrap gap-2">
                <a href="<?= eds_warranty_cards_escape($cardUrl) ?>" class="inline-flex max-w-full items-center justify-center px-4 py-2 border border-transparent text-sm font-medium text-center rounded-md text-white bg-primary-600 hover:bg-primary-700"><?= eds_warranty_cards_escape(t('eds_warranty_cards.view_issued')) ?></a>
            </div>
        <?php else: ?>
            <p class="text-sm text-gray-600"><?= eds_warranty_cards_escape(t($draft ? 'eds_warranty_cards.draft_without_number' : 'eds_warranty_cards.no_draft')) ?></p>
            <?php if ($canEditDraft || $draft): ?>
            <div class="mt-4 flex flex-wrap gap-2">
                <a href="<?= eds_warranty_cards_escape($draftUrl) ?>" class="inline-flex max-w-full items-center justify-center px-4 py-2 border border-transparent text-sm font-medium text-center rounded-md text-white bg-primary-600 hover:bg-primary-700"><?= eds_warranty_cards_escape(t($canEditDraft ? ($draft ? 'eds_warranty_cards.edit_draft' : 'eds_warranty_cards.prepare') : 'eds_warranty_cards.view_draft')) ?></a>
                <?php if ($draft): ?>
                <a href="<?= eds_warranty_cards_escape($cardUrl) ?>" class="inline-flex max-w-full items-center justify-center px-4 py-2 border border-gray-300 text-sm font-medium text-center rounded-md text-gray-700 bg-white hover:bg-gray-50"><?= eds_warranty_cards_escape(t('eds_warranty_cards.review')) ?></a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
