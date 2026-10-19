<?php

declare(strict_types=1);

namespace Module\CciConsentHistory\Grid\Query;

use Doctrine\DBAL\Query\QueryBuilder;
use PrestaShop\PrestaShop\Core\Grid\Query\AbstractDoctrineQueryBuilder;
use PrestaShop\PrestaShop\Core\Grid\Search\SearchCriteriaInterface;

final class ConsentAcceptanceQueryBuilder extends AbstractDoctrineQueryBuilder
{
    private const SORT_FIELDS = [
        'customer' => 'customer.lastname',
        'version' => 'version.version_number',
        'language' => 'language.name',
        'purpose' => 'acceptance.purpose_key',
        'source' => 'acceptance.acceptance_source',
        'status' => 'acceptance.acceptance_source',
        'accepted_at' => 'acceptance.date_add',
        'wording' => 'version.content_text',
    ];

    public function getSearchQueryBuilder(SearchCriteriaInterface $searchCriteria): QueryBuilder
    {
        $queryBuilder = $this->createBaseQueryBuilder($searchCriteria->getFilters())
            ->select(
                'acceptance.*',
                'version.version_number',
                'version.content_hash',
                'version.content_text',
                'version.content_html',
                'version.source_module',
                'customer.firstname',
                'customer.lastname',
                'customer.email',
                'language.name AS language_name',
                'language.iso_code'
            );

        $orderBy = self::SORT_FIELDS[$searchCriteria->getOrderBy() ?? ''] ?? self::SORT_FIELDS['accepted_at'];
        $sortOrder = strtolower((string) $searchCriteria->getOrderWay()) === 'asc' ? 'ASC' : 'DESC';
        $queryBuilder
            ->orderBy($orderBy, $sortOrder)
            ->addOrderBy('acceptance.id_cci_consent_acceptance', $sortOrder);

        if ($searchCriteria->getLimit() !== null) {
            $queryBuilder->setMaxResults(max(1, (int) $searchCriteria->getLimit()));
        }
        if ($searchCriteria->getOffset() !== null) {
            $queryBuilder->setFirstResult(max(0, (int) $searchCriteria->getOffset()));
        }

        return $queryBuilder;
    }

    public function getCountQueryBuilder(SearchCriteriaInterface $searchCriteria): QueryBuilder
    {
        return $this->createBaseQueryBuilder($searchCriteria->getFilters())
            ->select('COUNT(acceptance.id_cci_consent_acceptance)');
    }

    private function createBaseQueryBuilder(array $filters): QueryBuilder
    {
        $idShop = (int) \Context::getContext()->shop->id;
        $queryBuilder = $this->connection->createQueryBuilder()
            ->from($this->dbPrefix . 'cci_consent_acceptance', 'acceptance')
            ->innerJoin(
                'acceptance',
                $this->dbPrefix . 'cci_consent_version',
                'version',
                'version.id_cci_consent_version = acceptance.id_cci_consent_version'
            )
            ->leftJoin(
                'acceptance',
                $this->dbPrefix . 'customer',
                'customer',
                'customer.id_customer = acceptance.id_customer'
            )
            ->leftJoin(
                'acceptance',
                $this->dbPrefix . 'lang',
                'language',
                'language.id_lang = acceptance.id_lang'
            )
            ->andWhere('acceptance.id_shop = :id_shop')
            ->setParameter('id_shop', $idShop);

        $query = trim((string) ($filters['query'] ?? ''));
        if ($query !== '') {
            $queryBuilder
                ->andWhere(
                    '(customer.email LIKE :query OR customer.firstname LIKE :query'
                    . ' OR customer.lastname LIKE :query'
                    . ' OR CONCAT_WS(\' \', customer.firstname, customer.lastname) LIKE :query'
                    . ' OR acceptance.source_customer_id = :customer_id)'
                )
                ->setParameter('query', '%' . $query . '%')
                ->setParameter('customer_id', ctype_digit($query) ? (int) $query : 0);
        }

        $source = (string) ($filters['source'] ?? '');
        if (preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $source)) {
            $queryBuilder
                ->andWhere('acceptance.acceptance_source = :source')
                ->setParameter('source', $source);
        }

        return $queryBuilder;
    }
}
