<?php

declare(strict_types=1);

namespace Module\CciConsentHistory\Grid\Data\Factory;

use PrestaShop\PrestaShop\Core\Grid\Data\Factory\GridDataFactoryInterface;
use PrestaShop\PrestaShop\Core\Grid\Data\GridData;
use PrestaShop\PrestaShop\Core\Grid\Record\RecordCollection;
use PrestaShop\PrestaShop\Core\Grid\Search\SearchCriteriaInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ConsentAcceptanceGridDataFactory implements GridDataFactoryInterface
{
    public function __construct(
        private readonly GridDataFactoryInterface $innerDataFactory,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function getData(SearchCriteriaInterface $searchCriteria): GridData
    {
        $gridData = $this->innerDataFactory->getData($searchCriteria);
        $records = [];

        foreach ($gridData->getRecords()->all() as $row) {
            $records[] = $this->presentRecord($row);
        }

        return new GridData(
            new RecordCollection($records),
            $gridData->getRecordsTotal(),
            $gridData->getQuery()
        );
    }

    private function presentRecord(array $row): array
    {
        $name = trim((string) ($row['firstname'] ?? '') . ' ' . (string) ($row['lastname'] ?? ''));
        if ($name === '') {
            $sourceCustomerId = (int) ($row['source_customer_id'] ?? 0);
            $guest = (int) $row['id_customer'] === 0 && $row['acceptance_source'] === 'gdpr_form';
            $name = $this->translator->trans(
                $guest ? ((int) $row['source_guest_id'] > 0 ? 'Guest #%id%' : 'Guest') : 'Deleted customer #%id%',
                ['%id%' => $guest ? (int) $row['source_guest_id'] : ($sourceCustomerId > 0 ? $sourceCustomerId : (int) $row['id_customer'])],
                'Modules.CciConsentHistory.Admin'
            );
        }

        $email = trim((string) ($row['email'] ?? ''));
        $row['customer_html'] = '<div class="font-weight-bold">' . $this->escape($name) . '</div>'
            . ($email !== '' ? '<div class="text-muted">' . $this->escape($email) . '</div>' : '');
        $row['version_html'] = '<span class="badge badge-info">v' . (int) $row['version_number'] . '</span>'
            . '<div><code>' . $this->escape(substr((string) $row['content_hash'], 0, 12)) . '</code></div>';
        $module = \Module::getInstanceByName('cci_consent_history');
        $row['purpose_label'] = $module instanceof \Cci_Consent_History ? $module->getConsentPurposeLabel($row) : (string) $row['purpose_key'];
        $row['language_label'] = (string) ($row['language_name'] ?: strtoupper((string) $row['iso_code']));
        $row['source_label'] = $this->sourceLabel((string) $row['acceptance_source']);
        if ($row['acceptance_source'] === 'gdpr_form') {
            $row['source_label'] .= ' (' . $row['source_module'] . ')';
        }
        $row['status_label'] = $this->translator->trans(
            str_ends_with($row['acceptance_source'], '_withdrawn') ? 'Withdrawn'
                : (str_ends_with($row['acceptance_source'], '_granted') ? 'Granted' : 'Accepted'),
            [], 'Modules.CciConsentHistory.Admin'
        );
        $row['accepted_at'] = (string) $row['date_add'];
        $row['wording_html'] = '<button type="button" class="btn btn-link btn-sm p-0 cci-consent-history-show-wording"'
            . ' data-wording="' . $this->escape(base64_encode((string) $row['content_text'])) . '">'
            . $this->escape($this->translator->trans('Show wording', [], 'Modules.CciConsentHistory.Admin'))
            . '</button>';

        return $row;
    }

    private function sourceLabel(string $source): string
    {
        $module = \Module::getInstanceByName('cci_consent_history');
        $importedSources = $module instanceof \Cci_Consent_History
            ? $module->getImportManager()->registry()->acceptanceSources() : [];
        if (isset($importedSources[$source])) {
            return $this->translator->trans($importedSources[$source], [], 'Modules.CciConsentHistory.Admin');
        }

        return match ($source) {
            'customer_choice_granted', 'customer_choice_withdrawn', 'customer_field_accepted' => $this->translator->trans('Customer form', [], 'Modules.CciConsentHistory.Admin'),
            'account_creation' => $this->translator->trans('Account creation', [], 'Modules.CciConsentHistory.Admin'),
            'profile_update' => $this->translator->trans('Profile update', [], 'Modules.CciConsentHistory.Admin'),
            'gdpr_form' => $this->translator->trans('GDPR form submission', [], 'Modules.CciConsentHistory.Admin'),
            'renewal' => $this->translator->trans('Renewed acceptance', [], 'Modules.CciConsentHistory.Admin'),
            default => $this->translator->trans('Imported acceptance', [], 'Modules.CciConsentHistory.Admin'),
        };
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
