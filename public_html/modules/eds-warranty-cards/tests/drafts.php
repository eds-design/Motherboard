<?php
if (PHP_SAPI !== 'cli' || !isset($socket, $checks)) {
    exit;
}
$pdo = connection();
$draftModel = new EdsWarrantyCardDraft($pdo);
$counterBefore = (new EdsWarrantyCardCounter($pdo))->getNextNumber();
$pdo->exec("INSERT IGNORE INTO work_orders (id,work_order_number,computer,description,status,priority,created_at) VALUES
    (101,'WO-101','PC','Problem','Open','Standard',NOW()),
    (102,'WO-102','PC','Problem','Open','Standard',NOW()),
    (103,'WO-103','PC','Problem','Open','Standard',NOW()),
    (104,'WO-104','PC','Problem','Open','Standard',NOW()),
    (105,'WO-105','PC','Problem','Open','Standard',NOW()),
    (106,'WO-106','PC','Problem','Open','Standard',NOW()),
    (107,'WO-107','PC','Problem','Open','Standard',NOW()),
    (108,'WO-108','PC','Problem','Open','Standard',NOW()),
    (109,'WO-109','PC','Problem','Open','Standard',NOW()),
    (110,'WO-110','PC','Problem','Open','Standard',NOW()),
    (111,'WO-111','PC','Problem','Open','Standard',NOW()),
    (112,'WO-112','PC','Problem','Open','Standard',NOW())");
$pdo->exec('DROP TABLE IF EXISTS work_order_products');
$pdo->exec('CREATE TABLE work_order_products (id INT PRIMARY KEY, work_order_id INT NOT NULL, product_name VARCHAR(255) NOT NULL, quantity INT NOT NULL) ENGINE=InnoDB');
$pdo->exec("INSERT INTO work_order_products VALUES (501, 101, 'Смяна на хард диск', 1), (502, 101, 'SSD 500 GB', 2), (503, 101, 'Друга позиция', 1), (504, 102, 'Друга задача', 1)");
$moduleSetting = $pdo->prepare("INSERT INTO settings (setting_key,setting_value,created_at,updated_at) VALUES ('enabled_modules',?,NOW(),NOW()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_at=NOW()");
$moduleSetting->execute(['["eds-warranty-cards","inventory"]']);
check(count($draftModel->lines(101)) === 3, 'inventory lines available while Inventory module setting is enabled');
$moduleSetting->execute(['["eds-warranty-cards"]']);
check(count($draftModel->lines(101)) === 3, 'saved inventory lines remain available while Inventory module setting is disabled');
function draftForm(EdsWarrantyCardDraft $model, int $id): array {
    $draft = $model->get($id);
    $units = [];
    foreach (EdsWarrantyCardDraftForm::rows($model->lines($id), $draft['content']['units'] ?? []) as $key => $row) {
        $units[$key] = $row['fields'];
    }
    return [
        'revision' => $draft['revision'] ?? '0',
        'inventory_token' => EdsWarrantyCardDraftForm::token($model->lines($id)),
        'units' => $units,
        'manual_parts' => $draft['content']['manual_parts'] ?? [],
        'work_items' => $draft['content']['work_items'] ?? [],
        'invalid' => false,
    ];
}
check($draftModel->get(101) === null, 'opening has no draft write');
$rows = EdsWarrantyCardDraftForm::rows($draftModel->lines(101));
check(count($rows) === 4, 'all lines expanded into individual units');
check(isset($rows['501_1'], $rows['502_1'], $rows['502_2'], $rows['503_1']), 'labor and all units included without filtering');
check(array_column(array_column($rows, 'fields'), 'selected') === [false, false, false, false], 'all selections initially empty');
$form = draftForm($draftModel, 101);
$form['units']['502_1']['selected'] = true;
$form['units']['502_2']['selected'] = true;
$form['work_items']['w_11111111111111111111111111111111'] = EdsWarrantyCardDraftForm::blankWorkItem();
$form['work_items']['w_22222222222222222222222222222222'] = ['description' => 'Partial activity', 'months' => ''];
$draftModel->save(101, $form);
$saved = $draftModel->get(101);
check($saved['revision'] === '1', 'incomplete draft saved');
check($saved['content']['units']['502_1']['months'] === '' && $saved['content']['work_items']['w_22222222222222222222222222222222']['months'] === '', 'incomplete selected fields remain empty');
check(!isset($saved['content']['number'], $saved['content']['issued_at']), 'draft has no number or issuance data');
check(!$saved['content']['units']['501_1']['selected'], 'labor not selected automatically');
check(count($saved['content']['work_items']) === 2 && EdsWarrantyCardDraftForm::workItemIsEmpty($saved['content']['work_items']['w_11111111111111111111111111111111']), 'empty and partial work activity rows are saved');
$stale = draftForm($draftModel, 101);
$form = $stale;
$form['units']['502_1'] = ['selected' => true, 'serial_number' => 'SN-A', 'months' => '12', 'supplier' => 'Доставчик А', 'supplier_card' => 'SUP-A', 'name' => 'FORGED NAME'];
$form['units']['502_2'] = ['selected' => true, 'serial_number' => 'SN-B', 'months' => '24', 'supplier' => 'Доставчик Б', 'supplier_card' => 'SUP-B'];
$form['work_items']['w_11111111111111111111111111111111'] = ['description' => '<script>MY-WORK</script>', 'months' => '3'];
$draftModel->save(101, $form);
$saved = $draftModel->get(101);
check($saved['revision'] === '2', 'edit increments revision');
check($saved['content']['units']['502_1']['serial_number'] === 'SN-A' && $saved['content']['units']['502_2']['serial_number'] === 'SN-B', 'each unit retains independent data');
check($saved['content']['units']['502_1']['name'] === 'SSD 500 GB', 'name read from source, never from POST');
check($saved['content']['units']['502_2']['supplier_card'] === 'SUP-B', 'internal fields saved in draft');
check($saved['content']['units']['502_1']['source'] === 'inventory', 'inventory origin stored explicitly');
rejects(fn() => $draftModel->save(101, $stale), t('eds_warranty_cards.edit_conflict'));
check($draftModel->get(101) === $saved, 'stale editor changes nothing');
$form = draftForm($draftModel, 101);
$form['units']['502_1']['months'] = '-2';
rejects(fn() => $draftModel->save(101, $form), t('eds_warranty_cards.invalid_months'));
check($draftModel->get(101) === $saved, 'invalid months changes nothing');
$parsed = EdsWarrantyCardDraftForm::read([
    'revision' => '2', 'inventory_token' => $form['inventory_token'], 'form_complete' => '1',
    'units' => ['502_1' => ['selected' => '1', 'serial_number' => 'MY-INPUT', 'months' => '-2', 'supplier' => 'MY-SUPPLIER', 'supplier_card' => 'MY-CARD']],
    'manual_parts' => ['m_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' => ['name' => 'MY MANUAL', 'serial_number' => 'MANUAL-SN', 'months' => '-3', 'supplier' => 'MANUAL-SUPPLIER', 'supplier_card' => 'MANUAL-CARD']],
    'work_items' => ['w_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' => ['description' => '<script>MY-WORK</script>', 'months' => 'invalid']],
]);
$errorRows = EdsWarrantyCardDraftForm::rows($draftModel->lines(101), $saved['content']['units'], $parsed['units']);
check($errorRows['502_1']['fields']['serial_number'] === 'MY-INPUT' && $errorRows['502_1']['fields']['months'] === '-2', 'invalid submitted part data retained');
check($parsed['work_items']['w_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'] === ['description' => '<script>MY-WORK</script>', 'months' => 'invalid'], 'invalid submitted work data retained');
check($parsed['manual_parts']['m_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa']['serial_number'] === 'MANUAL-SN' && $parsed['manual_parts']['m_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa']['months'] === '-3', 'invalid submitted manual part data retained');
check(!EdsWarrantyCardDraftForm::read(['revision' => '0', 'form_complete' => '1'])['invalid'], 'empty fields accepted by parser');
check(EdsWarrantyCardDraftForm::read(['revision' => '0'])['invalid'], 'truncated POST detected');
check(EdsWarrantyCardDraftForm::read(['revision' => [], 'form_complete' => '1'])['invalid'], 'malformed scalar rejected');
check(EdsWarrantyCardDraftForm::read(['revision'=>'0','form_complete'=>'1','manual_parts'=>['bad-key'=>[]]])['invalid'], 'malformed manual key rejected');
check(EdsWarrantyCardDraftForm::read(['revision'=>'0','form_complete'=>'1','work_items'=>['bad-key'=>[]]])['invalid'], 'malformed work activity key rejected');
$form = draftForm($draftModel, 101);
$form['units']['504_1'] = ['selected' => true] + EdsWarrantyCardDraftForm::blank();
rejects(fn() => $draftModel->save(101, $form), t('eds_warranty_cards.unavailable_selected'));
check($draftModel->get(101) === $saved, 'foreign task unit not saved');
$form = draftForm($draftModel, 101);
$form['units']['forged_1'] = EdsWarrantyCardDraftForm::blank();
rejects(fn() => $draftModel->save(101, $form), t('eds_warranty_cards.unavailable_selected'));
check($draftModel->get(101) === $saved, 'unselected forged inventory key is rejected and changes nothing');
$form = draftForm($draftModel, 101);
$pdo->exec('UPDATE work_order_products SET quantity = 1 WHERE id = 502');
rejects(fn() => $draftModel->save(101, $form), t('eds_warranty_cards.inventory_conflict'));
check($draftModel->get(101) === $saved, 'quantity change cannot silently remove selected unit');
$form = draftForm($draftModel, 101);
$draftModel->save(101, $form);
$saved = $draftModel->get(101);
check($saved['content']['units']['502_2']['selected'], 'saved inventory unit remains editable after source line disappears');
$form = draftForm($draftModel, 101);
$form['units']['502_2']['selected'] = false;
$draftModel->save(101, $form);
$saved = $draftModel->get(101);
check(isset($saved['content']['units']['502_2']) && !$saved['content']['units']['502_2']['selected'], 'unavailable saved unit data is retained when deselected');
// Manual parts use stable module-owned keys and never touch inventory.
$inventoryBefore = $pdo->query('SELECT id,work_order_id,product_name,quantity FROM work_order_products ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$form = draftForm($draftModel, 101);
$form['manual_parts'] = [
    'm_11111111111111111111111111111111' => ['name'=>'RAM 16 GB','serial_number'=>'RAM-A','months'=>'36','supplier'=>'Internal A','supplier_card'=>'Card A'],
    'm_22222222222222222222222222222222' => ['name'=>'RAM 16 GB','serial_number'=>'RAM-B','months'=>'24','supplier'=>'Internal B','supplier_card'=>'Card B'],
    'm_33333333333333333333333333333333' => EdsWarrantyCardDraftForm::blankManual(),
];
$draftModel->save(101, $form);
$saved = $draftModel->get(101);
check(count($saved['content']['manual_parts']) === 3, 'manual parts including empty draft row saved');
check($saved['content']['manual_parts']['m_11111111111111111111111111111111']['source'] === 'manual', 'manual origin stored explicitly');
check($saved['content']['manual_parts']['m_11111111111111111111111111111111']['serial_number'] === 'RAM-A' && $saved['content']['manual_parts']['m_22222222222222222222222222222222']['serial_number'] === 'RAM-B', 'identical manual names retain separate serial numbers');
check($pdo->query('SELECT id,work_order_id,product_name,quantity FROM work_order_products ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) === $inventoryBefore, 'manual draft save leaves inventory unchanged');
$form = draftForm($draftModel, 101);
unset($form['manual_parts']['m_22222222222222222222222222222222']);
$form['manual_parts']['m_11111111111111111111111111111111']['serial_number'] = 'RAM-A-EDITED';
$form['work_items'] = [
    'w_33333333333333333333333333333333' => ['description' => 'Диагностика', 'months' => '3'],
    'w_44444444444444444444444444444444' => ['description' => 'Диагностика', 'months' => '6'],
];
$draftModel->save(101, $form);
$saved = $draftModel->get(101);
check(count($saved['content']['manual_parts']) === 2 && !isset($saved['content']['manual_parts']['m_22222222222222222222222222222222']) && $saved['content']['manual_parts']['m_11111111111111111111111111111111']['serial_number'] === 'RAM-A-EDITED', 'manual row removed and remaining rows saved without duplication');
check(count($saved['content']['work_items']) === 2 && $saved['content']['work_items']['w_33333333333333333333333333333333']['months'] === '3' && $saved['content']['work_items']['w_44444444444444444444444444444444']['months'] === '6', 'identical work descriptions remain separate activities');
$form = draftForm($draftModel, 101);
unset($form['work_items']['w_33333333333333333333333333333333']);
$form['work_items']['w_44444444444444444444444444444444']['description'] = 'Редактирана диагностика';
$draftModel->save(101, $form);
$saved = $draftModel->get(101);
check(count($saved['content']['work_items']) === 1 && !isset($saved['content']['work_items']['w_33333333333333333333333333333333']) && $saved['content']['work_items']['w_44444444444444444444444444444444']['description'] === 'Редактирана диагностика', 'individual work activity can be edited and removed');
check((int) $pdo->query('SELECT COUNT(*) FROM eds_warranty_cards_drafts WHERE work_order_id = 101')->fetchColumn() === 1, 'exactly one draft per task');
eds_warranty_cards_migrate($pdo);
check($draftModel->get(101) === $saved, 'migration preserves draft');
$form = draftForm($draftModel, 102);
$form['work_items']['w_55555555555555555555555555555555'] = ['description' => 'Work only', 'months' => '2'];
$draftModel->save(102, $form);
check(!$draftModel->get(102)['content']['units']['504_1']['selected'], 'work warranty never selects inventory');
$deleteRevision = $draftModel->get(102)['revision'];
$deleteCounter = (new EdsWarrantyCardCounter($pdo))->getNextNumber();
$deleteInventory = $pdo->query('SELECT id,work_order_id,product_name,quantity FROM work_order_products ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$deleteWorkOrder = $pdo->query('SELECT * FROM work_orders WHERE id=102')->fetch(PDO::FETCH_ASSOC);
$draftModel->delete(102, $deleteRevision);
check($draftModel->get(102) === null, 'existing draft is deleted');
check((new EdsWarrantyCardCounter($pdo))->getNextNumber() === $deleteCounter, 'draft deletion does not change counter');
check($pdo->query('SELECT id,work_order_id,product_name,quantity FROM work_order_products ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) === $deleteInventory, 'draft deletion does not change inventory rows or quantities');
check($pdo->query('SELECT * FROM work_orders WHERE id=102')->fetch(PDO::FETCH_ASSOC) === $deleteWorkOrder, 'draft deletion does not change work order');
$newForm = draftForm($draftModel, 102);
check($draftModel->get(102) === null && $newForm['manual_parts'] === [] && $newForm['work_items'] === [] && array_filter($newForm['units'], static fn(array $unit): bool => $unit['selected']) === [], 'opening after deletion produces a new empty unsaved form');
rejects(fn() => $draftModel->delete(102, $deleteRevision), t('eds_warranty_cards.draft_delete_missing'));
$staleDeleteForm = draftForm($draftModel, 106);
$staleDeleteForm['work_items']['w_66666666666666666666666666666666'] = ['description'=>'First version','months'=>'3'];
$draftModel->save(106, $staleDeleteForm);
$staleDeleteRevision = $draftModel->get(106)['revision'];
$newerDeleteForm = draftForm($draftModel, 106);
$newerDeleteForm['work_items']['w_66666666666666666666666666666666']['description'] = 'Newer version';
$draftModel->save(106, $newerDeleteForm);
rejects(fn() => $draftModel->delete(106, $staleDeleteRevision), t('eds_warranty_cards.draft_delete_conflict'));
check($draftModel->get(106)['content']['work_items']['w_66666666666666666666666666666666']['description'] === 'Newer version', 'stale deletion preserves the newer draft');
$deleteRaceForm = draftForm($draftModel, 107);
$deleteRaceForm['work_items']['w_77777777777777777777777777777777'] = ['description'=>'Race version one','months'=>'6'];
$draftModel->save(107, $deleteRaceForm);
$deleteRaceForm = draftForm($draftModel, 107);
$deleteRaceForm['work_items']['w_77777777777777777777777777777777']['description'] = 'Race version two';
$deleteRaceRevision = $deleteRaceForm['revision'];
unset($pdo, $draftModel);
$deleteRaceWorkers = [];
foreach (['save', 'delete'] as $operation) {
    $pid = pcntl_fork();
    if ($pid === -1) { throw new RuntimeException('fork failed'); }
    if ($pid === 0) {
        try {
            $workerModel = new EdsWarrantyCardDraft(connection());
            $operation === 'save'
                ? $workerModel->save(107, $deleteRaceForm)
                : $workerModel->delete(107, $deleteRaceRevision);
            exit(0);
        } catch (InvalidArgumentException $error) {
            $expected = in_array($error->getMessage(), [t('eds_warranty_cards.edit_conflict'), t('eds_warranty_cards.draft_delete_conflict'), t('eds_warranty_cards.draft_delete_missing')], true);
            exit($expected ? 2 : 3);
        } catch (Throwable $error) {
            fwrite(STDERR, $error->getMessage() . "\n");
            exit(3);
        }
    }
    $deleteRaceWorkers[] = $pid;
}
$deleteRaceExits = [];
foreach ($deleteRaceWorkers as $pid) {
    pcntl_waitpid($pid, $status);
    check(pcntl_wifexited($status), 'save/delete race worker completed');
    $deleteRaceExits[] = pcntl_wexitstatus($status);
}
sort($deleteRaceExits);
check($deleteRaceExits === [0, 2], 'concurrent save and deletion allow exactly one mutation');
$pdo = connection();
$draftModel = new EdsWarrantyCardDraft($pdo);
$deleteRaceResult = $draftModel->get(107);
check($deleteRaceResult === null || ($deleteRaceResult['revision'] === '2' && $deleteRaceResult['content']['work_items']['w_77777777777777777777777777777777']['description'] === 'Race version two'), 'save/delete race leaves either no draft or the complete newer revision');

// Strict draft limits are enforced transactionally at their exact boundaries.
$limitForm = draftForm($draftModel, 108);
for ($i = 0; $i < EdsWarrantyCardDraftLimits::MANUAL_ROWS; $i++) {
    $key = 'm_' . str_pad(dechex($i), 32, '0', STR_PAD_LEFT);
    $limitForm['manual_parts'][$key] = ['name'=>'Част','serial_number'=>'','months'=>'','supplier'=>'','supplier_card'=>''];
}
$draftModel->save(108, $limitForm);
check(count($draftModel->get(108)['content']['manual_parts']) === 100, 'exactly 100 non-empty manual parts are saved');

$workLimitForm = draftForm($draftModel, 109);
for ($i = 0; $i < EdsWarrantyCardDraftLimits::WORK_ITEMS; $i++) {
    $key = 'w_' . str_pad(dechex($i), 32, '0', STR_PAD_LEFT);
    $workLimitForm['work_items'][$key] = ['description'=>'Дейност','months'=>''];
}
$draftModel->save(109, $workLimitForm);
check(count($draftModel->get(109)['content']['work_items']) === 50, 'exactly 50 partial work activities are saved');

$baselineForm = draftForm($draftModel, 110);
$baselineForm['manual_parts']['m_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'] = ['name'=>'Baseline','serial_number'=>'','months'=>'','supplier'=>'','supplier_card'=>''];
$draftModel->save(110, $baselineForm);
$baselineDraft = $draftModel->get(110);
$limitCounter = (new EdsWarrantyCardCounter($pdo))->getNextNumber();
$invalidCases = [
    ['name', str_repeat('я', 129), 'eds_warranty_cards.part_name_too_long'],
    ['serial_number', str_repeat('я', 129), 'eds_warranty_cards.serial_number_too_long'],
    ['supplier', str_repeat('я', 129), 'eds_warranty_cards.supplier_too_long'],
    ['supplier_card', str_repeat('я', 129), 'eds_warranty_cards.supplier_card_too_long'],
    ['months', '1000000', 'eds_warranty_cards.months_too_long'],
];
foreach ($invalidCases as [$field, $value, $messageKey]) {
    $invalidForm = draftForm($draftModel, 110);
    $invalidForm['manual_parts']['m_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'][$field] = $value;
    rejects(fn() => $draftModel->save(110, $invalidForm), t($messageKey));
    check($draftModel->get(110) === $baselineDraft && (new EdsWarrantyCardCounter($pdo))->getNextNumber() === $limitCounter, $field . ' rejection preserves draft revision, content and counter');
}
foreach (["line\nbreak", "bad\xFF", "control\x01value"] as $invalidText) {
    $invalidForm = draftForm($draftModel, 110);
    $invalidForm['manual_parts']['m_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa']['serial_number'] = $invalidText;
    rejects(fn() => $draftModel->save(110, $invalidForm), t('eds_warranty_cards.invalid_draft_text'));
    check($draftModel->get(110) === $baselineDraft && (new EdsWarrantyCardCounter($pdo))->getNextNumber() === $limitCounter, 'invalid single-line text rejection preserves draft and counter');
}
$invalidWork = draftForm($draftModel, 110);
$invalidWork['work_items']['w_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'] = ['description'=>str_repeat('я', 251),'months'=>'1'];
rejects(fn() => $draftModel->save(110, $invalidWork), t('eds_warranty_cards.work_description_too_long'));
check($draftModel->get(110) === $baselineDraft, 'overlong work description preserves saved draft');

$tooManyManual = draftForm($draftModel, 110);
$tooManyManual['manual_parts'] = [];
for ($i = 0; $i < 101; $i++) {
    $tooManyManual['manual_parts']['m_' . str_pad(dechex($i), 32, '0', STR_PAD_LEFT)] = EdsWarrantyCardDraftForm::blankManual();
}
rejects(fn() => $draftModel->save(110, $tooManyManual), t('eds_warranty_cards.too_many_parts'));
$tooManyWork = draftForm($draftModel, 110);
$tooManyWork['work_items'] = [];
for ($i = 0; $i < 51; $i++) {
    $tooManyWork['work_items']['w_' . str_pad(dechex($i), 32, '0', STR_PAD_LEFT)] = EdsWarrantyCardDraftForm::blankWorkItem();
}
rejects(fn() => $draftModel->save(110, $tooManyWork), t('eds_warranty_cards.too_many_work_items'));
check($draftModel->get(110) === $baselineDraft && (new EdsWarrantyCardCounter($pdo))->getNextNumber() === $limitCounter, 'oversized raw row arrays preserve revision, draft content and counter');

$pdo->exec("INSERT INTO work_order_products VALUES (511,111,'Oversized quantity',101),(512,112,'Exact quantity',100)");
rejects(fn() => EdsWarrantyCardDraftForm::rows($draftModel->lines(111)), t('eds_warranty_cards.too_many_inventory_units'));
$oversizedInventoryForm = [
    'revision'=>'0', 'inventory_token'=>EdsWarrantyCardDraftForm::token($draftModel->lines(111)),
    'units'=>[], 'manual_parts'=>[], 'work_items'=>[], 'invalid'=>false,
];
rejects(fn() => $draftModel->save(111, $oversizedInventoryForm), t('eds_warranty_cards.too_many_inventory_units'));
check($draftModel->get(111) === null && (new EdsWarrantyCardCounter($pdo))->getNextNumber() === $limitCounter, 'oversized inventory expansion writes no draft and changes no counter');
$hundredInventoryRows = EdsWarrantyCardDraftForm::rows($draftModel->lines(112));
check(count($hundredInventoryRows) === 100, 'exactly 100 inventory physical units are expanded');
$combinedLimitForm = draftForm($draftModel, 112);
$combinedLimitForm['units']['512_1']['selected'] = true;
for ($i = 0; $i < 100; $i++) {
    $combinedLimitForm['manual_parts']['m_' . str_pad(dechex($i), 32, '0', STR_PAD_LEFT)] = ['name'=>'Manual','serial_number'=>'','months'=>'','supplier'=>'','supplier_card'=>''];
}
rejects(fn() => $draftModel->save(112, $combinedLimitForm), t('eds_warranty_cards.too_many_parts'));
check($draftModel->get(112) === null && (new EdsWarrantyCardCounter($pdo))->getNextNumber() === $limitCounter, '101 combined selected and manual parts are rejected atomically');

$pdo->exec('DROP TABLE work_order_products');
check($draftModel->lines(103) === [], 'missing inventory table supported');
$form = draftForm($draftModel, 101);
$draftModel->save(101, $form);
$savedWithoutInventory = $draftModel->get(101);
check($savedWithoutInventory['content']['units']['502_1']['name'] === 'SSD 500 GB', 'existing inventory draft data remains editable without inventory table');
check(isset($savedWithoutInventory['content']['manual_parts']['m_11111111111111111111111111111111']), 'manual rows remain when inventory table is missing');
$legacyContent = json_encode(['units'=>['legacy_1'=>['name'=>'Legacy saved part','selected'=>true,'serial_number'=>'LEGACY','months'=>'12','supplier'=>'Legacy supplier','supplier_card'=>'Legacy card']],'work_enabled'=>true,'work_description'=>'Legacy performed work','work_months'=>'12'], JSON_THROW_ON_ERROR);
$stmt = $pdo->prepare('INSERT INTO eds_warranty_cards_drafts (work_order_id,revision,content,created_at,updated_at) VALUES (?,1,?,NOW(),NOW())');
$stmt->execute([104, $legacyContent]);
$legacyForm = draftForm($draftModel, 104);
check(count($legacyForm['work_items']) === 1 && reset($legacyForm['work_items']) === ['description'=>'Legacy performed work','months'=>'12'], 'enabled legacy draft is shown as one work activity');
$draftModel->save(104, $legacyForm);
$legacySaved = $draftModel->get(104);
check($legacySaved['content']['units']['legacy_1']['source'] === 'inventory' && $legacySaved['content']['units']['legacy_1']['serial_number'] === 'LEGACY', 'legacy draft without origin remains editable and gains inventory origin');
check(isset($legacySaved['content']['work_items']) && !isset($legacySaved['content']['work_enabled'], $legacySaved['content']['work_description'], $legacySaved['content']['work_months']), 'legacy draft is saved in the new work activity format');
$disabledLegacy = json_encode(['units'=>[],'work_enabled'=>false,'work_description'=>'Hidden old value','work_months'=>'24'], JSON_THROW_ON_ERROR);
$stmt->execute([105, $disabledLegacy]);
check($draftModel->get(105)['content']['work_items'] === [], 'disabled legacy draft does not activate hidden work values');
$disabledLegacyForm = draftForm($draftModel, 105);
$draftModel->save(105, $disabledLegacyForm);
$disabledLegacySaved = $draftModel->get(105);
check($disabledLegacySaved['content']['work_items'] === [] && !isset($disabledLegacySaved['content']['work_enabled'], $disabledLegacySaved['content']['work_description'], $disabledLegacySaved['content']['work_months']), 'disabled legacy draft saves without a work activity');
$race = draftForm($draftModel, 103);
unset($pdo, $draftModel, $counter);
$workers = [];
for ($i = 0; $i < 2; $i++) {
    $pid = pcntl_fork();
    if ($pid === -1) { throw new RuntimeException('fork failed'); }
    if ($pid === 0) {
        try {
            (new EdsWarrantyCardDraft(connection()))->save(103, $race);
            exit(0);
        } catch (InvalidArgumentException $error) {
            exit($error->getMessage() === t('eds_warranty_cards.edit_conflict') ? 2 : 3);
        } catch (Throwable $error) {
            fwrite(STDERR, $error->getMessage() . "\n");
            exit(3);
        }
    }
    $workers[] = $pid;
}
$exits = [];
foreach ($workers as $pid) {
    pcntl_waitpid($pid, $status);
    check(pcntl_wifexited($status), 'draft worker completed');
    $exits[] = pcntl_wexitstatus($status);
}
sort($exits);
check($exits === [0, 2], 'concurrent first saves create one draft and one conflict');
$pdo = connection();
$draftModel = new EdsWarrantyCardDraft($pdo);
check($draftModel->get(103)['revision'] === '1', 'concurrent creation does not overwrite');
check((new EdsWarrantyCardCounter($pdo))->getNextNumber() === $counterBefore, 'all draft operations leave counter untouched');

// Render the real module template using a minimal layout, and inspect the DOM.
$fixtureRoot = sys_get_temp_dir() . '/eds-warranty-card-view-' . bin2hex(random_bytes(8));
mkdir($fixtureRoot . '/views', 0700, true);
file_put_contents($fixtureRoot . '/views/layout.php', '<?php echo $content;');
define('ROOT_PATH', $fixtureRoot);
define('BASE_URL', 'http://isolated.example');
define('APP_NAME', 'Test');
$render = static function (bool $editable, bool $savedDraft = true) use ($parsed, $errorRows): string {
    $workOrder = ['id' => 101, 'work_order_number' => 'WO-TEST', 'customer_name' => 'Test customer', 'computer' => 'PC'];
    $form = $parsed; $rows = $errorRows; $manualParts = $parsed['manual_parts']; $workItems = $parsed['work_items']; $canEdit = $editable;
    $hasSavedDraft = $savedDraft;
    $error = t('eds_warranty_cards.invalid_months'); $message = ''; $csrf_token = 'CSRF-TEST';
    $conflict = false; $inventoryChanged = false;
    ob_start();
    include dirname(__DIR__) . '/views/draft.php';
    return ob_get_clean();
};
$html = $render(true);
$dom = new DOMDocument();
@$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
$xpath = new DOMXPath($dom);
check($xpath->query('//input[@name="units[502_1][serial_number]"]')->item(0)->getAttribute('value') === 'MY-INPUT', 'error form renders retained serial');
check($xpath->query('//input[@name="units[502_1][months]"]')->item(0)->getAttribute('value') === '-2', 'error form renders retained invalid months');
check($xpath->query('//input[@name="units[502_1][selected]" and @checked]')->length === 1, 'error form retains selection');
check($xpath->query('//input[@name="manual_parts[m_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa][serial_number]"]')->item(0)->getAttribute('value') === 'MANUAL-SN', 'error form renders retained manual serial');
check($xpath->query('//button[@id="eds-add-manual-part"]')->length === 1 && $xpath->query('//*[@data-eds-remove-manual]')->length >= 1, 'editable form provides manual add and remove controls');
check($xpath->query('//input[@name="work_items[w_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa][description]"]')->item(0)->getAttribute('value') === '<script>MY-WORK</script>', 'work activity input retains escaped operator text');
check($xpath->query('//input[@name="work_items[w_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa][months]" and @inputmode="numeric"]')->item(0)->getAttribute('value') === 'invalid', 'work activity months use a numeric keyboard hint and retain invalid input');
check($xpath->query('//button[@id="eds-add-work-item"]')->length === 1 && $xpath->query('//*[@data-eds-remove-work]')->length >= 1, 'editable form provides work activity add and remove controls');
check($xpath->query('//input[@type="checkbox" and @name="work_enabled"]')->length === 0 && $xpath->query('//textarea[@name="work_description"]')->length === 0, 'legacy work checkbox and textarea are absent');
check($xpath->query('//form[@id="eds-delete-draft-form"]')->length === 1 && str_contains($html, 'Сигурни ли сте, че искате да изтриете черновата?'), 'saved editable draft provides confirmed deletion action');
check($xpath->query('//form//form')->length === 0 && $xpath->query('//form')->length === 2, 'save and delete forms are separate and never nested');
check($xpath->query('//button[@name="draft_action" and @value="save" and @form="eds-warranty-card-draft-form"]')->length === 1
    && $xpath->query('//button[@name="draft_action" and @value="save_and_review" and @form="eds-warranty-card-draft-form"]')->length === 1, 'editable draft has save and save-to-review actions attached to the main form');
$unsavedHtml = $render(true, false);
$unsavedDom = new DOMDocument();
@$unsavedDom->loadHTML('<?xml encoding="UTF-8">' . $unsavedHtml);
$unsavedXpath = new DOMXPath($unsavedDom);
check($unsavedXpath->query('//form[@id="eds-delete-draft-form"]')->length === 0
    && $unsavedXpath->query('//button[@name="draft_action"]')->length === 2, 'unsaved draft has both save actions and no deletion action');
check(!str_contains($html, '<script>MY-WORK</script>'), 'operator text cannot execute');
check(str_contains($render(false), '<fieldset disabled>'), 'limited view disables editing');
check(!str_contains($render(false), 'type="submit"'), 'limited view has no save button');
$limitedHtml = $render(false);
$limitedDom = new DOMDocument();
@$limitedDom->loadHTML('<?xml encoding="UTF-8">' . $limitedHtml);
$limitedXpath = new DOMXPath($limitedDom);
check($limitedXpath->query('//button[@id="eds-add-manual-part"]')->length === 0, 'limited view has no manual add control');
check($limitedXpath->query('//button[@id="eds-add-work-item"]')->length === 0 && $limitedXpath->query('//*[@data-eds-remove-work]')->length === 0, 'limited view has no work activity add or remove controls');
check($limitedXpath->query('//form[@id="eds-delete-draft-form"]')->length === 0 && $limitedXpath->query('//button[@name="draft_action"]')->length === 0, 'limited view has no draft save or deletion actions');
unlink($fixtureRoot . '/views/layout.php');
rmdir($fixtureRoot . '/views');
rmdir($fixtureRoot);
