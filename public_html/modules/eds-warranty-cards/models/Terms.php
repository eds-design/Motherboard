<?php

final class EdsWarrantyCardTerms {
    public const MAX_CHARACTERS = 20000;

    private const ALLOWED = ['p', 'br', 'strong', 'ul', 'ol', 'li'];
    private const DROP_WITH_CONTENT = [
        'script', 'style', 'iframe', 'object', 'embed', 'svg', 'math', 'template', 'noscript',
        'link', 'meta', 'base', 'form', 'input', 'button', 'textarea', 'select', 'option',
        'optgroup', 'audio', 'video', 'source', 'track', 'canvas', 'picture', 'frame',
        'frameset', 'applet', 'portal', 'xmp', 'plaintext',
    ];

    public static function sanitizeHtml(mixed $value): string {
        if (!is_string($value) || preg_match('//u', $value) !== 1) {
            throw new InvalidArgumentException(t('eds_warranty_cards.terms_invalid_utf8'));
        }
        if (trim($value) === '') {
            return '';
        }

        try {
            $document = \Dom\HTMLDocument::createFromString($value, LIBXML_NOERROR | LIBXML_COMPACT);
        } catch (Throwable) {
            throw new InvalidArgumentException(t('eds_warranty_cards.terms_invalid_utf8'));
        }
        $body = $document->body;
        foreach (iterator_to_array($body->childNodes) as $node) {
            self::cleanNode($document, $node);
        }
        self::removeEmptyElements($body);
        self::trimContainer($body);

        $html = '';
        foreach ($body->childNodes as $node) {
            $html .= $document->saveHtml($node);
        }
        if (self::characterCount($body) > self::MAX_CHARACTERS) {
            throw new InvalidArgumentException(t('eds_warranty_cards.terms_too_long', ['max' => self::MAX_CHARACTERS]));
        }
        return $html;
    }

    public static function sanitizePlain(mixed $value): string {
        if (!is_string($value) || preg_match('//u', $value) !== 1) {
            throw new InvalidArgumentException(t('eds_warranty_cards.terms_invalid_utf8'));
        }
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $escaped = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return self::sanitizeHtml(str_replace("\n", '<br>', $escaped));
    }

    public static function forDisplay(mixed $value): string {
        try {
            return self::sanitizeHtml($value);
        } catch (InvalidArgumentException) {
            return '';
        }
    }

    public static function read(PDO $pdo, bool $lock = false): string {
        $stmt = $pdo->query('SELECT terms_html FROM eds_warranty_cards_settings WHERE id = 1' . ($lock ? ' FOR UPDATE' : ''));
        $value = $stmt->fetchColumn();
        return self::forDisplay(is_string($value) ? $value : '');
    }

    public static function write(PDO $pdo, string $sanitized): void {
        $stmt = $pdo->prepare("INSERT INTO eds_warranty_cards_settings (id, terms_html, updated_at) VALUES (1, ?, NOW()) ON DUPLICATE KEY UPDATE terms_html = VALUES(terms_html), updated_at = NOW()");
        $stmt->execute([$sanitized]);
    }

    public static function toPlainText(mixed $value): string {
        $html = self::forDisplay($value);
        if ($html === '') {
            return '';
        }
        $document = \Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR | LIBXML_COMPACT);
        $text = self::plainText($document->body);
        $text = preg_replace('/[\t ]+\n/u', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;
        return trim($text);
    }

    private static function cleanNode(\Dom\HTMLDocument $document, object $node): void {
        if ($node->nodeType === XML_COMMENT_NODE) {
            $node->remove();
            return;
        }
        if ($node->nodeType !== XML_ELEMENT_NODE) {
            return;
        }

        $tag = strtolower((string) $node->localName);
        if (in_array($tag, self::DROP_WITH_CONTENT, true)) {
            $node->remove();
            return;
        }

        if ($tag === 'b') {
            $strong = $document->createElement('strong');
            while ($node->firstChild !== null) {
                $strong->appendChild($node->firstChild);
            }
            $node->replaceWith($strong);
            $node = $strong;
            $tag = 'strong';
        }

        foreach (iterator_to_array($node->childNodes) as $child) {
            self::cleanNode($document, $child);
        }

        if (!in_array($tag, self::ALLOWED, true)) {
            $parent = $node->parentNode;
            if ($parent === null) {
                return;
            }
            while ($node->firstChild !== null) {
                $parent->insertBefore($node->firstChild, $node);
            }
            $node->remove();
            return;
        }

        foreach (iterator_to_array($node->attributes) as $attribute) {
            $node->removeAttribute($attribute->name);
        }
    }

    private static function removeEmptyElements(object $container): void {
        foreach (iterator_to_array($container->childNodes) as $child) {
            if ($child->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }
            self::removeEmptyElements($child);
            $tag = strtolower((string) $child->localName);
            if ($tag !== 'br' && !self::hasMeaningfulContent($child)) {
                $child->remove();
            }
        }
    }

    private static function hasMeaningfulContent(object $node): bool {
        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE && trim(str_replace("\u{00A0}", ' ', (string) $child->nodeValue)) !== '') {
                return true;
            }
            if ($child->nodeType === XML_ELEMENT_NODE && strtolower((string) $child->localName) !== 'br') {
                return true;
            }
        }
        return false;
    }

    private static function trimContainer(object $container): void {
        while ($container->firstChild !== null && self::isDiscardableBoundary($container->firstChild)) {
            $container->firstChild->remove();
        }
        while ($container->lastChild !== null && self::isDiscardableBoundary($container->lastChild)) {
            $container->lastChild->remove();
        }
    }

    private static function isDiscardableBoundary(object $node): bool {
        return ($node->nodeType === XML_TEXT_NODE && trim((string) $node->nodeValue) === '')
            || ($node->nodeType === XML_ELEMENT_NODE && strtolower((string) $node->localName) === 'br');
    }

    private static function characterCount(object $body): int {
        $text = trim(self::plainText($body));
        $count = preg_match_all('/./us', $text, $matches);
        return $count === false ? self::MAX_CHARACTERS + 1 : $count;
    }

    private static function plainText(object $node): string {
        $text = '';
        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                $text .= (string) $child->nodeValue;
                continue;
            }
            if ($child->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }
            $tag = strtolower((string) $child->localName);
            if ($tag === 'br') {
                $text .= "\n";
                continue;
            }
            if ($tag === 'li') {
                $text .= '- ' . self::plainText($child) . "\n";
                continue;
            }
            $text .= self::plainText($child);
            if (in_array($tag, ['p', 'ul', 'ol'], true)) {
                $text .= "\n";
            }
        }
        return $text;
    }
}
