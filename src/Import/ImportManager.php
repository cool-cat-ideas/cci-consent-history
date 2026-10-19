<?php

declare(strict_types=1);

namespace Module\CciConsentHistory\Import;

final class ImportManager
{
    private const TABLE = 'cci_consent_import_job';
    private const TERMINAL = ['completed', 'cancelled'];

    public function __construct(private readonly ImporterRegistry $registry)
    {
    }

    public function registry(): ImporterRegistry
    {
        return $this->registry;
    }

    /** @return array<string, array{label:string, available:bool, reason:?string, description?:string, notice?:string}> */
    public function sources(int $shopId, int $languageId): array
    {
        $sources = [];
        foreach ($this->registry->all() as $id => $importer) {
            $availability = ['available' => false, 'reason' => 'select_one_shop'];
            if ($shopId > 0) {
                try {
                    $context = ['shop_id' => $shopId, 'language_id' => $languageId, 'apply_wording' => false];
                    if ($importer instanceof ImportAvailabilityInterface) {
                        $availability = $importer->availability($context);
                        if (!is_bool($availability['available'] ?? null)) {
                            throw new ImportException('source_unavailable');
                        }
                    } else {
                        // Contract v1 defines plan() as read-only; old integrations need no changes.
                        $importer->plan($context);
                        $availability = ['available' => true, 'reason' => null];
                    }
                } catch (ImportException $exception) {
                    $availability = ['available' => false, 'reason' => $exception->reason];
                } catch (\Throwable) {
                    $availability = ['available' => false, 'reason' => 'source_unavailable'];
                }
            }
            $sources[$id] = ['label' => $importer->getLabel()] + $availability;
        }

        return $sources;
    }

    public function latest(int $shopId, ?string $importer = null): ?array
    {
        $where = '`id_shop` = ' . $shopId;
        if ($importer !== null) {
            $where .= " AND `importer` = '" . pSQL($importer) . "'";
        }
        $row = \Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE ' . $where
            . ' ORDER BY (`active_key` IS NOT NULL) DESC, `date_add` DESC, `id_job` DESC',
            false
        );

        return is_array($row) ? $this->present($row) : null;
    }

    public function start(
        string $importerId,
        int $shopId,
        int $languageId,
        bool $backupConfirmed,
        bool $applyWording,
        int $employeeId = 0,
    ): array {
        if (!$backupConfirmed) {
            throw new ImportException('backup_required');
        }
        if ($shopId <= 0 || !\Shop::getShop($shopId) || !\Language::getLanguage($languageId)) {
            throw new ImportException('invalid_context');
        }
        $importer = $this->registry->get($importerId);
        // Only one source can change a shop's wording and history at a time.
        $activeKey = 'shop:' . $shopId;
        $lock = 'cci-import-start:' . substr(hash('sha256', _DB_NAME_ . ':' . $activeKey), 0, 40);
        if (!$this->lock($lock)) {
            throw new ImportException('import_busy');
        }

        try {
            $existing = \Db::getInstance()->getRow(
                'SELECT * FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE `id_shop` = ' . $shopId
                . ' AND `active_key` IS NOT NULL ORDER BY `date_add` DESC',
                false
            );
            if (is_array($existing)) {
                return $this->present($existing);
            }
            $context = ['shop_id' => $shopId, 'language_id' => $languageId, 'apply_wording' => $applyWording];
            if ($importer instanceof ImportAvailabilityInterface && !$importer->availability($context)['available']) {
                throw new ImportException('source_unavailable');
            }
            $this->assertTransactional($importer, $context);
            $plan = $importer->plan($context);
            if (!isset($plan['total'], $plan['cursor'], $plan['source']) || !is_int($plan['total'])
                || $plan['total'] < 0 || !is_array($plan['cursor']) || !is_array($plan['source'])
            ) {
                throw new ImportException('invalid_import_plan');
            }
            $id = bin2hex(random_bytes(16));
            $now = date('Y-m-d H:i:s');
            $this->must(\Db::getInstance()->insert(self::TABLE, [
                'id_job' => pSQL($id),
                'active_key' => pSQL($activeKey),
                'importer' => pSQL($importerId),
                'id_shop' => $shopId,
                'id_employee' => $employeeId ?: null,
                'status' => 'queued',
                'phase' => 'preparing',
                'total' => $plan['total'],
                'context_json' => pSQL($this->encode($context), true),
                'source_json' => pSQL($this->encode($plan['source']), true),
                'cursor_json' => pSQL($this->encode($plan['cursor']), true),
                'counts_json' => pSQL($this->encode(array_fill_keys(BatchResult::COUNTERS, 0)), true),
                'failed_batches' => 0,
                'error_code' => null,
                'date_add' => $now,
                'date_upd' => $now,
            ], true));

            return $this->status($id, $shopId);
        } finally {
            $this->unlock($lock);
        }
    }

    public function status(string $id, int $shopId): array
    {
        return $this->present($this->load($id, $shopId));
    }

    public function control(string $id, int $shopId, string $action): array
    {
        if (!in_array($action, ['pause', 'resume', 'cancel'], true)) {
            throw new ImportException('invalid_action');
        }
        $lock = $this->jobLock($id);
        if (!$this->lock($lock, 5)) {
            throw new ImportException('import_busy');
        }

        try {
            $row = $this->load($id, $shopId);
            if (in_array($row['status'], self::TERMINAL, true)) {
                return $this->present($row);
            }
            $status = match ($action) {
                'pause' => 'paused',
                'resume' => 'queued',
                'cancel' => 'cancelled',
            };
            $data = ['status' => $status, 'error_code' => null, 'date_upd' => date('Y-m-d H:i:s')];
            if ($status === 'cancelled') {
                $data['active_key'] = null;
            }
            $this->must(\Db::getInstance()->update(self::TABLE, $data, "`id_job` = '" . pSQL($id) . "'", 0, true));

            return $this->status($id, $shopId);
        } finally {
            $this->unlock($lock);
        }
    }

    public function step(string $id, int $shopId, int $limit = 250): array
    {
        $limit = max(1, min(500, $limit));
        $row = $this->load($id, $shopId);
        if (!in_array($row['status'], ['queued', 'running'], true)) {
            return $this->present($row);
        }
        $lock = $this->jobLock($id);
        if (!$this->lock($lock)) {
            return $this->status($id, $shopId) + ['locked' => true];
        }
        $db = \Db::getInstance();
        $transaction = false;

        try {
            $row = $this->load($id, $shopId);
            if (!in_array($row['status'], ['queued', 'running'], true)) {
                return $this->present($row);
            }
            $importer = $this->registry->get((string) $row['importer']);
            $context = $this->decode($row['context_json']);
            $source = $this->decode($row['source_json']);
            $cursor = $this->decode($row['cursor_json']);
            $counts = $this->decode($row['counts_json']);
            $this->must($db->execute('START TRANSACTION'));
            $transaction = true;
            $result = $importer->processBatch($context, $source, $cursor, $limit);
            if ($result->counts['processed'] > $limit
                || (!$result->done && $this->encode($result->cursor) === $this->encode($cursor))
            ) {
                throw new ImportException('invalid_batch_result');
            }
            foreach (BatchResult::COUNTERS as $counter) {
                $counts[$counter] += $result->counts[$counter];
            }
            if ($counts['processed'] > (int) $row['total']
                || ($result->done && $counts['processed'] !== (int) $row['total'])
            ) {
                throw new ImportException('source_changed');
            }
            if ($result->done) {
                $importer->finish($context, $source, $result->cursor, $counts);
            }
            $this->must($db->update(self::TABLE, [
                'status' => $result->done ? 'completed' : 'running',
                'phase' => $result->done ? 'finishing' : $result->phase,
                'cursor_json' => pSQL($this->encode($result->cursor), true),
                'counts_json' => pSQL($this->encode($counts), true),
                'active_key' => $result->done ? null : $row['active_key'],
                'error_code' => null, 'date_upd' => date('Y-m-d H:i:s'),
            ], "`id_job` = '" . pSQL($id) . "'", 0, true));
            $this->must($db->execute('COMMIT'));
            $transaction = false;

            return $this->status($id, $shopId);
        } catch (\Throwable $exception) {
            if ($transaction) {
                $db->execute('ROLLBACK');
            }
            // A SQL exception can contain personal source data. Expose only a controlled error code.
            $reason = $exception instanceof ImportException ? $exception->reason : 'batch_failed';
            $this->must($db->update(self::TABLE, [
                'status' => 'failed', 'failed_batches' => (int) $row['failed_batches'] + 1,
                'error_code' => pSQL($reason), 'date_upd' => date('Y-m-d H:i:s'),
            ], "`id_job` = '" . pSQL($id) . "'", 0, true));

            return $this->status($id, $shopId);
        } finally {
            $this->unlock($lock);
        }
    }

    private function load(string $id, int $shopId): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id) || $shopId <= 0) {
            throw new ImportException('job_not_found');
        }
        $row = \Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . self::TABLE . "` WHERE `id_job` = '" . pSQL($id)
            . "' AND `id_shop` = " . $shopId,
            false
        );
        if (!is_array($row)) {
            throw new ImportException('job_not_found');
        }

        return $row;
    }

    private function present(array $row): array
    {
        $counts = $this->decode($row['counts_json']);
        $completed = $row['status'] === 'completed';
        $total = (int) $row['total'];

        return [
            'schema_version' => BatchResult::VERSION,
            'job_id' => (string) $row['id_job'], 'importer' => (string) $row['importer'],
            'status' => (string) $row['status'], 'phase' => (string) $row['phase'], 'total' => $total,
            'counts' => $counts,
            'percent' => $completed ? 100 : ($total > 0 ? min(99, (int) floor(100 * $counts['processed'] / $total)) : 0),
            'can_continue' => in_array($row['status'], ['queued', 'running'], true),
            'can_resume' => in_array($row['status'], ['paused', 'failed'], true),
            'can_cancel' => !in_array($row['status'], self::TERMINAL, true),
            'failed_batches' => (int) $row['failed_batches'],
            'error_code' => $row['error_code'] ?: null, 'updated_at' => (string) $row['date_upd'],
        ];
    }

    private function assertTransactional(ImporterInterface $importer, array $context): void
    {
        foreach (array_unique(array_merge([_DB_PREFIX_ . self::TABLE], $importer->transactionalTables($context))) as $table) {
            $engine = \Db::getInstance()->getValue(
                "SELECT ENGINE FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '" . pSQL($table) . "'",
                false
            );
            if (strtoupper((string) $engine) !== 'INNODB') {
                throw new ImportException('transactional_storage_required');
            }
        }
    }

    private function lock(string $name, int $wait = 0): bool
    {
        return (int) \Db::getInstance()->getValue("SELECT GET_LOCK('" . pSQL($name) . "', " . $wait . ')', false) === 1;
    }

    private function unlock(string $name): void
    {
        \Db::getInstance()->getValue("SELECT RELEASE_LOCK('" . pSQL($name) . "')", false);
    }

    private function jobLock(string $id): string
    {
        return 'cci-import:' . substr(hash('sha256', _DB_NAME_ . ':' . $id), 0, 48);
    }

    private function must(bool $success): void
    {
        if (!$success) {
            throw new ImportException('storage_write_failed');
        }
    }

    private function encode(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function decode(string $value): array
    {
        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new ImportException('invalid_job_state');
        }

        return $decoded;
    }
}
