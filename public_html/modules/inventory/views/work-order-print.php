<?php
$assigned = $assigned ?? [];
$totals = motherboard_inventory_work_order_totals($assigned);
$sectionClass = $context['section_class'] ?? 'border border-gray-300 rounded-lg p-3 mb-4';
$hideHeading = motherboard_inventory_hide_printout_heading();
?>
<div class="<?= $sectionClass ?> print-avoid-break">
    <?php if (!$hideHeading): ?>
        <h3 class="text-sm font-semibold text-gray-900 mb-2"><?= t('inventory.wo_section') ?></h3>
    <?php endif; ?>
    <div class="grid grid-cols-2 gap-4">
        <table class="w-full text-xs text-gray-700">
            <thead>
                <tr>
                    <th class="text-left py-1 font-medium"><?= t('inventory.product_name') ?></th>
                    <th class="text-left py-1 font-medium"><?= t('inventory.price') ?></th>
                    <th class="text-left py-1 font-medium"><?= t('inventory.quantity') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($assigned as $line): ?>
                    <tr>
                        <td class="py-1">
                            <span class="font-medium text-gray-900">
                                <?php if (!empty($line['item_number'])): ?>
                                    <?= htmlspecialchars($line['item_number']) ?>:
                                <?php endif; ?>
                                <?= htmlspecialchars($line['product_name']) ?>
                            </span>
                            <?php if (!empty($line['is_custom']) && !empty($line['description'])): ?>
                                <div class="text-gray-500"><?= nl2br(htmlspecialchars($line['description'])) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="py-1 whitespace-nowrap">
                            <?= htmlspecialchars(motherboard_inventory_format_money($line['unit_price'])) ?><?php if (!empty($line['taxable'])): ?><span title="<?= htmlspecialchars(t('inventory.taxable')) ?>"><?= t('inventory.taxable_mark') ?></span><?php endif; ?>
                        </td>
                        <td class="py-1"><?= (int) $line['quantity'] ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <div class="text-xs text-gray-700 text-right space-y-0.5">
            <div><?= t('inventory.taxable_total') ?>: <?= htmlspecialchars(motherboard_inventory_format_money($totals['taxable'])) ?></div>
            <div><?= t('inventory.nontaxable_total') ?>: <?= htmlspecialchars(motherboard_inventory_format_money($totals['nontaxable'])) ?></div>
            <div><?= t('inventory.tax_amount', ['rate' => motherboard_inventory_format_tax_rate($totals['tax_rate'])]) ?>: <?= htmlspecialchars(motherboard_inventory_format_money($totals['tax'])) ?></div>
            <div class="font-semibold"><?= t('inventory.grand_total') ?>: <?= htmlspecialchars(motherboard_inventory_format_money($totals['grand_total'])) ?></div>
        </div>
    </div>
</div>
