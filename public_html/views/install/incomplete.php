<?php
$title = t('install.database_problem_title');
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(I18n::getInstance()->getLocale()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title) ?></title>
    <link rel="stylesheet" href="<?= htmlspecialchars(BASE_URL) ?>/assets/app.css">
</head>
<body class="min-h-screen bg-gray-100 text-gray-900">
    <main class="min-h-screen flex items-center justify-center px-4 py-12">
        <div class="w-full max-w-2xl rounded-lg border border-amber-200 bg-amber-50 p-8">
            <h1 class="text-2xl font-bold text-amber-950"><?= htmlspecialchars(t('install.database_problem_title')) ?></h1>
            <p class="mt-4 text-amber-900">
                <?= htmlspecialchars($databaseUnavailable
                    ? t('install.database_unavailable_help')
                    : t('install.database_not_empty_help')) ?>
            </p>
            <?php if (!$databaseUnavailable): ?>
                <p class="mt-3 text-sm text-amber-800"><?= htmlspecialchars(t('install.database_not_empty_action')) ?></p>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>
