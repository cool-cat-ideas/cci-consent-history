<?php

declare(strict_types=1);

namespace Module\CciConsentHistory\Import;

final class ImporterRegistry
{
    /** @var array<string, ImporterInterface> */
    private array $importers = [];

    public function register(ImporterInterface $importer): void
    {
        $id = $importer->getId();
        if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $id) || isset($this->importers[$id])) {
            throw new ImportException('invalid_importer_registration');
        }
        $this->importers[$id] = $importer;
    }

    public function installSchema(): bool
    {
        foreach ($this->importers as $importer) {
            if ($importer instanceof ImporterStorageInterface && !$importer->installSchema()) {
                return false;
            }
        }

        return true;
    }

    public function uninstallSchema(): bool
    {
        foreach (array_reverse($this->importers) as $importer) {
            if ($importer instanceof ImporterStorageInterface && !$importer->uninstallSchema()) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, string> */
    public function acceptanceSources(): array
    {
        $sources = [];
        foreach ($this->importers as $importer) {
            if (!$importer instanceof ImporterSourceLabelsInterface) {
                continue;
            }
            foreach ($importer->getAcceptanceSources() as $source => $label) {
                if (!is_string($source) || !preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $source)
                    || !is_string($label) || trim($label) === '' || isset($sources[$source])
                ) {
                    throw new ImportException('invalid_importer_source_labels');
                }
                $sources[$source] = $label;
            }
        }

        return $sources;
    }

    public function get(string $id): ImporterInterface
    {
        return $this->importers[$id] ?? throw new ImportException('unknown_importer');
    }

    /** @return array<string, ImporterInterface> */
    public function all(): array
    {
        return $this->importers;
    }
}
