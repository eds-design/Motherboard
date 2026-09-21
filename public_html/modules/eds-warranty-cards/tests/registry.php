<?php
// Included by tests/run.php after the existing issuance and print checks.
$pdo->beginTransaction();
try {
    $pdo->exec('DELETE FROM eds_warranty_cards_issued');
    $registry = new EdsWarrantyCardRegistry($pdo);
    check($registry->count() === 0 && $registry->page('', 50, 0) === [], 'empty issued-card registry');

    $insertWorkOrder = $pdo->prepare('INSERT INTO work_orders (id,work_order_number,customer_id,computer,description,created_at) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE work_order_number=VALUES(work_order_number)');
    $insertCard = $pdo->prepare('INSERT INTO eds_warranty_cards_issued (work_order_id,card_number,number_key,issued_at,issued_by,public_content,internal_content) VALUES (?,?,?,?,NULL,?,?)');
    $hugeValue = str_repeat('9', 1000);
    $hugeFormatted = '000' . $hugeValue;

    for ($index = 1; $index <= 51; $index++) {
        $workOrderId = 7000 + $index;
        $number = match ($index) {
            1 => '0006',
            2 => $hugeFormatted,
            default => (string) (500000 + $index),
        };
        $issuedAt = match ($index) {
            50, 51 => '2026-09-20 12:00:00',
            default => date('Y-m-d H:i:s', strtotime('2026-09-20 11:00:00') - ($index * 60)),
        };
        $customer = $index === 49
            ? []
            : [
                'name' => $index === 3 ? '<script>Registry Customer 03</script>' : sprintf('Registry Customer %02d', $index),
                'phone' => sprintf('SECRET-PHONE-%04d', $index),
                'company' => 'HIDDEN COMPANY ' . $index,
            ];
        $snapshot = [
            'customer' => $customer,
            'parts' => [],
            'work_warranties' => [],
            'terms_html' => '',
        ];
        $insertWorkOrder->execute([$workOrderId, 'REGISTRY-WO-' . $index, null, '', '', $issuedAt]);
        $insertCard->execute([
            $workOrderId,
            $number,
            EdsWarrantyCardNumber::identityKey($number),
            $issuedAt,
            json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            json_encode(['parts' => [['supplier' => 'NEVER RETURN THIS']]], JSON_THROW_ON_ERROR),
        ]);

        if ($index === 50) {
            check($registry->count() === 50 && count($registry->page('', 50, 0)) === 50
                && $registry->page('', 50, 50) === [], 'exactly fifty issued cards fit on one registry page');
        }
    }

    $secondPage = $registry->page('', 50, 50);
    check($registry->count() === 51 && count($registry->page('', 50, 0)) === 50
        && count($secondPage) === 1 && $secondPage[0]['work_order_id'] === 7049, 'fifty-one issued cards use two database pages with the correct remaining row');
    $firstPage = $registry->page('', 50, 0);
    check($firstPage[0]['work_order_id'] === 7051 && $firstPage[1]['work_order_id'] === 7050, 'registry sorts newest first with stable work-order tie-breaker');
    check(array_keys($firstPage[0]) === ['work_order_id', 'card_number', 'customer_name', 'issued_at']
        && !isset($firstPage[0]['phone'], $firstPage[0]['company'], $firstPage[0]['internal_content']), 'registry projection excludes phone, company and internal snapshot');

    $nameMatches = $registry->page('registry customer 10', 50, 0);
    check(count($nameMatches) === 1 && $nameMatches[0]['customer_name'] === 'Registry Customer 10', 'frozen customer name search is partial and case-insensitive');
    $phoneMatches = $registry->page('PHONE-0011', 50, 0);
    check(count($phoneMatches) === 1 && $phoneMatches[0]['work_order_id'] === 7011
        && !array_key_exists('phone', $phoneMatches[0]), 'frozen phone is searchable but never returned to the view');

    $numericMatches = $registry->page('6', 50, 0);
    check($numericMatches !== [] && $numericMatches[0]['card_number'] === '0006', 'numeric identity finds padded card number and ranks exact match first');
    $hugeMatches = $registry->page($hugeValue, 50, 0);
    check(count($hugeMatches) === 1 && $hugeMatches[0]['card_number'] === $hugeFormatted, 'arbitrarily large card number search preserves exact formatted result');
    $missingCustomer = $registry->page('500049', 50, 0);
    check(count($missingCustomer) === 1 && $missingCustomer[0]['customer_name'] === '', 'legacy snapshot with missing customer fields is safe');
    check($registry->count("%_' OR 1=1 --") === 0, 'SQL special characters remain literal search text');

    check(EdsWarrantyCardRegistry::normalizeSearch(['bad']) === ''
        && EdsWarrantyCardRegistry::normalizeSearch("\xC3\x28") === '', 'invalid search types and UTF-8 are rejected safely');
    $overlong = str_repeat('ж', EdsWarrantyCardRegistry::SEARCH_MAX_CHARACTERS + 10);
    check(mb_strlen(EdsWarrantyCardRegistry::normalizeSearch($overlong), 'UTF-8') === EdsWarrantyCardRegistry::SEARCH_MAX_CHARACTERS, 'search length is bounded by UTF-8 characters');
} finally {
    $pdo->rollBack();
}
