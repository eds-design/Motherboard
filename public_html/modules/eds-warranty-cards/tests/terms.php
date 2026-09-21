<?php
if (PHP_SAPI !== 'cli' || !isset($checks)) { exit; }

$attacks = [
    '<script>alert(1)</script>' => '',
    '<img src=x onerror=alert(1)>' => '',
    '<p onclick="alert(1)">Текст</p>' => '<p>Текст</p>',
    '<svg onload=alert(1)></svg>' => '',
    '<a href="javascript:alert(1)">Връзка</a>' => 'Връзка',
    '<style>body{display:none}</style>' => '',
    '<iframe srcdoc="<script>alert(1)</script>"></iframe>' => '',
    '<object data="javascript:alert(1)"></object>' => '',
    '<p style="color:red" class="x" data-test="1">Текст</p>' => '<p>Текст</p>',
];
foreach ($attacks as $input => $expected) {
    check(EdsWarrantyCardTerms::sanitizeHtml($input) === $expected, 'strict terms attack sanitization');
}

$allowed = EdsWarrantyCardTerms::sanitizeHtml('<p>Абзац<br><b>Получер</b> и <strong title="x">силен</strong></p><!-- comment --><ul class="x"><li>Едно</li></ul><ol><li data-x="1">Две</li></ol>');
check($allowed === '<p>Абзац<br><strong>Получер</strong> и <strong>силен</strong></p><ul><li>Едно</li></ul><ol><li>Две</li></ol>', 'allowed structure preserved and b normalized');
check(EdsWarrantyCardTerms::sanitizeHtml('<div><em>Безопасен <span>форматиращ текст</span></em></div>') === 'Безопасен форматиращ текст', 'safe unsupported formatting is unwrapped');
check(EdsWarrantyCardTerms::sanitizeHtml('<p></p><p><br></p><!--x--><br>') === '', 'empty content normalized');
check(EdsWarrantyCardTerms::sanitizeHtml($allowed) === $allowed, 'terms sanitization is idempotent');
check(EdsWarrantyCardTerms::sanitizePlain("Ред 1\nРед 2") === 'Ред 1<br>Ред 2', 'plain fallback preserves new line as br');
check(EdsWarrantyCardTerms::toPlainText('<p>Абзац<br><strong>текст</strong></p><ul><li>Точка</li></ul>') === "Абзац\nтекст\n- Точка", 'safe HTML has accessible plain fallback');

$twentyThousand = str_repeat('я', 20000);
check(EdsWarrantyCardTerms::sanitizeHtml($twentyThousand) === $twentyThousand, '20000 UTF-8 characters accepted');
rejects(fn() => EdsWarrantyCardTerms::sanitizeHtml($twentyThousand . 'я'), t('eds_warranty_cards.terms_too_long', ['max'=>20000]));
rejects(fn() => EdsWarrantyCardTerms::sanitizeHtml("\xC3\x28"), t('eds_warranty_cards.terms_invalid_utf8'));
check(EdsWarrantyCardTerms::forDisplay("\xC3\x28") === '', 'invalid stored UTF-8 fails closed on display');

$mixed = EdsWarrantyCardTerms::sanitizeHtml('<p id=x>Safe<script>bad()</script><a href=x> link</a></p><math><mtext>hidden</mtext></math>');
check($mixed === '<p>Safe link</p>', 'dangerous subtrees removed while adjacent safe text remains');
check(!preg_match('/<(?!\/?(?:p|br|strong|ul|ol|li)(?:\s|>|\/))/i', $allowed . $mixed), 'sanitized output contains only allowlisted tags');
check(!preg_match('/<[^>]+\s+[a-z_:][-a-z0-9_:.]*\s*=/i', $allowed . $mixed), 'sanitized output contains no attributes');
