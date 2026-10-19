<?php

declare(strict_types=1);

namespace Module\CciConsentHistory\Import;

/** Optional schema lifecycle for an integration bundled with this module. */
interface ImporterStorageInterface
{
    public function installSchema(): bool;

    public function uninstallSchema(): bool;
}
