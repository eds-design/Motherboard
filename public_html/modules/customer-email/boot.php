<?php
require_once $definition['path'] . '/lib.php';
require_once $definition['path'] . '/schema.php';
require_once ROOT_PATH . '/models/Settings.php';
require_once ROOT_PATH . '/models/WorkOrder.php';

motherboard_customer_email_load_models();

$customerEmailPath = $definition['path'];
$customerEmailController = $definition['path'] . '/controllers/CustomerEmailController.php';

Hooks::addAction('app.ready', function ($router, Database $database) {
    motherboard_customer_email_ensure_schema($database);
});

Hooks::addAction('router.register', function (Router $router) use ($customerEmailController): void {
    $router->addRoute('/module-manager/customer-email/preview', 'CustomerEmailController', 'preview', $customerEmailController);
    $router->addRoute('/work-orders/view/{id}/customer-email', 'CustomerEmailController', 'toggle', $customerEmailController);
});

Hooks::addAction('work_order.view.after_customer_info', function (array $workOrder, array $context) use ($customerEmailPath): void {
    $workOrderId = (int) ($workOrder['id'] ?? 0);
    if ($workOrderId <= 0) {
        return;
    }
    $hasAddress = filter_var(trim((string) ($workOrder['customer_email'] ?? '')), FILTER_VALIDATE_EMAIL) !== false;
    $disabled = (new CustomerEmailOptOut())->isDisabled($workOrderId);
    $canEdit = !empty($context['canEdit']);
    $csrf_token = $context['csrf_token'] ?? '';
    include $customerEmailPath . '/views/work-order-section.php';
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
    foreach (MOTHERBOARD_CUSTOMER_EMAIL_EVENTS as $event => $key) {
        $settings->setSetting($key, isset($post[$key]) ? '1' : '0');

        $templateKey = motherboard_customer_email_template_key($event);
        if (!array_key_exists($templateKey, $post)) {
            continue;
        }

        // Wording that still matches the shipped message is stored as empty, so an untouched
        // email keeps following the language the shop is reading it in.
        $template = motherboard_customer_email_clean_template((string) $post[$templateKey]);
        if ($template === motherboard_customer_email_clean_template(motherboard_customer_email_default_template($event))) {
            $template = '';
        }
        $settings->setSetting($templateKey, $template);
    }
    return $result;
});
