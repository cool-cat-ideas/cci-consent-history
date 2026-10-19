<?php

declare(strict_types=1);

namespace Module\CciConsentHistory\Import;

final class BundledImporterLoader
{
    /** Discover trusted local integration code, never a path supplied by a request. */
    public function register(string $directory, ImporterRegistry $registry, \CciConsentHistoryRepository $repository): void
    {
        $entries = glob($directory . '/*/register.php') ?: [];
        sort($entries, SORT_STRING);

        foreach ($entries as $entry) {
            $factory = require $entry;
            if (!is_callable($factory)) {
                throw new ImportException('invalid_importer_registration');
            }

            $importer = $factory($repository);
            if (!$importer instanceof ImporterInterface) {
                throw new ImportException('invalid_importer_registration');
            }

            $registry->register($importer);
        }
    }
}
