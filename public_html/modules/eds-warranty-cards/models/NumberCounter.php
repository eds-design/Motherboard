<?php
require_once dirname(__DIR__) . '/lib.php';

final class EdsWarrantyCardCounter {
    public function __construct(private PDO $pdo) {}

    public function getNextNumber(): string {
        $value = $this->pdo->query('SELECT next_number FROM eds_warranty_cards_counter WHERE id = 1')->fetchColumn();
        if (!is_string($value)) {
            throw new RuntimeException(t('eds_warranty_cards.counter_unavailable'));
        }
        return EdsWarrantyCardNumber::validate($value);
    }

    public function advanceTo(mixed $value, ?callable $withCounter = null): void {
        $requested = EdsWarrantyCardNumber::validate($value);
        $this->withLock(function (string $current) use ($requested, $withCounter): void {
            if (EdsWarrantyCardNumber::compare($requested, $current) < 0) {
                throw new InvalidArgumentException(t('eds_warranty_cards.number_cannot_decrease'));
            }
            if ($withCounter !== null) {
                $withCounter($this->pdo);
            }
            $this->write($requested);
        });
    }

    /**
     * Future issuance must lock the counter FIRST, then the card using this PDO.
     * Persist the complete issued card inside the callback; return true only for
     * a new issuance, false for an already issued card. No external side effects.
     * The card and counter commit together; errors roll both back.
     */
    public function issue(callable $persistCard): ?string {
        return $this->withLock(function (string $current) use ($persistCard): ?string {
            $created = $persistCard($current, $this->pdo);
            if (!is_bool($created)) {
                throw new LogicException('Issuance callback must return a boolean.');
            }
            if (!$created) {
                return null;
            }
            $this->write(EdsWarrantyCardNumber::increment($current));
            return $current;
        });
    }

    private function withLock(callable $operation): mixed {
        if ($this->pdo->inTransaction()) {
            throw new LogicException('Counter operation must own its transaction.');
        }
        $this->pdo->beginTransaction();
        try {
            $value = $this->pdo->query('SELECT next_number FROM eds_warranty_cards_counter WHERE id = 1 FOR UPDATE')->fetchColumn();
            if (!is_string($value)) {
                throw new RuntimeException(t('eds_warranty_cards.counter_unavailable'));
            }
            $result = $operation(EdsWarrantyCardNumber::validate($value));
            $this->pdo->commit();
            return $result;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    private function write(string $value): void {
        $stmt = $this->pdo->prepare('UPDATE eds_warranty_cards_counter SET next_number = ?, updated_at = NOW() WHERE id = 1');
        $stmt->execute([$value]);
    }
}
