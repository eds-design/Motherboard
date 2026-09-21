<?php

final class EdsWarrantyCardDraftLimits {
    public const MANUAL_PART_NAME_LENGTH = 128;
    public const SERIAL_NUMBER_LENGTH = 128;
    public const SUPPLIER_LENGTH = 128;
    public const SUPPLIER_CARD_LENGTH = 128;
    public const WORK_DESCRIPTION_LENGTH = 250;
    public const MONTHS_DIGITS = 6;
    public const PARTS = 100;
    public const MANUAL_ROWS = 100;
    public const WORK_ITEMS = 50;
    public const INVENTORY_UNITS = 100;
    public const JSON_BYTES = 256 * 1024;

    private const FIELD_LIMITS = [
        'name' => [self::MANUAL_PART_NAME_LENGTH, 'eds_warranty_cards.part_name_too_long'],
        'serial_number' => [self::SERIAL_NUMBER_LENGTH, 'eds_warranty_cards.serial_number_too_long'],
        'supplier' => [self::SUPPLIER_LENGTH, 'eds_warranty_cards.supplier_too_long'],
        'supplier_card' => [self::SUPPLIER_CARD_LENGTH, 'eds_warranty_cards.supplier_card_too_long'],
        'description' => [self::WORK_DESCRIPTION_LENGTH, 'eds_warranty_cards.work_description_too_long'],
    ];

    public static function normalizeField(string $field, mixed $value): string {
        if (!is_string($value) || preg_match('//u', $value) !== 1) {
            throw new InvalidArgumentException(t('eds_warranty_cards.invalid_draft_text'));
        }
        if (preg_match('/[\r\n\x{2028}\x{2029}\p{Cc}\p{Cf}]/u', $value) === 1) {
            throw new InvalidArgumentException(t('eds_warranty_cards.invalid_draft_text'));
        }
        $value = preg_replace('/\A[\p{Z} ]+|[\p{Z} ]+\z/u', '', $value) ?? $value;
        if (isset(self::FIELD_LIMITS[$field])) {
            [$limit, $message] = self::FIELD_LIMITS[$field];
            if (mb_strlen($value, 'UTF-8') > $limit) {
                throw new InvalidArgumentException(t($message));
            }
        }
        return $value;
    }

    public static function normalizeMonths(mixed $value): string {
        $value = self::normalizeField('months', $value);
        if ($value === '') {
            return '';
        }
        if (preg_match('/\A[0-9]+\z/', $value) !== 1) {
            throw new InvalidArgumentException(t('eds_warranty_cards.invalid_months'));
        }
        if (strlen($value) > self::MONTHS_DIGITS) {
            throw new InvalidArgumentException(t('eds_warranty_cards.months_too_long'));
        }
        if (ltrim($value, '0') === '') {
            throw new InvalidArgumentException(t('eds_warranty_cards.invalid_months'));
        }
        return $value;
    }

    public static function normalizePartFields(array $part, bool $manual): array {
        $result = [];
        if ($manual) {
            $result['name'] = self::normalizeField('name', $part['name'] ?? '');
        }
        $result['serial_number'] = self::normalizeField('serial_number', $part['serial_number'] ?? '');
        $result['months'] = self::normalizeMonths($part['months'] ?? '');
        $result['supplier'] = self::normalizeField('supplier', $part['supplier'] ?? '');
        $result['supplier_card'] = self::normalizeField('supplier_card', $part['supplier_card'] ?? '');
        return $result;
    }

    public static function normalizeWorkItem(array $item): array {
        return [
            'description' => self::normalizeField('description', $item['description'] ?? ''),
            'months' => self::normalizeMonths($item['months'] ?? ''),
        ];
    }

    public static function assertRawCounts(array $units, array $manualParts, array $workItems): void {
        if (count($units) > self::INVENTORY_UNITS || count($manualParts) > self::MANUAL_ROWS) {
            throw new InvalidArgumentException(t('eds_warranty_cards.too_many_parts'));
        }
        if (count($workItems) > self::WORK_ITEMS) {
            throw new InvalidArgumentException(t('eds_warranty_cards.too_many_work_items'));
        }
    }

    public static function assertPartCount(int $selectedInventory, int $nonEmptyManual): void {
        if ($selectedInventory + $nonEmptyManual > self::PARTS) {
            throw new InvalidArgumentException(t('eds_warranty_cards.too_many_parts'));
        }
    }

    public static function inventoryUnitCount(array $lines): int {
        $total = 0;
        foreach ($lines as $line) {
            $quantity = $line['quantity'] ?? null;
            if (is_int($quantity)) {
                $normalized = (string) $quantity;
            } elseif (is_string($quantity)) {
                $normalized = $quantity;
            } else {
                throw new InvalidArgumentException(t('eds_warranty_cards.invalid_form'));
            }
            if (preg_match('/\A[0-9]+\z/', $normalized) !== 1) {
                throw new InvalidArgumentException(t('eds_warranty_cards.invalid_form'));
            }
            $normalized = ltrim($normalized, '0');
            if ($normalized === '') {
                continue;
            }
            if (strlen($normalized) > 3 || (int) $normalized > self::INVENTORY_UNITS - $total) {
                throw new InvalidArgumentException(t('eds_warranty_cards.too_many_inventory_units'));
            }
            $total += (int) $normalized;
        }
        return $total;
    }

    public static function encodeCanonical(array $content): string {
        $json = json_encode($content, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        self::assertJsonSize($json);
        return $json;
    }

    public static function assertJsonSize(string $json): void {
        if (strlen($json) > self::JSON_BYTES) {
            throw new InvalidArgumentException(t('eds_warranty_cards.draft_too_large'));
        }
    }

    public static function normalizeStoredForIssuance(array $content): array {
        $units = self::storedCollection($content, 'units');
        $manual = self::storedCollection($content, 'manual_parts');
        $work = self::storedCollection($content, 'work_items');
        self::assertRawCounts($units, $manual, $work);

        $normalizedUnits = [];
        $selected = 0;
        foreach ($units as $key => $part) {
            if (!is_array($part)) {
                throw new InvalidArgumentException(t('eds_warranty_cards.invalid_form'));
            }
            $selectedFlag = !empty($part['selected']);
            $name = self::normalizeField('inventory_name', $part['name'] ?? '');
            $normalizedUnits[(string) $key] = [
                'name' => $name,
                'source' => 'inventory',
                'selected' => $selectedFlag,
            ] + self::normalizePartFields($part, false);
            if ($selectedFlag) {
                $selected++;
            }
        }

        $normalizedManual = [];
        $nonEmptyManual = 0;
        foreach ($manual as $key => $part) {
            if (!is_array($part)) {
                throw new InvalidArgumentException(t('eds_warranty_cards.invalid_form'));
            }
            $normalized = ['source' => 'manual'] + self::normalizePartFields($part, true);
            $normalizedManual[(string) $key] = $normalized;
            if (!self::manualIsEmpty($normalized)) {
                $nonEmptyManual++;
            }
        }

        $normalizedWork = [];
        foreach ($work as $key => $item) {
            if (!is_array($item)) {
                throw new InvalidArgumentException(t('eds_warranty_cards.invalid_form'));
            }
            $normalizedWork[(string) $key] = self::normalizeWorkItem($item);
        }
        self::assertPartCount($selected, $nonEmptyManual);
        $normalized = ['units' => $normalizedUnits, 'manual_parts' => $normalizedManual, 'work_items' => $normalizedWork];
        self::encodeCanonical($normalized);
        return $normalized;
    }

    private static function manualIsEmpty(array $part): bool {
        foreach (['name', 'serial_number', 'months', 'supplier', 'supplier_card'] as $field) {
            if (($part[$field] ?? '') !== '') {
                return false;
            }
        }
        return true;
    }

    private static function storedCollection(array $content, string $key): array {
        if (!array_key_exists($key, $content)) {
            return [];
        }
        if (!is_array($content[$key])) {
            throw new InvalidArgumentException(t('eds_warranty_cards.invalid_form'));
        }
        return $content[$key];
    }
}
