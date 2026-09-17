<?php
// Customer email template. Table layout and inline styles only, since most mail clients
// ignore <style> blocks and flexbox. Receives $email from motherboard_customer_email_render().
$e = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="<?= $e($email['lang']) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $e($email['heading']) ?></title>
</head>
<body style="margin:0; padding:0; background-color:#f3f4f6; font-family:-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color:#111827;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f3f4f6;">
    <tr>
        <td align="center" style="padding:32px 16px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px; background-color:#ffffff; border:1px solid #e5e7eb; border-radius:8px;">
                <tr>
                    <td style="padding:24px 32px; border-bottom:1px solid #e5e7eb;">
                        <span style="font-size:18px; font-weight:700; color:#111827;"><?= $e($email['company']) ?></span>
                    </td>
                </tr>
                <tr>
                    <td style="padding:32px;">
                        <h1 style="margin:0 0 24px; font-size:22px; line-height:1.3; font-weight:700; color:#111827;"><?= $e($email['heading']) ?></h1>
                        <p style="margin:0 0 16px; font-size:15px; line-height:1.6; color:#374151;"><?= $e($email['greeting']) ?></p>
                        <p style="margin:0 0 24px; font-size:15px; line-height:1.6; color:#374151;"><?= $e($email['body']) ?></p>

                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px; background-color:#f9fafb; border:1px solid #e5e7eb; border-radius:6px;">
                            <?php foreach ($email['details'] as $i => [$label, $value]): ?>
                            <tr>
                                <td style="padding:12px 16px; font-size:14px; color:#6b7280;<?= $i > 0 ? ' border-top:1px solid #e5e7eb;' : '' ?>"><?= $e($label) ?></td>
                                <td align="right" style="padding:12px 16px; font-size:14px; font-weight:600; color:#111827;<?= $i > 0 ? ' border-top:1px solid #e5e7eb;' : '' ?>"><?= $e($value) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </table>

                        <p style="margin:0 0 24px; font-size:15px; line-height:1.6; color:#374151;"><?= $e($email['questions']) ?></p>
                        <p style="margin:0; font-size:15px; line-height:1.6; color:#374151;"><?= $e($email['sign_off']) ?><br><strong style="color:#111827;"><?= $e($email['company']) ?></strong></p>
                    </td>
                </tr>
                <?php if ($email['address'] !== '' || $email['contact']): ?>
                <tr>
                    <td style="padding:20px 32px; border-top:1px solid #e5e7eb; font-size:13px; line-height:1.6; color:#6b7280;">
                        <?php if ($email['address'] !== ''): ?>
                            <?= nl2br($e($email['address'])) ?><br>
                        <?php endif; ?>
                        <?= implode(' &middot; ', array_map($e, $email['contact'])) ?>
                    </td>
                </tr>
                <?php endif; ?>
            </table>
            <p style="max-width:600px; margin:16px auto 0; font-size:12px; line-height:1.5; color:#9ca3af; text-align:center;"><?= $e($email['footer']) ?></p>
        </td>
    </tr>
</table>
</body>
</html>
