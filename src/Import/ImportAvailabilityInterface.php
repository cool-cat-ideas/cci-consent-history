<?php

declare(strict_types=1);

namespace Module\CciConsentHistory\Import;

/** Optional, inexpensive source check. Existing contract-v1 adapters remain supported. */
interface ImportAvailabilityInterface
{
    /** @return array{available:bool, reason:?string} Read-only; never create jobs or import records. */
    public function availability(array $context): array;
}
