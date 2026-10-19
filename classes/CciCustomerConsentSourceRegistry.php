<?php

/**
 * Customer-checkbox source contract shared by native and third-party integrations.
 *
 * @license https://opensource.org/license/afl-3-0-php Academic Free License 3.0 (AFL-3.0)
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

final class CciCustomerConsentSourceRegistry
{
    public const CONTRACT_VERSION = 1;

    /** @var array<string, array{purpose:string,module:string,kind:string,read_state:callable|null}> */
    private array $sources = [];

    /**
     * The optional state reader receives (int $idCustomer, Context $context) and
     * returns bool for a known persisted state, or null when it cannot be read.
     */
    public function register(
        string $field,
        string $purpose,
        string $module,
        string $kind = 'choice',
        ?callable $readState = null,
    ): void {
        if (
            !preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,63}$/D', $field)
            || !preg_match('/^[a-z0-9_]{1,64}$/D', $purpose)
            || !preg_match('/^[a-zA-Z0-9_]{1,64}$/D', $module)
            || !in_array($kind, ['choice', 'acknowledgement'], true)
        ) {
            throw new InvalidArgumentException('Invalid customer consent source definition.');
        }
        $this->sources[$field] = [
            'purpose' => $purpose,
            'module' => $module,
            'kind' => $kind,
            'read_state' => $readState,
        ];
    }

    public function unregister(string $field): void
    {
        unset($this->sources[$field]);
    }

    public function get(string $field): ?array
    {
        return $this->sources[$field] ?? null;
    }

    public function all(): array
    {
        return $this->sources;
    }
}
