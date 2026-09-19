<?php
$assigned = $assigned ?? [];
$totals = motherboard_inventory_work_order_totals($assigned);
$sectionClass = $context['section_class'] ?? 'border border-gray-300 rounded-lg p-3 mb-4';
?>
<div class="<?= $sectionClass ?> print-avoid-break">
    <h3 class="text-sm font-semibold text-gray-900 mb-2"><?= t('inventory.wo_section') ?></h3>
    <div class="grid grid-cols-2 gap-4">
        <div class="text-xs text-gray-700 space-y-1">
            <?php foreach ($assigned as $line): ?>
                <div>
                    <span class="font-medium text-gray-900">
                        <?= (int) $line['quantity'] ?>x
                        <?php if (!empty($line['item_number'])): ?>
                            <?= htmlspecialchars($line['item_number']) ?>:
                        <?php endif; ?>
                        <?= htmlspecialchars($line['product_name']) ?>,
                        <?= htmlspecialchars(motherboard_inventory_format_price($line['unit_price'])) ?><?php if (!empty($line['taxable'])): ?><span title="<?= htmlspecialchars(t('inventory.taxable')) ?>"><?= t('inventory.taxable_mark') ?></span><?php endif; ?>
                        <?= t('inventory.price_each') ?>
                    </span>
                    <?php if (!empty($line['is_custom']) && !empty($line['description'])): ?>
                        <div class="text-gray-500"><?= nl2br(htmlspecialchars($line['description'])) ?></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="text-xs text-gray-700 text-right space-y-0.5">
            <div><?= t('inventory.taxable_total') ?>: <?= htmlspecialchars(motherboard_inventory_format_price($totals['taxable'])) ?></div>
            <div><?= t('inventory.nontaxable_total') ?>: <?= htmlspecialchars(motherboard_inventory_format_price($totals['nontaxable'])) ?></div>
            <div><?= t('inventory.tax_amount', ['rate' => motherboard_inventory_format_tax_rate($totals['tax_rate'])]) ?>: <?= htmlspecialchars(motherboard_inventory_format_price($totals['tax'])) ?></div>
            <div class="font-semibold"><?= t('inventory.grand_total') ?>: <?= htmlspecialchars(motherboard_inventory_format_price($totals['grand_total'])) ?></div>
        </div>
    </div>
</div>
