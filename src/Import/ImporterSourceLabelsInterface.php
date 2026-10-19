<?php

declare(strict_types=1);

namespace Module\CciConsentHistory\Import;

/** Optional labels for acceptance sources written by an integration. */
interface ImporterSourceLabelsInterface
{
    /** @return array<string, string> Source identifier => label. */
    public function getAcceptanceSources(): array;
}
