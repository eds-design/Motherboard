<?php

function eds_warranty_cards_escape(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function eds_warranty_cards_format_months(string $months): string {
    $key = preg_match('/\A0*1\z/', $months) === 1
        ? 'eds_warranty_cards.document_month_one'
        : 'eds_warranty_cards.document_month_many';
    return t($key, ['months' => $months]);
}

final class EdsWarrantyCardNumber {
    // Validate without changing the user-selected decimal format.
    public static function validate(mixed $value): string {
        if (!is_string($value) || !preg_match('/\A[0-9]+\z/', $value)) {
            throw new InvalidArgumentException(t('eds_warranty_cards.invalid_number'));
        }
        if (ltrim($value, '0') === '') {
            throw new InvalidArgumentException(t('eds_warranty_cards.invalid_number'));
        }
        return $value;
    }

    public static function compare(string $left, string $right): int {
        $left = self::numericValue($left);
        $right = self::numericValue($right);
        $length = strlen($left) <=> strlen($right);
        return $length !== 0 ? $length : (strcmp($left, $right) <=> 0);
    }

    public static function numericValue(string $number): string {
        // Strip zeros only for comparison/identity; stored/issued values retain them.
        return ltrim(self::validate($number), '0');
    }

    public static function identityKey(string $number): string {
        return hash('sha256', self::numericValue($number), true);
    }

    public static function increment(string $number): string {
        $number = self::validate($number);
        for ($i = strlen($number) - 1; $i >= 0; $i--) {
            if ($number[$i] !== '9') {
                $number[$i] = chr(ord($number[$i]) + 1);
                return $number;
            }
            $number[$i] = '0';
        }
        return '1' . $number;
    }
}
