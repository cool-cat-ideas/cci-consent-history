<?php

declare(strict_types=1);

namespace Module\CciConsentHistory\Import;

final class BatchResult
{
    public const VERSION = 1;
    public const COUNTERS = ['processed', 'added', 'skipped', 'rejected', 'unmatched', 'errors'];

    public readonly array $counts;

    public function __construct(
        public readonly array $cursor,
        array $counts,
        public readonly bool $done,
        public readonly string $phase = 'history',
    ) {
        if (!in_array($phase, ['preparing', 'history', 'finishing'], true)
            || array_diff(array_keys($counts), self::COUNTERS) !== []
        ) {
            throw new ImportException('invalid_batch_result');
        }
        $normalized = array_fill_keys(self::COUNTERS, 0);
        foreach ($counts as $key => $value) {
            if (!is_int($value) || $value < 0) {
                throw new ImportException('invalid_batch_result');
            }
            $normalized[$key] = $value;
        }
        if ($normalized['processed'] !== $normalized['added'] + $normalized['skipped']
                + $normalized['rejected'] + $normalized['errors']
            || $normalized['unmatched'] > $normalized['processed']
        ) {
            throw new ImportException('invalid_batch_result');
        }
        json_encode($cursor, JSON_THROW_ON_ERROR);
        $this->counts = $normalized;
    }
}
