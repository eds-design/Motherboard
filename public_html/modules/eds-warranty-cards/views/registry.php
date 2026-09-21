<?php
$title = t('eds_warranty_cards.registry_title') . ' - ' . ($companyName ?? APP_NAME);
$registryUrl = BASE_URL . '/eds-warranty-cards';
$assetUrl = BASE_URL . '/eds-warranty-cards/assets/warranty-card-registry.css?v=0.11.2';
$pageUrl = static function (int $page) use ($registryUrl, $search): string {
    $query = [];
    if ($search !== '') {
        $query['q'] = $search;
    }
    if ($page > 1) {
        $query['page'] = $page;
    }
    return $registryUrl . ($query ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
};
$paginationPages = [];
if ($totalPages > 1) {
    foreach ([1, $currentPage - 2, $currentPage - 1, $currentPage, $currentPage + 1, $currentPage + 2, $totalPages] as $page) {
        if ($page >= 1 && $page <= $totalPages) {
            $paginationPages[$page] = $page;
        }
    }
    ksort($paginationPages);
}
ob_start();
?>
<link rel="stylesheet" href="<?= eds_warranty_cards_escape($assetUrl) ?>">
<div class="eds-warranty-registry py-6">
    <div class="eds-warranty-registry__heading">
        <h1><?= eds_warranty_cards_escape(t('eds_warranty_cards.registry_title')) ?></h1>
        <form method="GET" action="<?= eds_warranty_cards_escape($registryUrl) ?>" class="eds-warranty-registry__search" role="search">
            <label for="eds-warranty-registry-query" class="sr-only"><?= eds_warranty_cards_escape(t('eds_warranty_cards.registry_search_label')) ?></label>
            <input id="eds-warranty-registry-query" type="search" name="q" maxlength="<?= EdsWarrantyCardRegistry::SEARCH_MAX_CHARACTERS ?>" value="<?= eds_warranty_cards_escape($search) ?>" placeholder="<?= eds_warranty_cards_escape(t('eds_warranty_cards.registry_search_placeholder')) ?>">
            <button type="submit"><?= eds_warranty_cards_escape(t('eds_warranty_cards.registry_search')) ?></button>
            <?php if ($search !== ''): ?>
                <a href="<?= eds_warranty_cards_escape($registryUrl) ?>"><?= eds_warranty_cards_escape(t('eds_warranty_cards.registry_clear')) ?></a>
            <?php endif; ?>
        </form>
    </div>

    <?php if (!$cards): ?>
        <div class="eds-warranty-registry__empty">
            <?= eds_warranty_cards_escape(t($search === '' ? 'eds_warranty_cards.registry_empty' : 'eds_warranty_cards.registry_no_results')) ?>
        </div>
    <?php else: ?>
        <div class="eds-warranty-registry__table-wrap">
            <table class="eds-warranty-registry__table">
                <thead><tr>
                    <th scope="col"><?= eds_warranty_cards_escape(t('eds_warranty_cards.registry_column_number')) ?></th>
                    <th scope="col"><?= eds_warranty_cards_escape(t('eds_warranty_cards.registry_column_customer')) ?></th>
                    <th scope="col"><?= eds_warranty_cards_escape(t('eds_warranty_cards.registry_column_date')) ?></th>
                    <th scope="col"><?= eds_warranty_cards_escape(t('eds_warranty_cards.registry_column_actions')) ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($cards as $card):
                    $cardUrl = BASE_URL . '/work-orders/view/' . $card['work_order_id'] . '/eds-warranty-card';
                ?>
                    <tr>
                        <td data-label="<?= eds_warranty_cards_escape(t('eds_warranty_cards.registry_column_number')) ?>"><strong><?= eds_warranty_cards_escape($card['card_number']) ?></strong></td>
                        <td data-label="<?= eds_warranty_cards_escape(t('eds_warranty_cards.registry_column_customer')) ?>"><?= eds_warranty_cards_escape($card['customer_name'] !== '' ? $card['customer_name'] : '—') ?></td>
                        <td data-label="<?= eds_warranty_cards_escape(t('eds_warranty_cards.registry_column_date')) ?>"><?= eds_warranty_cards_escape($card['issued_at'] !== '' ? ldate($card['issued_at'], 'd.m.Y') : '—') ?></td>
                        <td data-label="<?= eds_warranty_cards_escape(t('eds_warranty_cards.registry_column_actions')) ?>" class="eds-warranty-registry__actions">
                            <span class="eds-warranty-registry__action-buttons">
                                <a href="<?= eds_warranty_cards_escape($cardUrl) ?>"><?= eds_warranty_cards_escape(t('eds_warranty_cards.registry_view')) ?></a>
                                <a href="<?= eds_warranty_cards_escape($cardUrl . '/print') ?>" target="_blank" rel="noopener noreferrer"><?= eds_warranty_cards_escape(t('eds_warranty_cards.registry_print')) ?></a>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if ($totalPages > 1): ?>
        <nav class="eds-warranty-registry__pagination" aria-label="<?= eds_warranty_cards_escape(t('eds_warranty_cards.registry_pagination')) ?>">
            <?php if ($currentPage > 1): ?><a href="<?= eds_warranty_cards_escape($pageUrl($currentPage - 1)) ?>" rel="prev"><?= eds_warranty_cards_escape(t('eds_warranty_cards.registry_previous')) ?></a><?php endif; ?>
            <?php $previousPage = null; foreach ($paginationPages as $page): ?>
                <?php if ($previousPage !== null && $page > $previousPage + 1): ?><span aria-hidden="true">…</span><?php endif; ?>
                <?php if ($page === $currentPage): ?>
                    <span class="is-current" aria-current="page"><?= $page ?></span>
                <?php else: ?>
                    <a href="<?= eds_warranty_cards_escape($pageUrl($page)) ?>" aria-label="<?= eds_warranty_cards_escape(t('eds_warranty_cards.registry_page', ['page' => (string) $page])) ?>"><?= $page ?></a>
                <?php endif; ?>
            <?php $previousPage = $page; endforeach; ?>
            <?php if ($currentPage < $totalPages): ?><a href="<?= eds_warranty_cards_escape($pageUrl($currentPage + 1)) ?>" rel="next"><?= eds_warranty_cards_escape(t('eds_warranty_cards.registry_next')) ?></a><?php endif; ?>
        </nav>
    <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
include ROOT_PATH . '/views/layout.php';
