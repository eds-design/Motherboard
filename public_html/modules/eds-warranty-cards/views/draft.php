<?php
$title = t('eds_warranty_cards.card') . ' - ' . ($companyName ?? APP_NAME);
$draftUrl = BASE_URL . '/work-orders/view/' . (int) $workOrder['id'] . '/eds-warranty-card/draft';
$draftFieldMax = [
    'name' => EdsWarrantyCardDraftLimits::MANUAL_PART_NAME_LENGTH,
    'serial_number' => EdsWarrantyCardDraftLimits::SERIAL_NUMBER_LENGTH,
    'months' => EdsWarrantyCardDraftLimits::MONTHS_DIGITS,
    'supplier' => EdsWarrantyCardDraftLimits::SUPPLIER_LENGTH,
    'supplier_card' => EdsWarrantyCardDraftLimits::SUPPLIER_CARD_LENGTH,
];
ob_start();
?>
<style>
.eds-work-item-fields { display: grid; gap: 1rem; }
.eds-draft-actions { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 1rem; }
.eds-draft-save-actions { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: .75rem; margin-left: auto; }
.eds-draft-delete-form { margin: 0; }
@media (min-width: 640px) {
    .eds-work-item-fields { grid-template-columns: minmax(0, 3fr) minmax(0, 1fr) auto; align-items: end; }
}
@media (max-width: 639px) {
    .eds-draft-actions, .eds-draft-save-actions { flex-direction: column; align-items: stretch; width: 100%; }
    .eds-draft-save-actions { margin-left: 0; }
    .eds-draft-delete-form { width: 100%; }
    .eds-draft-actions button { width: 100%; justify-content: center; }
}
</style>
<div class="py-8">
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900"><?= eds_warranty_cards_escape(t('eds_warranty_cards.card')) ?> — <?= eds_warranty_cards_escape(t('eds_warranty_cards.draft')) ?></h1>
        <p class="mt-1 text-sm text-gray-600"><?= eds_warranty_cards_escape(t('eds_warranty_cards.draft_help')) ?></p>
        <p class="mt-2 text-sm text-gray-700"><?= eds_warranty_cards_escape(t('eds_warranty_cards.task')) ?>: <?= eds_warranty_cards_escape((string) $workOrder['work_order_number']) ?> · <?= eds_warranty_cards_escape((string) ($workOrder['customer_name'] ?? '')) ?> · <?= eds_warranty_cards_escape((string) $workOrder['computer']) ?> <?= eds_warranty_cards_escape((string) ($workOrder['model'] ?? '')) ?></p>
        <a href="<?= eds_warranty_cards_escape(BASE_URL . '/work-orders/view/' . (int) $workOrder['id']) ?>" class="mt-4 inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50"><?= eds_warranty_cards_escape(t('eds_warranty_cards.back_to_task')) ?></a>
    </div>
    <?php if ($error !== ''): ?>
        <div class="mb-6 bg-red-50 border border-red-200 rounded-md p-4" role="alert">
            <p class="text-sm text-red-600"><?= eds_warranty_cards_escape($error) ?></p>
            <?php if ($conflict): ?>
                <p class="mt-2 text-sm text-gray-700"><?= eds_warranty_cards_escape(t('eds_warranty_cards.conflict_help')) ?></p>
                <a href="<?= eds_warranty_cards_escape($draftUrl) ?>" target="_blank" rel="noopener" class="mt-2 inline-flex text-sm text-primary-600"><?= eds_warranty_cards_escape(t('eds_warranty_cards.open_latest')) ?></a>
            <?php elseif ($inventoryChanged): ?>
                <p class="mt-2 text-sm text-gray-700"><?= eds_warranty_cards_escape(t('eds_warranty_cards.inventory_review')) ?></p>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <?php if ($message !== ''): ?>
        <div class="mb-6 bg-green-50 border border-green-200 rounded-md p-4"><p class="text-sm text-green-600"><?= eds_warranty_cards_escape($message) ?></p></div>
    <?php endif; ?>
    <?php if (!$canEdit): ?>
        <p class="mb-6 text-sm text-gray-600"><?= eds_warranty_cards_escape(t('eds_warranty_cards.read_only')) ?></p>
    <?php endif; ?>
    <form method="POST" action="<?= eds_warranty_cards_escape($draftUrl) ?>" id="eds-warranty-card-draft-form">
        <input type="hidden" name="csrf_token" value="<?= eds_warranty_cards_escape($csrf_token) ?>">
        <input type="hidden" name="revision" value="<?= eds_warranty_cards_escape($form['revision']) ?>">
        <input type="hidden" name="inventory_token" value="<?= eds_warranty_cards_escape($form['inventory_token']) ?>">
        <fieldset <?= !$canEdit ? 'disabled' : '' ?>>
            <div class="bg-white shadow rounded-lg mb-6">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h2 class="text-lg font-medium text-gray-900"><?= eds_warranty_cards_escape(t('eds_warranty_cards.parts')) ?></h2>
                    <p class="mt-1 text-sm text-gray-600"><?= eds_warranty_cards_escape(t('eds_warranty_cards.parts_help')) ?></p>
                </div>
                <div class="px-6 py-4 space-y-6">
                    <?php $hasAvailableInventory = count(array_filter($rows, static fn(array $row): bool => $row['available'])) > 0; ?>
                    <?php if (!$hasAvailableInventory): ?><p class="text-sm text-gray-600"><?= eds_warranty_cards_escape(t('eds_warranty_cards.no_parts')) ?></p><?php endif; ?>
                    <?php foreach ($rows as $key => $row): $fields = $row['fields']; $unitId = 'eds-unit-' . hash('sha256', (string) $key); ?>
                        <div class="border border-gray-300 rounded-lg p-4" data-eds-unit>
                            <label class="flex items-start" for="<?= $unitId ?>">
                                <input type="checkbox" id="<?= $unitId ?>" name="units[<?= eds_warranty_cards_escape((string) $key) ?>][selected]" value="1" <?= $fields['selected'] ? 'checked' : '' ?> class="h-4 w-4 mt-0.5 text-primary-600 border-gray-300 rounded" data-eds-select>
                                <span class="ml-2 text-sm font-medium text-gray-900"><?= eds_warranty_cards_escape($row['name']) ?><?php if ($row['quantity'] > 1): ?> — <?= eds_warranty_cards_escape(t('eds_warranty_cards.unit_of', ['unit' => $row['unit'], 'quantity' => $row['quantity']])) ?><?php endif; ?></span>
                            </label>
                            <?php if (!$row['available']): ?><p class="mt-2 text-sm text-amber-700"><?= eds_warranty_cards_escape(t('eds_warranty_cards.saved_inventory_unit')) ?></p><?php endif; ?>
                            <div data-eds-fields <?= !$fields['selected'] ? 'hidden' : '' ?>>
                                <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <?php foreach (['serial_number', 'months', 'supplier', 'supplier_card'] as $field): ?>
                                        <div>
                                            <label for="<?= $unitId . '-' . $field ?>" class="block text-sm font-medium text-gray-700"><?= eds_warranty_cards_escape(t('eds_warranty_cards.' . $field)) ?></label>
                                            <input type="text" <?= $field === 'months' ? 'inputmode="numeric"' : '' ?> maxlength="<?= $draftFieldMax[$field] ?>" id="<?= $unitId . '-' . $field ?>" name="units[<?= eds_warranty_cards_escape((string) $key) ?>][<?= $field ?>]" value="<?= eds_warranty_cards_escape($fields[$field]) ?>" class="mt-1 block w-full px-4 py-3 border-2 border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm bg-white">
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <p class="mt-2 text-sm text-gray-500"><?= eds_warranty_cards_escape(t('eds_warranty_cards.internal_help')) ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="bg-white shadow rounded-lg mb-6">
                <div class="px-6 py-4 border-b border-gray-200 sm:flex sm:items-start sm:justify-between">
                    <div>
                        <h2 class="text-lg font-medium text-gray-900"><?= eds_warranty_cards_escape(t('eds_warranty_cards.manual_parts')) ?></h2>
                        <p class="mt-1 text-sm text-gray-600"><?= eds_warranty_cards_escape(t('eds_warranty_cards.manual_parts_help')) ?></p>
                    </div>
                    <?php if ($canEdit): ?><button type="button" id="eds-add-manual-part" data-max-rows="<?= EdsWarrantyCardDraftLimits::MANUAL_ROWS ?>" data-limit-message="<?= eds_warranty_cards_escape(t('eds_warranty_cards.too_many_parts')) ?>" class="mt-3 sm:mt-0 inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50"><?= eds_warranty_cards_escape(t('eds_warranty_cards.add_manual_part')) ?></button><?php endif; ?>
                </div>
                <div id="eds-manual-parts" class="px-6 py-4 space-y-6">
                    <p id="eds-no-manual-parts" class="text-sm text-gray-600" <?= $manualParts ? 'hidden' : '' ?>><?= eds_warranty_cards_escape(t('eds_warranty_cards.no_manual_parts')) ?></p>
                    <?php foreach ($manualParts as $key => $part): $partId = 'eds-manual-' . hash('sha256', (string) $key); ?>
                        <div class="border border-gray-300 rounded-lg p-4" data-eds-manual-part>
                            <div class="flex items-start justify-between gap-4">
                                <h3 class="text-sm font-medium text-gray-900"><?= eds_warranty_cards_escape(t('eds_warranty_cards.manual_part')) ?></h3>
                                <?php if ($canEdit): ?><button type="button" class="text-sm text-red-600 hover:text-red-800" data-eds-remove-manual><?= eds_warranty_cards_escape(t('eds_warranty_cards.remove_manual_part')) ?></button><?php endif; ?>
                            </div>
                            <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <?php foreach (['name', 'serial_number', 'months', 'supplier', 'supplier_card'] as $field): ?>
                                    <div<?= $field === 'name' ? ' class="sm:col-span-2"' : '' ?>>
                                        <label for="<?= $partId . '-' . $field ?>" class="block text-sm font-medium text-gray-700"><?= eds_warranty_cards_escape(t('eds_warranty_cards.' . ($field === 'name' ? 'part_name' : $field))) ?></label>
                                        <input type="text" <?= $field === 'months' ? 'inputmode="numeric"' : '' ?> maxlength="<?= $draftFieldMax[$field] ?>" id="<?= $partId . '-' . $field ?>" name="manual_parts[<?= eds_warranty_cards_escape((string) $key) ?>][<?= $field ?>]" value="<?= eds_warranty_cards_escape((string) ($part[$field] ?? '')) ?>" class="mt-1 block w-full px-4 py-3 border-2 border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm bg-white">
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <p class="mt-2 text-sm text-gray-500"><?= eds_warranty_cards_escape(t('eds_warranty_cards.internal_help')) ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="bg-white shadow rounded-lg mb-6">
                <div class="px-6 py-4 border-b border-gray-200 sm:flex sm:items-start sm:justify-between">
                    <div>
                        <h2 class="text-lg font-medium text-gray-900"><?= eds_warranty_cards_escape(t('eds_warranty_cards.work_enabled')) ?></h2>
                        <p class="mt-1 text-sm text-gray-600"><?= eds_warranty_cards_escape(t('eds_warranty_cards.work_items_help')) ?></p>
                    </div>
                    <?php if ($canEdit): ?><button type="button" id="eds-add-work-item" data-max-rows="<?= EdsWarrantyCardDraftLimits::WORK_ITEMS ?>" data-limit-message="<?= eds_warranty_cards_escape(t('eds_warranty_cards.too_many_work_items')) ?>" class="mt-3 sm:mt-0 inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50"><?= eds_warranty_cards_escape(t('eds_warranty_cards.add_work_item')) ?></button><?php endif; ?>
                </div>
                <div id="eds-work-items" class="px-6 py-4 space-y-4">
                    <p id="eds-no-work-items" class="text-sm text-gray-600" <?= $workItems ? 'hidden' : '' ?>><?= eds_warranty_cards_escape(t('eds_warranty_cards.no_work_items')) ?></p>
                    <?php foreach ($workItems as $key => $item): $itemId = 'eds-work-' . hash('sha256', (string) $key); ?>
                        <div class="border border-gray-300 rounded-lg p-4" data-eds-work-item>
                            <div class="eds-work-item-fields">
                                <div>
                                    <label for="<?= $itemId ?>-description" class="block text-sm font-medium text-gray-700"><?= eds_warranty_cards_escape(t('eds_warranty_cards.work_description')) ?></label>
                                    <input type="text" maxlength="<?= EdsWarrantyCardDraftLimits::WORK_DESCRIPTION_LENGTH ?>" id="<?= $itemId ?>-description" name="work_items[<?= eds_warranty_cards_escape((string) $key) ?>][description]" value="<?= eds_warranty_cards_escape((string) ($item['description'] ?? '')) ?>" class="mt-1 block w-full px-4 py-3 border-2 border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm bg-white">
                                </div>
                                <div>
                                    <label for="<?= $itemId ?>-months" class="block text-sm font-medium text-gray-700"><?= eds_warranty_cards_escape(t('eds_warranty_cards.months')) ?></label>
                                    <input type="text" inputmode="numeric" maxlength="<?= EdsWarrantyCardDraftLimits::MONTHS_DIGITS ?>" id="<?= $itemId ?>-months" name="work_items[<?= eds_warranty_cards_escape((string) $key) ?>][months]" value="<?= eds_warranty_cards_escape((string) ($item['months'] ?? '')) ?>" class="mt-1 block w-full px-4 py-3 border-2 border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm bg-white">
                                </div>
                                <?php if ($canEdit): ?><button type="button" class="inline-flex items-center justify-center px-3 py-3 text-sm text-red-600 hover:text-red-800" data-eds-remove-work><?= eds_warranty_cards_escape(t('eds_warranty_cards.remove_work_item')) ?></button><?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </fieldset>
        <input type="hidden" name="form_complete" value="1">
    </form>
    <?php if ($canEdit): ?>
        <div class="eds-draft-actions">
            <?php if (!empty($hasSavedDraft)): ?>
                <form method="POST" action="<?= eds_warranty_cards_escape($draftUrl . '/delete') ?>" id="eds-delete-draft-form" class="eds-draft-delete-form" data-confirm="<?= eds_warranty_cards_escape(t('eds_warranty_cards.delete_draft_confirmation')) ?>">
                    <input type="hidden" name="csrf_token" value="<?= eds_warranty_cards_escape($csrf_token) ?>">
                    <input type="hidden" name="revision" value="<?= eds_warranty_cards_escape($form['revision']) ?>">
                    <input type="hidden" name="confirm_delete" value="0" data-eds-delete-confirmed>
                    <noscript>
                        <label class="mb-3 flex items-start text-sm text-red-700">
                            <input type="checkbox" name="confirm_delete" value="1" required class="h-4 w-4 mt-0.5 text-red-600 border-gray-300 rounded">
                            <span class="ml-2"><?= eds_warranty_cards_escape(t('eds_warranty_cards.delete_draft_confirmation')) ?></span>
                        </label>
                    </noscript>
                    <button type="submit" class="inline-flex items-center px-4 py-2 border border-red-300 text-sm font-medium rounded-md text-red-700 bg-white hover:bg-red-50"><?= eds_warranty_cards_escape(t('eds_warranty_cards.delete_draft')) ?></button>
                </form>
            <?php endif; ?>
            <?php if (!$conflict): ?>
                <div class="eds-draft-save-actions">
                    <button type="submit" form="eds-warranty-card-draft-form" name="draft_action" value="save" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50"><?= eds_warranty_cards_escape(t('eds_warranty_cards.save_draft')) ?></button>
                    <button type="submit" form="eds-warranty-card-draft-form" name="draft_action" value="save_and_review" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-primary-600 hover:bg-primary-700"><?= eds_warranty_cards_escape(t('eds_warranty_cards.save_and_review')) ?></button>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
<?php if ($canEdit): ?><template id="eds-manual-part-template">
    <div class="border border-gray-300 rounded-lg p-4" data-eds-manual-part>
        <div class="flex items-start justify-between gap-4">
            <h3 class="text-sm font-medium text-gray-900"><?= eds_warranty_cards_escape(t('eds_warranty_cards.manual_part')) ?></h3>
            <button type="button" class="text-sm text-red-600 hover:text-red-800" data-eds-remove-manual><?= eds_warranty_cards_escape(t('eds_warranty_cards.remove_manual_part')) ?></button>
        </div>
        <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-4">
            <?php foreach (['name', 'serial_number', 'months', 'supplier', 'supplier_card'] as $field): ?>
                <div<?= $field === 'name' ? ' class="sm:col-span-2"' : '' ?>>
                    <label for="eds-manual-__KEY__-<?= $field ?>" class="block text-sm font-medium text-gray-700"><?= eds_warranty_cards_escape(t('eds_warranty_cards.' . ($field === 'name' ? 'part_name' : $field))) ?></label>
                    <input type="text" <?= $field === 'months' ? 'inputmode="numeric"' : '' ?> maxlength="<?= $draftFieldMax[$field] ?>" id="eds-manual-__KEY__-<?= $field ?>" name="manual_parts[__KEY__][<?= $field ?>]" value="" class="mt-1 block w-full px-4 py-3 border-2 border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm bg-white">
                </div>
            <?php endforeach; ?>
        </div>
        <p class="mt-2 text-sm text-gray-500"><?= eds_warranty_cards_escape(t('eds_warranty_cards.internal_help')) ?></p>
    </div>
</template>
<template id="eds-work-item-template">
    <div class="border border-gray-300 rounded-lg p-4" data-eds-work-item>
        <div class="eds-work-item-fields">
            <div>
                <label for="eds-work-__KEY__-description" class="block text-sm font-medium text-gray-700"><?= eds_warranty_cards_escape(t('eds_warranty_cards.work_description')) ?></label>
                <input type="text" maxlength="<?= EdsWarrantyCardDraftLimits::WORK_DESCRIPTION_LENGTH ?>" id="eds-work-__KEY__-description" name="work_items[__KEY__][description]" value="" class="mt-1 block w-full px-4 py-3 border-2 border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm bg-white">
            </div>
            <div>
                <label for="eds-work-__KEY__-months" class="block text-sm font-medium text-gray-700"><?= eds_warranty_cards_escape(t('eds_warranty_cards.months')) ?></label>
                <input type="text" inputmode="numeric" maxlength="<?= EdsWarrantyCardDraftLimits::MONTHS_DIGITS ?>" id="eds-work-__KEY__-months" name="work_items[__KEY__][months]" value="" class="mt-1 block w-full px-4 py-3 border-2 border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm bg-white">
            </div>
            <button type="button" class="inline-flex items-center justify-center px-3 py-3 text-sm text-red-600 hover:text-red-800" data-eds-remove-work><?= eds_warranty_cards_escape(t('eds_warranty_cards.remove_work_item')) ?></button>
        </div>
    </div>
</template><?php endif; ?>
<script>
document.querySelectorAll('[data-eds-unit]').forEach(function (unit) {
    var checkbox = unit.querySelector('[data-eds-select]');
    var fields = unit.querySelector('[data-eds-fields]');
    checkbox.addEventListener('change', function () { fields.hidden = !checkbox.checked; });
});
function edsBindManualRemove(row) {
    var button = row.querySelector('[data-eds-remove-manual]');
    if (!button) return;
    button.addEventListener('click', function () {
        row.remove();
        document.getElementById('eds-no-manual-parts').hidden = document.querySelectorAll('[data-eds-manual-part]').length > 0;
    });
}
document.querySelectorAll('[data-eds-manual-part]').forEach(edsBindManualRemove);
var addManualPart = document.getElementById('eds-add-manual-part');
if (addManualPart) addManualPart.addEventListener('click', function () {
    if (document.querySelectorAll('[data-eds-manual-part]').length >= Number(addManualPart.dataset.maxRows)) {
        window.alert(addManualPart.dataset.limitMessage);
        return;
    }
    var bytes = new Uint8Array(16);
    window.crypto.getRandomValues(bytes);
    var key = 'm_' + Array.from(bytes, function (byte) { return byte.toString(16).padStart(2, '0'); }).join('');
    var template = document.getElementById('eds-manual-part-template').innerHTML.replaceAll('__KEY__', key);
    var container = document.getElementById('eds-manual-parts');
    container.insertAdjacentHTML('beforeend', template);
    var row = container.lastElementChild;
    edsBindManualRemove(row);
    document.getElementById('eds-no-manual-parts').hidden = true;
    row.querySelector('input').focus();
});
function edsBindWorkRemove(row) {
    var button = row.querySelector('[data-eds-remove-work]');
    if (!button) return;
    button.addEventListener('click', function () {
        row.remove();
        document.getElementById('eds-no-work-items').hidden = document.querySelectorAll('[data-eds-work-item]').length > 0;
    });
}
document.querySelectorAll('[data-eds-work-item]').forEach(edsBindWorkRemove);
var addWorkItem = document.getElementById('eds-add-work-item');
if (addWorkItem) addWorkItem.addEventListener('click', function () {
    if (document.querySelectorAll('[data-eds-work-item]').length >= Number(addWorkItem.dataset.maxRows)) {
        window.alert(addWorkItem.dataset.limitMessage);
        return;
    }
    var bytes = new Uint8Array(16);
    window.crypto.getRandomValues(bytes);
    var key = 'w_' + Array.from(bytes, function (byte) { return byte.toString(16).padStart(2, '0'); }).join('');
    var template = document.getElementById('eds-work-item-template').innerHTML.replaceAll('__KEY__', key);
    var container = document.getElementById('eds-work-items');
    container.insertAdjacentHTML('beforeend', template);
    var row = container.lastElementChild;
    edsBindWorkRemove(row);
    document.getElementById('eds-no-work-items').hidden = true;
    row.querySelector('input').focus();
});
var deleteDraftForm = document.getElementById('eds-delete-draft-form');
if (deleteDraftForm) deleteDraftForm.addEventListener('submit', function (event) {
    if (!window.confirm(deleteDraftForm.dataset.confirm)) {
        event.preventDefault();
        return;
    }
    deleteDraftForm.querySelector('[data-eds-delete-confirmed]').value = '1';
});
</script>
<?php
$content = ob_get_clean();
include ROOT_PATH . '/views/layout.php';
