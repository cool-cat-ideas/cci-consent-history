<?php

declare(strict_types=1);

namespace Module\CciConsentHistory\Grid\Filters;

use Module\CciConsentHistory\Grid\Definition\Factory\ConsentAcceptanceGridDefinitionFactory;
use PrestaShop\PrestaShop\Core\Search\Filters;

final class ConsentAcceptanceFilters extends Filters
{
    protected $filterId = ConsentAcceptanceGridDefinitionFactory::GRID_ID;

    public static function getDefaults(): array
    {
        return [
            'limit' => 50,
            'offset' => 0,
            'orderBy' => 'accepted_at',
            'sortOrder' => 'desc',
            'filters' => [],
        ];
    }
}
