<?php
$title = t('module.eds-warranty-cards.name') . ' - ' . ($companyName ?? APP_NAME);
$assetBase = BASE_URL . '/eds-warranty-cards/assets';
ob_start();
?>
<link id="eds-warranty-cards-quill-stylesheet" rel="stylesheet" href="<?= htmlspecialchars($assetBase . '/quill.snow.css', ENT_QUOTES, 'UTF-8') ?>">
<link rel="stylesheet" href="<?= htmlspecialchars($assetBase . '/warranty-terms-editor.css', ENT_QUOTES, 'UTF-8') ?>">

<div class="py-8">
    <div class="mb-6 sm:flex sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900"><?= htmlspecialchars(t('module.eds-warranty-cards.name'), ENT_QUOTES, 'UTF-8') ?></h1>
            <p class="mt-1 text-sm text-gray-600"><?= htmlspecialchars(t('module.eds-warranty-cards.description'), ENT_QUOTES, 'UTF-8') ?></p>
        </div>
        <a href="<?= htmlspecialchars(BASE_URL . '/settings?tab=modules', ENT_QUOTES, 'UTF-8') ?>" class="mt-4 sm:mt-0 inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50"><?= htmlspecialchars(t('modules.back'), ENT_QUOTES, 'UTF-8') ?></a>
    </div>
    <?php if (!empty($error)): ?>
        <div class="mb-6 bg-red-50 border border-red-200 rounded-md p-4"><p class="text-sm text-red-600" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p></div>
    <?php endif; ?>
    <?php if (!empty($message)): ?>
        <div class="mb-6 bg-green-50 border border-green-200 rounded-md p-4"><p class="text-sm text-green-600"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p></div>
    <?php endif; ?>
    <div class="bg-white shadow rounded-lg">
        <form method="POST" action="<?= htmlspecialchars(BASE_URL . '/module-manager/eds-warranty-cards/settings', ENT_QUOTES, 'UTF-8') ?>" class="px-6 py-4 space-y-6">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
            <section aria-labelledby="eds-warranty-cards-issuer-heading">
                <h2 id="eds-warranty-cards-issuer-heading" class="text-lg font-medium text-gray-900"><?= htmlspecialchars(t('eds_warranty_cards.issuer_details'), ENT_QUOTES, 'UTF-8') ?></h2>
                <div class="mt-4 grid grid-cols-1 gap-6 sm:grid-cols-2">
                    <div>
                        <label for="eds_warranty_cards_issuer_service_name" class="block text-sm font-medium text-gray-700"><?= htmlspecialchars(t('eds_warranty_cards.issuer_service_name'), ENT_QUOTES, 'UTF-8') ?></label>
                        <input type="text" maxlength="255" id="eds_warranty_cards_issuer_service_name" name="eds_warranty_cards_issuer_service_name" value="<?= htmlspecialchars($edsWarrantyCardsIssuer['service_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" aria-describedby="eds-warranty-cards-issuer-service-name-help" class="mt-1 block w-full px-4 py-3 border-2 border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm bg-white">
                        <p id="eds-warranty-cards-issuer-service-name-help" class="mt-1 text-sm text-gray-500"><?= htmlspecialchars(t('eds_warranty_cards.issuer_service_name_help'), ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                    <div>
                        <label for="eds_warranty_cards_issuer_legal_name" class="block text-sm font-medium text-gray-700"><?= htmlspecialchars(t('eds_warranty_cards.issuer_legal_name'), ENT_QUOTES, 'UTF-8') ?></label>
                        <input type="text" maxlength="255" id="eds_warranty_cards_issuer_legal_name" name="eds_warranty_cards_issuer_legal_name" value="<?= htmlspecialchars($edsWarrantyCardsIssuer['legal_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="mt-1 block w-full px-4 py-3 border-2 border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm bg-white">
                    </div>
                    <div>
                        <label for="eds_warranty_cards_issuer_registration_number" class="block text-sm font-medium text-gray-700"><?= htmlspecialchars(t('eds_warranty_cards.issuer_registration_number'), ENT_QUOTES, 'UTF-8') ?></label>
                        <input type="text" maxlength="100" id="eds_warranty_cards_issuer_registration_number" name="eds_warranty_cards_issuer_registration_number" value="<?= htmlspecialchars($edsWarrantyCardsIssuer['registration_number'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="mt-1 block w-full px-4 py-3 border-2 border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm bg-white">
                    </div>
                    <div>
                        <label for="eds_warranty_cards_issuer_representative" class="block text-sm font-medium text-gray-700"><?= htmlspecialchars(t('eds_warranty_cards.issuer_representative'), ENT_QUOTES, 'UTF-8') ?></label>
                        <input type="text" maxlength="255" id="eds_warranty_cards_issuer_representative" name="eds_warranty_cards_issuer_representative" value="<?= htmlspecialchars($edsWarrantyCardsIssuer['representative'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="mt-1 block w-full px-4 py-3 border-2 border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm bg-white">
                    </div>
                    <div class="sm:col-span-2">
                        <label for="eds_warranty_cards_issuer_address" class="block text-sm font-medium text-gray-700"><?= htmlspecialchars(t('eds_warranty_cards.issuer_address'), ENT_QUOTES, 'UTF-8') ?></label>
                        <textarea rows="4" maxlength="2000" id="eds_warranty_cards_issuer_address" name="eds_warranty_cards_issuer_address" aria-describedby="eds-warranty-cards-issuer-address-help" class="mt-1 block w-full px-4 py-3 border-2 border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm bg-white"><?= htmlspecialchars($edsWarrantyCardsIssuer['address'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
                        <p id="eds-warranty-cards-issuer-address-help" class="mt-1 text-sm text-gray-500"><?= htmlspecialchars(t('eds_warranty_cards.issuer_address_help'), ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                    <div>
                        <label for="eds_warranty_cards_issuer_phone" class="block text-sm font-medium text-gray-700"><?= htmlspecialchars(t('eds_warranty_cards.issuer_phone'), ENT_QUOTES, 'UTF-8') ?></label>
                        <input type="text" maxlength="100" id="eds_warranty_cards_issuer_phone" name="eds_warranty_cards_issuer_phone" value="<?= htmlspecialchars($edsWarrantyCardsIssuer['phone'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" aria-describedby="eds-warranty-cards-issuer-phone-help" data-no-auto-format class="mt-1 block w-full px-4 py-3 border-2 border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm bg-white">
                        <p id="eds-warranty-cards-issuer-phone-help" class="mt-1 text-sm text-gray-500"><?= htmlspecialchars(t('eds_warranty_cards.issuer_phone_help'), ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                    <div>
                        <label for="eds_warranty_cards_issuer_email" class="block text-sm font-medium text-gray-700"><?= htmlspecialchars(t('eds_warranty_cards.issuer_email'), ENT_QUOTES, 'UTF-8') ?></label>
                        <input type="text" inputmode="email" maxlength="254" id="eds_warranty_cards_issuer_email" name="eds_warranty_cards_issuer_email" value="<?= htmlspecialchars($edsWarrantyCardsIssuer['email'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" aria-describedby="eds-warranty-cards-issuer-email-help" class="mt-1 block w-full px-4 py-3 border-2 border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm bg-white">
                        <p id="eds-warranty-cards-issuer-email-help" class="mt-1 text-sm text-gray-500"><?= htmlspecialchars(t('eds_warranty_cards.issuer_email_help'), ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                    <div>
                        <label for="eds_warranty_cards_issuer_website" class="block text-sm font-medium text-gray-700"><?= htmlspecialchars(t('eds_warranty_cards.issuer_website'), ENT_QUOTES, 'UTF-8') ?></label>
                        <input type="text" inputmode="url" maxlength="2048" id="eds_warranty_cards_issuer_website" name="eds_warranty_cards_issuer_website" value="<?= htmlspecialchars($edsWarrantyCardsIssuer['website'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" aria-describedby="eds-warranty-cards-issuer-website-help" class="mt-1 block w-full px-4 py-3 border-2 border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm bg-white">
                        <p id="eds-warranty-cards-issuer-website-help" class="mt-1 text-sm text-gray-500"><?= htmlspecialchars(t('eds_warranty_cards.issuer_website_help'), ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                    <div>
                        <label for="eds_warranty_cards_issuer_logo_url" class="block text-sm font-medium text-gray-700"><?= htmlspecialchars(t('eds_warranty_cards.issuer_logo_url'), ENT_QUOTES, 'UTF-8') ?></label>
                        <input type="text" inputmode="url" maxlength="2048" id="eds_warranty_cards_issuer_logo_url" name="eds_warranty_cards_issuer_logo_url" value="<?= htmlspecialchars($edsWarrantyCardsIssuer['logo_url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" aria-describedby="eds-warranty-cards-issuer-logo-help" class="mt-1 block w-full px-4 py-3 border-2 border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm bg-white">
                        <p id="eds-warranty-cards-issuer-logo-help" class="mt-1 text-sm text-gray-500"><?= htmlspecialchars(t('eds_warranty_cards.issuer_logo_url_help'), ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                </div>
            </section>
            <section class="border-t border-gray-200 pt-6" aria-labelledby="eds-warranty-cards-number-heading">
                <h2 id="eds-warranty-cards-number-heading" class="text-lg font-medium text-gray-900"><label for="eds_warranty_cards_next_number"><?= htmlspecialchars(t('eds_warranty_cards.next_number'), ENT_QUOTES, 'UTF-8') ?></label></h2>
                <div class="mt-1 max-w-xs">
                    <input type="text" inputmode="numeric" pattern="[0-9]*[1-9][0-9]*" required id="eds_warranty_cards_next_number" name="eds_warranty_cards_next_number" value="<?= htmlspecialchars($edsWarrantyCardsNextNumber, ENT_QUOTES, 'UTF-8') ?>" aria-describedby="eds-warranty-cards-number-help" class="block w-full px-4 py-3 border-2 border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm bg-white">
                </div>
                <p id="eds-warranty-cards-number-help" class="mt-1 text-sm text-gray-500"><?= htmlspecialchars(t('eds_warranty_cards.next_number_help'), ENT_QUOTES, 'UTF-8') ?></p>
            </section>
            <section class="border-t border-gray-200 pt-6" aria-labelledby="eds-warranty-cards-terms-heading">
                <h2 id="eds-warranty-cards-terms-heading" class="text-lg font-medium text-gray-900"><label id="eds-warranty-cards-terms-label" for="eds_warranty_cards_terms_html"><?= htmlspecialchars(t('eds_warranty_cards.terms'), ENT_QUOTES, 'UTF-8') ?></label></h2>
                <p id="eds-warranty-cards-terms-help" class="mt-1 text-sm text-gray-500"><?= htmlspecialchars(t('eds_warranty_cards.terms_help'), ENT_QUOTES, 'UTF-8') ?></p>

                <textarea id="eds_warranty_cards_terms_html" name="eds_warranty_cards_terms_html" rows="12" aria-describedby="eds-warranty-cards-terms-help" class="mt-3 block w-full px-4 py-3 border-2 border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm bg-white"><?= htmlspecialchars($edsWarrantyCardsTermsHtml, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>

                <div id="eds-warranty-cards-quill" class="eds-warranty-quill" hidden>
                    <div id="eds-warranty-cards-quill-toolbar" role="toolbar" aria-label="<?= htmlspecialchars(t('eds_warranty_cards.terms_toolbar'), ENT_QUOTES, 'UTF-8') ?>">
                        <span class="ql-formats">
                            <button type="button" class="ql-bold" aria-label="<?= htmlspecialchars(t('eds_warranty_cards.terms_bold'), ENT_QUOTES, 'UTF-8') ?>" title="<?= htmlspecialchars(t('eds_warranty_cards.terms_bold'), ENT_QUOTES, 'UTF-8') ?>"></button>
                            <button type="button" class="ql-list" value="bullet" aria-label="<?= htmlspecialchars(t('eds_warranty_cards.terms_bulleted_list'), ENT_QUOTES, 'UTF-8') ?>" title="<?= htmlspecialchars(t('eds_warranty_cards.terms_bulleted_list'), ENT_QUOTES, 'UTF-8') ?>"></button>
                            <button type="button" class="ql-list" value="ordered" aria-label="<?= htmlspecialchars(t('eds_warranty_cards.terms_numbered_list'), ENT_QUOTES, 'UTF-8') ?>" title="<?= htmlspecialchars(t('eds_warranty_cards.terms_numbered_list'), ENT_QUOTES, 'UTF-8') ?>"></button>
                        </span>
                    </div>
                    <div id="eds-warranty-cards-quill-editor" aria-labelledby="eds-warranty-cards-terms-label" aria-describedby="eds-warranty-cards-terms-help"></div>
                </div>
            </section>
            <div class="flex justify-end">
                <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-primary-600 hover:bg-primary-700"><?= htmlspecialchars(t('common.save'), ENT_QUOTES, 'UTF-8') ?></button>
            </div>
        </form>
    </div>
</div>
<script src="<?= htmlspecialchars($assetBase . '/quill.js', ENT_QUOTES, 'UTF-8') ?>"></script>
<script src="<?= htmlspecialchars($assetBase . '/warranty-terms-editor.js', ENT_QUOTES, 'UTF-8') ?>"></script>
<?php
$content = ob_get_clean();
include ROOT_PATH . '/views/layout.php';
