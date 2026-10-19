<?php

declare(strict_types=1);

namespace Module\CciConsentHistory\Import;

/** Contract version 1. Integrations write only inside the manager's batch transaction. */
interface ImporterInterface
{
    public function getId(): string;

    public function getLabel(): string;

    /** Read-only snapshot: {total:int, cursor:array, source:array}. */
    public function plan(array $context): array;

    /** Names of destination tables which must support transactions. */
    public function transactionalTables(array $context): array;

    /** Process at most $limit history events. Never begin or commit a transaction here. */
    public function processBatch(array $context, array $source, array $cursor, int $limit): BatchResult;

    /** Called with the final batch, before its checkpoint is committed. Must be repeatable. */
    public function finish(array $context, array $source, array $cursor, array $counts): void;
}
