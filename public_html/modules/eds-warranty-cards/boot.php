<?php
require_once $definition['path'] . '/schema.php';
require_once $definition['path'] . '/models/NumberCounter.php';
require_once $definition['path'] . '/models/Terms.php';
require_once $definition['path'] . '/models/Issuer.php';
require_once $definition['path'] . '/models/DraftLimits.php';
require_once $definition['path'] . '/models/Draft.php';
require_once $definition['path'] . '/models/IssuedCard.php';
require_once $definition['path'] . '/models/Registry.php';

Hooks::addAction('router.register', function (Router $router) use ($definition): void {
    $router->addRoute('/eds-warranty-cards', 'EdsWarrantyCardRegistryController', 'index', $definition['path'] . '/controllers/RegistryController.php');
    $router->addRoute('/eds-warranty-cards/assets/{file}', 'EdsWarrantyCardAssetController', 'show', $definition['path'] . '/controllers/AssetController.php');
    $router->addRoute('/work-orders/view/{id}/eds-warranty-card/draft', 'EdsWarrantyCardDraftController', 'edit', $definition['path'] . '/controllers/DraftController.php');
    $router->addRoute('/work-orders/view/{id}/eds-warranty-card/draft/delete', 'EdsWarrantyCardDraftController', 'delete', $definition['path'] . '/controllers/DraftController.php');
    $router->addRoute('/work-orders/view/{id}/eds-warranty-card', 'EdsWarrantyCardController', 'show', $definition['path'] . '/controllers/CardController.php');
    $router->addRoute('/work-orders/view/{id}/eds-warranty-card/print', 'EdsWarrantyCardPrintController', 'show', $definition['path'] . '/controllers/PrintController.php');
});

Hooks::addAction('layout.nav', function (): void {
    if (!in_array($_SESSION['user_group'] ?? '', ['Admin', 'Technician'], true)) {
        return;
    }
    $workOrdersUrl = BASE_URL . '/work-orders';
    $assetUrl = BASE_URL . '/eds-warranty-cards/assets/warranty-card-nav.js?v=0.11.1';
    echo '<a id="eds-warranty-cards-nav-link" href="' . eds_warranty_cards_escape(BASE_URL . '/eds-warranty-cards') . '" data-work-orders-url="' . eds_warranty_cards_escape($workOrdersUrl) . '" class="block py-2 text-gray-600 hover:text-gray-900 md:inline-flex md:py-2 md:px-4">'
        . eds_warranty_cards_escape(t('eds_warranty_cards.registry_nav'))
        . '</a>'
        . '<script defer src="' . eds_warranty_cards_escape($assetUrl) . '"></script>';
});

Hooks::addAction('work_order.view.after_customer_info', function (array $workOrder, array $context) use ($definition): void {
    $database = new Database();
    $draft = (new EdsWarrantyCardDraft($database->connect()))->get((int) $workOrder['id']);
    $card = (new EdsWarrantyCardIssued($database->connect()))->get((int) $workOrder['id']);
    $canEditDraft = !empty($context['canEdit']) && in_array($_SESSION['user_group'] ?? '', ['Admin', 'Technician'], true);
    include $definition['path'] . '/views/work-order-section.php';
}, 20);

Hooks::addAction('work_order.delete.before', function (int $id): void {
    $database = new Database();
    if (!(new EdsWarrantyCardIssued($database->connect()))->get($id)) {
        return;
    }
    $_SESSION['flash']['error'] = t('eds_warranty_cards.delete_blocked');
    Controller::sendRedirect('/work-orders/view/' . $id);
});

Hooks::addFilter('schema.needs_migration', function (bool $needs, Database $database): bool {
    return $needs || eds_warranty_cards_needs_migration($database->connect());
});

Hooks::addAction('schema.migrate', function (Database $database): void {
    eds_warranty_cards_migrate($database->connect());
});

Hooks::addFilter('module.settings.save.eds-warranty-cards', function (array $result, array $post, Settings $settings): array {
    if (!empty($result['error'])) {
        return $result;
    }
    try {
        $terms = EdsWarrantyCardTerms::sanitizeHtml($post['eds_warranty_cards_terms_html'] ?? '');
        $issuer = EdsWarrantyCardIssuer::fromPost($post);
        $database = new Database();
        (new EdsWarrantyCardCounter($database->connect()))->advanceTo(
            $post['eds_warranty_cards_next_number'] ?? null,
            static function (PDO $pdo) use ($terms, $issuer): void {
                EdsWarrantyCardTerms::write($pdo, $terms);
                EdsWarrantyCardIssuer::write($pdo, $issuer);
            }
        );
        $result['message'] = t('eds_warranty_cards.settings_saved');
    } catch (InvalidArgumentException $error) {
        $result['ok'] = false;
        $result['error'] = $error->getMessage();
    } catch (Throwable $error) {
        error_log('EDS warranty card settings save failed: ' . $error->getMessage());
        $result['ok'] = false;
        $result['error'] = t('eds_warranty_cards.save_failed');
    }
    return $result;
});

Hooks::addFilter('view.data', function (array $data, string $viewName): array {
    if ($viewName === 'module-settings/eds-warranty-cards') {
        $database = new Database();
        $pdo = $database->connect();
        $data['edsWarrantyCardsNextNumber'] = (new EdsWarrantyCardCounter($pdo))->getNextNumber();
        $data['edsWarrantyCardsTermsHtml'] = EdsWarrantyCardTerms::read($pdo);
        $data['edsWarrantyCardsIssuer'] = EdsWarrantyCardIssuer::read($pdo);
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($data['error'])) {
            if (is_string($_POST['eds_warranty_cards_terms_html'] ?? null)) {
                $data['edsWarrantyCardsTermsHtml'] = $_POST['eds_warranty_cards_terms_html'];
            }
            $data['edsWarrantyCardsIssuer'] = EdsWarrantyCardIssuer::submittedForForm($_POST);
        }
    }
    return $data;
});
