<?php

declare(strict_types=1);

namespace Module\CciConsentHistory\Import;

final class ImportException extends \RuntimeException
{
    public readonly string $reason;

    public function __construct(string $reason)
    {
        $this->reason = preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $reason) ? $reason : 'batch_failed';
        parent::__construct($this->reason);
    }
}
