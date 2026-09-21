<?php
if (PHP_SAPI !== 'cli' || !isset($socket, $checks)) { exit; }
$pdo = connection();
$pdo->exec('INSERT IGNORE INTO users (id) VALUES (1)');
$pdo->exec("INSERT IGNORE INTO customers VALUES (201,'Original Customer','Original Company','original@example.test','111')");
foreach ([201,202,203,204,205,206,207,208,209,210,211,212,213] as $id) {
    $stmt = $pdo->prepare("INSERT IGNORE INTO work_orders (id,work_order_number,customer_id,computer,model,serial_number,imei,remarks,accessories,description,resolution,status,priority,created_at)
        VALUES (?,?,201,'Original PC','Original Model','DEVICE-SN','IMEI-X','Device remarks','[\"Charger\"]','Original problem','Original resolution','Open','Standard',NOW())");
    $stmt->execute([$id, 'WO-' . $id]);
}
$pdo->exec('CREATE TABLE work_order_products (id INT PRIMARY KEY, work_order_id INT NOT NULL, product_name VARCHAR(255) NOT NULL, quantity INT NOT NULL) ENGINE=InnoDB');
$pdo->exec("INSERT INTO work_order_products VALUES (601,201,'SSD 500 GB',1),(603,203,'Part without months',1)");
$setting = $pdo->prepare('INSERT INTO settings (setting_key,setting_value,created_at,updated_at) VALUES (?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_at=NOW()');
foreach (['company_name'=>'Original Service','company_address'=>'Original Address','company_phone'=>'222','company_email'=>'service@example.test','company_website'=>'https://service.example','company_logo'=>'logo.png','company_logo_url'=>'https://service.example/logo.png'] as $key => $value) {
    $setting->execute([$key, $value]);
}
$originalTerms = EdsWarrantyCardTerms::sanitizeHtml('<p>Original <b>warranty terms</b></p><script>TERMS-ATTACK</script><ul><li>Keep receipt</li></ul>');
EdsWarrantyCardTerms::write($pdo, $originalTerms);
$originalIssuer = EdsWarrantyCardIssuer::fromPost(issuerPost([
    'eds_warranty_cards_issuer_service_name' => 'Original Service',
    'eds_warranty_cards_issuer_legal_name' => 'Original Service Legal Ltd.',
    'eds_warranty_cards_issuer_registration_number' => 'REG-201',
    'eds_warranty_cards_issuer_representative' => 'Original Representative',
    'eds_warranty_cards_issuer_address' => 'Original Address',
    'eds_warranty_cards_issuer_phone' => '222',
    'eds_warranty_cards_issuer_email' => 'service@example.test',
    'eds_warranty_cards_issuer_website' => 'https://service.example',
    'eds_warranty_cards_issuer_logo_url' => 'https://service.example/logo.png',
]));
EdsWarrantyCardIssuer::write($pdo, $originalIssuer);
$draftModel = new EdsWarrantyCardDraft($pdo);
$issuedModel = new EdsWarrantyCardIssued($pdo);
// Reset only the disposable fixture between independent test groups.
$pdo->exec("UPDATE eds_warranty_cards_counter SET next_number='00004' WHERE id=1");
function saveIssuanceDraft(EdsWarrantyCardDraft $model, int $id, callable $change): void {
    $form = draftForm($model, $id);
    $change($form);
    $model->save($id, $form);
}
$legacyInvalidContent = EdsWarrantyCardDraftLimits::encodeCanonical([
    'units' => [],
    'manual_parts' => [
        'm_13131313131313131313131313131313' => [
            'source'=>'manual', 'name'=>str_repeat('я', 129), 'serial_number'=>'', 'months'=>'12', 'supplier'=>'', 'supplier_card'=>'',
        ],
    ],
    'work_items' => [],
]);
$pdo->prepare('INSERT INTO eds_warranty_cards_drafts (work_order_id,revision,content,created_at,updated_at) VALUES (213,1,?,NOW(),NOW())')
    ->execute([$legacyInvalidContent]);
$legacyInvalidCounter = (new EdsWarrantyCardCounter($pdo))->getNextNumber();
rejects(fn() => $issuedModel->preview(213), t('eds_warranty_cards.part_name_too_long'));
rejects(fn() => $issuedModel->issue(213, '1', str_repeat('0', 64), 1), t('eds_warranty_cards.part_name_too_long'));
check($issuedModel->get(213) === null && $draftModel->get(213)['revision'] === '1'
    && (new EdsWarrantyCardCounter($pdo))->getNextNumber() === $legacyInvalidCounter,
    'invalid draft from an older version cannot issue and preserves its revision and counter');
$correctableLegacy = draftForm($draftModel, 213);
$correctableLegacy['manual_parts']['m_13131313131313131313131313131313']['name'] = 'Corrected old draft';
$draftModel->save(213, $correctableLegacy);
check($draftModel->get(213)['revision'] === '2', 'invalid old draft remains correctable through the normal revision-safe save path');

saveIssuanceDraft($draftModel, 201, function (array &$form): void {
    $form['units']['601_1'] = ['selected'=>true,'serial_number'=>'PART-SN','months'=>'24','supplier'=>'SECRET SUPPLIER','supplier_card'=>'SECRET CARD'];
    $form['manual_parts']['m_44444444444444444444444444444444'] = ['name'=>'Manual RAM','serial_number'=>'MANUAL-A','months'=>'36','supplier'=>'MANUAL SECRET SUPPLIER','supplier_card'=>'MANUAL SECRET CARD'];
    $form['manual_parts']['m_55555555555555555555555555555555'] = ['name'=>'Manual RAM','serial_number'=>'MANUAL-B','months'=>'48','supplier'=>'SECOND MANUAL SECRET','supplier_card'=>'SECOND MANUAL CARD'];
    $form['work_items']['w_11111111111111111111111111111111'] = ['description'=>'Performed work','months'=>'6'];
    $form['work_items']['w_22222222222222222222222222222222'] = ['description'=>'Performed work','months'=>'12'];
});
$preview = $issuedModel->preview(201);
$issuedModel->validatePreview($preview['public']);
check($preview['public']['parts'][0] === ['name'=>'SSD 500 GB','serial_number'=>'PART-SN','months'=>'24'], 'customer preview has selected public part fields');
check($preview['public']['parts'][1] === ['name'=>'Manual RAM','serial_number'=>'MANUAL-A','months'=>'36'] && $preview['public']['parts'][2]['serial_number'] === 'MANUAL-B', 'manual parts enter customer preview independently');
check(!str_contains(json_encode($preview['public']), 'SECRET'), 'customer preview excludes all internal fields');
check($preview['internal']['parts'][0]['supplier'] === 'SECRET SUPPLIER', 'staff preview includes supplier');
check($preview['internal']['parts'][0]['source'] === 'inventory' && $preview['internal']['parts'][1]['source'] === 'manual', 'snapshot preserves internal part origins');
check($preview['internal']['parts'][1]['supplier'] === 'MANUAL SECRET SUPPLIER', 'staff preview includes manual supplier');
check($preview['public']['issuer'] === $originalIssuer && !isset($preview['public']['company'])
    && $preview['public']['customer']['name'] === 'Original Customer', 'preview includes effective module issuer and customer in the new structure');
check(array_keys($preview['public']) === ['issuer', 'customer', 'parts', 'work_warranties', 'terms_html']
    && !isset($preview['public']['device'], $preview['public']['work_order']), 'new customer snapshot excludes all device and work-order data');
check($preview['public']['work_warranties'] === [['description'=>'Performed work','months'=>'6'], ['description'=>'Performed work','months'=>'12']] && !array_key_exists('work_warranty', $preview['public']), 'preview keeps multiple work activities as separate rows in the new format');
check($preview['public']['terms_html'] === '<p>Original <strong>warranty terms</strong></p><ul><li>Keep receipt</li></ul>' && !str_contains($preview['public']['terms_html'], 'TERMS-ATTACK'), 'preview uses current sanitized warranty terms');

saveIssuanceDraft($draftModel, 202, static function (array &$form): void {
    $form['manual_parts']['m_66666666666666666666666666666666'] = EdsWarrantyCardDraftForm::blankManual();
    $form['work_items']['w_99999999999999999999999999999999'] = EdsWarrantyCardDraftForm::blankWorkItem();
});
$invalid = $issuedModel->preview(202);
rejects(fn() => $issuedModel->validatePreview($invalid['public']), t('eds_warranty_cards.issue_empty'));
$before = (new EdsWarrantyCardCounter($pdo))->getNextNumber();
rejects(fn() => $issuedModel->issue(202, $invalid['revision'], $invalid['token'], 1), t('eds_warranty_cards.issue_empty'));
check((new EdsWarrantyCardCounter($pdo))->getNextNumber() === $before && $issuedModel->get(202) === null, 'empty validation does not issue or increment');

saveIssuanceDraft($draftModel, 203, function (array &$form): void {
    $form['units']['603_1'] = ['selected'=>true,'serial_number'=>'','months'=>'','supplier'=>'','supplier_card'=>''];
});
$invalid = $issuedModel->preview(203);
rejects(fn() => $issuedModel->validatePreview($invalid['public']), t('eds_warranty_cards.issue_months_required'));
check($draftModel->get(203)['content']['units']['603_1']['selected'], 'invalid issuance preserves draft');

saveIssuanceDraft($draftModel, 209, static function (array &$form): void {
    $form['manual_parts']['m_77777777777777777777777777777777'] = ['name'=>'','serial_number'=>'ONLY-SERIAL','months'=>'12','supplier'=>'','supplier_card'=>''];
});
$invalidManualName = $issuedModel->preview(209);
rejects(fn() => $issuedModel->validatePreview($invalidManualName['public']), t('eds_warranty_cards.issue_part_name_required'));
check($draftModel->get(209)['content']['manual_parts']['m_77777777777777777777777777777777']['serial_number'] === 'ONLY-SERIAL', 'invalid manual part remains in draft');

saveIssuanceDraft($draftModel, 210, static function (array &$form): void {
    $form['manual_parts']['m_88888888888888888888888888888888'] = ['name'=>'Manual without months','serial_number'=>'','months'=>'','supplier'=>'','supplier_card'=>''];
});
$invalidManualMonths = $issuedModel->preview(210);
rejects(fn() => $issuedModel->validatePreview($invalidManualMonths['public']), t('eds_warranty_cards.issue_months_required'));

saveIssuanceDraft($draftModel, 204, function (array &$form): void {
    $form['work_items']['w_33333333333333333333333333333333'] = ['description'=>'   ','months'=>'12'];
});
$invalid = $issuedModel->preview(204);
rejects(fn() => $issuedModel->validatePreview($invalid['public']), t('eds_warranty_cards.issue_work_item_description_required'));
$before = (new EdsWarrantyCardCounter($pdo))->getNextNumber();
rejects(fn() => $issuedModel->issue(204, $invalid['revision'], $invalid['token'], 1), t('eds_warranty_cards.issue_work_item_description_required'));
check($issuedModel->get(204) === null && (new EdsWarrantyCardCounter($pdo))->getNextNumber() === $before, 'invalid work activity does not issue or increment and remains in draft');
check($draftModel->get(204)['content']['work_items']['w_33333333333333333333333333333333']['description'] === '', 'draft normalizes external spaces while preserving the invalid activity for correction');
foreach (['', '0', '-1', 'invalid'] as $invalidMonths) {
    rejects(
        fn() => $issuedModel->validatePreview(['parts'=>[], 'work_warranties'=>[['description'=>'Valid description','months'=>$invalidMonths]]]),
        t('eds_warranty_cards.issue_work_item_months_required')
    );
}

saveIssuanceDraft($draftModel, 205, function (array &$form): void {
    $form['work_items']['w_44444444444444444444444444444444'] = ['description'=>'Rollback work','months'=>'12'];
});
$rollbackPreview = $issuedModel->preview(205);
$before = (new EdsWarrantyCardCounter($pdo))->getNextNumber();
try {
    $issuedModel->issue(205, $rollbackPreview['revision'], $rollbackPreview['token'], 1, static function (): void { throw new RuntimeException('issuance rollback'); });
    throw new RuntimeException('Expected issuance rollback.');
} catch (RuntimeException $error) {
    check($error->getMessage() === 'issuance rollback', 'issuance failure propagated');
}
check($issuedModel->get(205) === null && (new EdsWarrantyCardCounter($pdo))->getNextNumber() === $before, 'issuance insert and counter roll back together');
check($draftModel->get(205) !== null, 'rollback preserves draft');

$counter = new EdsWarrantyCardCounter($pdo);
$result = $issuedModel->issue(201, $preview['revision'], $preview['token'], 1);
check($result['created'] && $result['card']['card_number'] === '00004', 'formatted number issued exactly');
check($counter->getNextNumber() === '00005', 'formatted counter increments exactly once');
check($result['card']['issued_at'] !== '', 'issuance date frozen in the issued-card record');
check(!isset($result['card']['public']['card_number'], $result['card']['public']['issued_at']), 'number and issue date remain in issued-card columns rather than duplicated in public content');
check($result['card']['public']['terms_html'] === $originalTerms, 'issuance freezes sanitized warranty terms');
check($result['card']['public']['issuer'] === $originalIssuer, 'issuance freezes the complete effective issuer');
$repeat = $issuedModel->issue(201, $preview['revision'], $preview['token'], 1);
check(!$repeat['created'] && $repeat['card']['card_number'] === '00004', 'repeat returns existing card');
check($counter->getNextNumber() === '00005', 'repeat consumes no number');
check((int) $pdo->query('SELECT COUNT(*) FROM eds_warranty_cards_issued WHERE work_order_id=201')->fetchColumn() === 1, 'one issued card per work order');
$lockedForm = draftForm($draftModel, 201);
rejects(fn() => $draftModel->save(201, $lockedForm), t('eds_warranty_cards.draft_locked_issued'));
check($issuedModel->get(201) === $result['card'], 'data layer blocks draft changes after issuance');
$issuedDeleteCounter = $counter->getNextNumber();
$issuedDeleteCard = $issuedModel->get(201);
rejects(fn() => $draftModel->delete(201, $lockedForm['revision']), t('eds_warranty_cards.draft_delete_issued'));
rejects(fn() => $draftModel->delete(201, $lockedForm['revision']), t('eds_warranty_cards.draft_delete_issued'));
check($issuedModel->get(201) === $issuedDeleteCard && $counter->getNextNumber() === $issuedDeleteCounter, 'repeated draft deletion requests cannot change an issued card or counter');
$client = $issuedModel->clientContent(201);
check(!str_contains(json_encode($client), 'SECRET'), 'client projection cannot expose internal data');
check(!array_key_exists('internal', $client), 'client projection has no internal container');
check(count($client['content']['parts']) === 3 && $client['content']['parts'][1]['name'] === 'Manual RAM', 'issued customer snapshot includes manual parts');
check(count($client['content']['work_warranties']) === 2 && $client['content']['work_warranties'][1]['months'] === '12', 'issued customer snapshot freezes every work activity');

$originalCard = $issuedModel->get(201);
$pdo->exec("UPDATE customers SET name='Changed Customer' WHERE id=201");
$pdo->exec("UPDATE work_orders SET computer='Changed PC', description='Changed Problem' WHERE id=201");
$setting->execute(['company_name', 'Changed Service']);
$setting->execute(['company_address', 'Changed General Address']);
EdsWarrantyCardIssuer::write($pdo, EdsWarrantyCardIssuer::fromPost(issuerPost([
    'eds_warranty_cards_issuer_service_name' => 'Changed Module Service',
    'eds_warranty_cards_issuer_legal_name' => 'Changed Legal Ltd.',
    'eds_warranty_cards_issuer_logo_url' => '/changed-logo.svg',
])));
EdsWarrantyCardTerms::write($pdo, '<p>Changed terms</p>');
$pdo->exec("UPDATE eds_warranty_cards_drafts SET content='{}' WHERE work_order_id=201");
check($issuedModel->get(201) === $originalCard, 'issued snapshot immutable after all source changes');
eds_warranty_cards_migrate($pdo);
check($issuedModel->get(201) === $originalCard, 'repeated migration preserves existing issued card and snapshots');

saveIssuanceDraft($draftModel, 206, function (array &$form): void {
    $form['work_items']['w_55555555555555555555555555555555'] = ['description'=>'Huge number work','months'=>'120'];
});
EdsWarrantyCardTerms::write($pdo, '');
$hugeFormatted = '000' . str_repeat('9', 1000);
$counter->advanceTo($hugeFormatted);
$hugePreview = $issuedModel->preview(206);
check($hugePreview['public']['terms_html'] === '', 'empty current terms produce no snapshot content');
check($hugePreview['public']['parts'] === [] && count($hugePreview['public']['work_warranties']) === 1, 'card can be issued with work activities only');
$hugeResult = $issuedModel->issue(206, $hugePreview['revision'], $hugePreview['token'], 1);
check($hugeResult['card']['card_number'] === $hugeFormatted, 'very large formatted number frozen exactly');
check($counter->getNextNumber() === '001' . str_repeat('0', 1000), 'very large formatted number increments exactly');

saveIssuanceDraft($draftModel, 208, function (array &$form): void {
    $form['work_items']['w_66666666666666666666666666666666'] = ['description'=>'Preview change','months'=>'3'];
});
$changedPreview = $issuedModel->preview(208);
$pdo->exec("UPDATE work_orders SET model='Changed after preview' WHERE id=208");
$before = $counter->getNextNumber();
$taskIndependentIssue = $issuedModel->issue(208, $changedPreview['revision'], $changedPreview['token'], 1);
check($taskIndependentIssue['created'] && $counter->getNextNumber() === EdsWarrantyCardNumber::increment($before)
    && !isset($taskIndependentIssue['card']['public']['device'], $taskIndependentIssue['card']['public']['work_order']), 'device-only task changes do not enter or invalidate the customer document');

saveIssuanceDraft($draftModel, 211, function (array &$form): void {
    $form['work_items']['w_77777777777777777777777777777777'] = ['description'=>'Terms preview change','months'=>'3'];
});
EdsWarrantyCardTerms::write($pdo, '<p>Terms before preview</p>');
$issuerBeforePreview = EdsWarrantyCardIssuer::fromPost(issuerPost(['eds_warranty_cards_issuer_service_name' => 'Issuer before preview']));
EdsWarrantyCardIssuer::write($pdo, $issuerBeforePreview);
$termsPreview = $issuedModel->preview(211);
EdsWarrantyCardTerms::write($pdo, '<p>Terms changed after preview</p>');
EdsWarrantyCardIssuer::write($pdo, EdsWarrantyCardIssuer::fromPost(issuerPost(['eds_warranty_cards_issuer_service_name' => 'Issuer changed after preview'])));
$before = $counter->getNextNumber();
rejects(fn() => $issuedModel->issue(211, $termsPreview['revision'], $termsPreview['token'], 1), t('eds_warranty_cards.issue_preview_changed'));
check($issuedModel->get(211) === null && $counter->getNextNumber() === $before, 'terms or issuer change after preview issues nothing and consumes no number');

saveIssuanceDraft($draftModel, 207, function (array &$form): void {
    $form['work_items']['w_88888888888888888888888888888888'] = ['description'=>'Concurrent issue','months'=>'3'];
});
$racePreview = $issuedModel->preview(207);
unset($issuedModel, $draftModel, $counter, $pdo);
$workers = [];
for ($i = 0; $i < 2; $i++) {
    $pid = pcntl_fork();
    if ($pid === -1) { throw new RuntimeException('fork failed'); }
    if ($pid === 0) {
        try {
            $result = (new EdsWarrantyCardIssued(connection()))->issue(207, $racePreview['revision'], $racePreview['token'], 1);
            exit($result['created'] ? 0 : 2);
        } catch (Throwable $error) { fwrite(STDERR, $error->getMessage() . "\n"); exit(3); }
    }
    $workers[] = $pid;
}
$exits = [];
foreach ($workers as $pid) { pcntl_waitpid($pid, $status); check(pcntl_wifexited($status), 'issuance worker completed'); $exits[] = pcntl_wexitstatus($status); }
sort($exits);
check($exits === [0,2], 'concurrent issuance creates once and returns existing once');
$pdo = connection(); $issuedModel = new EdsWarrantyCardIssued($pdo); $counter = new EdsWarrantyCardCounter($pdo);
check((int) $pdo->query('SELECT COUNT(*) FROM eds_warranty_cards_issued WHERE work_order_id=207')->fetchColumn() === 1, 'concurrent issuance has one row');
check($counter->getNextNumber() === EdsWarrantyCardNumber::increment($before), 'concurrent issuance consumes exactly one number');
saveIssuanceDraft(new EdsWarrantyCardDraft($pdo), 212, static function (array &$form): void {
    $form['work_items']['w_12121212121212121212121212121212'] = ['description'=>'Issue/delete race','months'=>'12'];
});
$raceDeleteModel = new EdsWarrantyCardDraft($pdo);
$issueDeletePreview = $issuedModel->preview(212);
$issueDeleteRevision = $raceDeleteModel->get(212)['revision'];
$issueDeleteCounter = $counter->getNextNumber();
unset($issuedModel, $counter, $raceDeleteModel, $pdo);
$issueDeleteWorkers = [];
foreach (['issue', 'delete'] as $operation) {
    $pid = pcntl_fork();
    if ($pid === -1) { throw new RuntimeException('fork failed'); }
    if ($pid === 0) {
        try {
            if ($operation === 'issue') {
                (new EdsWarrantyCardIssued(connection()))->issue(212, $issueDeletePreview['revision'], $issueDeletePreview['token'], 1);
            } else {
                (new EdsWarrantyCardDraft(connection()))->delete(212, $issueDeleteRevision);
            }
            exit(0);
        } catch (InvalidArgumentException $error) {
            $expected = in_array($error->getMessage(), [t('eds_warranty_cards.no_draft_to_issue'), t('eds_warranty_cards.draft_delete_issued')], true);
            exit($expected ? 2 : 3);
        } catch (Throwable $error) {
            fwrite(STDERR, $error->getMessage() . "\n");
            exit(3);
        }
    }
    $issueDeleteWorkers[] = $pid;
}
$issueDeleteExits = [];
foreach ($issueDeleteWorkers as $pid) {
    pcntl_waitpid($pid, $status);
    check(pcntl_wifexited($status), 'issue/delete race worker completed');
    $issueDeleteExits[] = pcntl_wexitstatus($status);
}
sort($issueDeleteExits);
check($issueDeleteExits === [0, 2], 'concurrent issue and deletion allow exactly one mutation');
$pdo = connection();
$issuedModel = new EdsWarrantyCardIssued($pdo);
$counter = new EdsWarrantyCardCounter($pdo);
$issueDeleteCard = $issuedModel->get(212);
$issueDeleteDraft = (new EdsWarrantyCardDraft($pdo))->get(212);
check(($issueDeleteCard !== null && $issueDeleteDraft !== null && $counter->getNextNumber() === EdsWarrantyCardNumber::increment($issueDeleteCounter))
    || ($issueDeleteCard === null && $issueDeleteDraft === null && $counter->getNextNumber() === $issueDeleteCounter), 'issue/delete race preserves a complete issued card or a complete deletion without consuming an extra number');
try {
    $pdo->exec('DELETE FROM work_orders WHERE id=201');
    throw new RuntimeException('Expected issued-card delete protection.');
} catch (PDOException) {
    check((int) $pdo->query('SELECT COUNT(*) FROM work_orders WHERE id=201')->fetchColumn() === 1, 'database backstop blocks task deletion');
}
