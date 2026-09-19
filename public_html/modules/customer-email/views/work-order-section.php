<?php
// Email card under the customer information on the work order view. The heading is the state
// itself, so the card is a single row like the warranty one. A read-only viewer sees the
// state without the button.
$canEdit = !empty($canEdit);
$csrf_token = $csrf_token ?? '';
$workOrderId = (int) ($workOrder['id'] ?? 0);
$hasAddress = !empty($hasAddress);
$disabled = !empty($disabled);

if (!$hasAddress) {
    $statusText = t('customer_email.status_unavailable');
} else {
    $statusText = $disabled ? t('customer_email.status_disabled') : t('customer_email.status_enabled');
}
?>

<div class="mt-6 bg-white shadow rounded-lg">
    <div class="px-6 py-4 flex items-center justify-between">
        <h2 class="text-lg font-medium text-gray-900"><?= htmlspecialchars($statusText) ?></h2>
        <?php if ($canEdit && $hasAddress): ?>
            <form method="POST" action="<?= BASE_URL ?>/work-orders/view/<?= $workOrderId ?>/customer-email">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <input type="hidden" name="customer_email_enabled" value="<?= $disabled ? '1' : '0' ?>">
                <button type="submit" class="inline-flex items-center px-3 py-1.5 border text-sm font-medium rounded-md border-gray-300 text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                    <?= $disabled ? t('customer_email.enable') : t('customer_email.disable') ?>
                </button>
            </form>
        <?php endif; ?>
    </div>
</div>
