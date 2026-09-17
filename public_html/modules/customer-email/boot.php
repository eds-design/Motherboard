<?php
require_once $definition['path'] . '/lib.php';
require_once $definition['path'] . '/schema.php';
require_once ROOT_PATH . '/models/Settings.php';
require_once ROOT_PATH . '/models/WorkOrder.php';

motherboard_customer_email_load_models();

$customerEmailController = $definition['path'] . '/controllers/CustomerEmailController.php';

Hooks::addAction('app.ready', function ($router, Database $database) {
    motherboard_customer_email_ensure_schema($database);
});

Hooks::addAction('router.register', function (Router $router) use ($customerEmailController): void {
    $router->addRoute('/module-manager/customer-email/preview', 'CustomerEmailController', 'preview', $customerEmailController);
});

// Runs after other create.after handlers so the email reflects anything they persisted.
Hooks::addAction('work_order.create.after', function ($workOrderId): void {
    motherboard_customer_email_notify((int) $workOrderId, 'created');
}, 20);

Hooks::addAction('work_order.status.changed', function (int $workOrderId, string $oldStatus, string $newStatus): void {
    $event = motherboard_customer_email_event_for_status($newStatus);
    if ($event !== null) {
        motherboard_customer_email_notify($workOrderId, $event);
    }
});

Hooks::addFilter('module.settings.save.customer-email', function (array $result, array $post, Settings $settings): array {
    foreach (MOTHERBOARD_CUSTOMER_EMAIL_EVENTS as $key) {
        $settings->setSetting($key, isset($post[$key]) ? '1' : '0');
    }
    return $result;
});
