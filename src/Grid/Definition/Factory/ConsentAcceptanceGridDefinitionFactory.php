<?php

declare(strict_types=1);

namespace Module\CciConsentHistory\Grid\Definition\Factory;

use PrestaShop\PrestaShop\Core\Grid\Column\ColumnCollection;
use PrestaShop\PrestaShop\Core\Grid\Column\Type\Common\ActionColumn;
use PrestaShop\PrestaShop\Core\Grid\Column\Type\Common\DataColumn;
use PrestaShop\PrestaShop\Core\Grid\Column\Type\Common\HtmlColumn;
use PrestaShop\PrestaShop\Core\Grid\Definition\Factory\AbstractGridDefinitionFactory;
use PrestaShop\PrestaShop\Core\Grid\Filter\Filter;
use PrestaShop\PrestaShop\Core\Grid\Filter\FilterCollection;
use PrestaShopBundle\Form\Admin\Type\SearchAndResetType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;

final class ConsentAcceptanceGridDefinitionFactory extends AbstractGridDefinitionFactory
{
    public const GRID_ID = 'cci_consent_acceptances';

    protected function getId(): string
    {
        return self::GRID_ID;
    }

    protected function getName(): string
    {
        return $this->trans('Consent history', [], 'Modules.CciConsentHistory.Admin');
    }

    protected function getColumns(): ColumnCollection
    {
        return (new ColumnCollection())
            ->add($this->htmlColumn('customer', $this->trans('Customer', [], 'Admin.Global'), 'customer_html'))
            ->add($this->dataColumn('purpose', $this->trans('Purpose', [], 'Modules.CciConsentHistory.Admin'), 'purpose_label'))
            ->add($this->htmlColumn('version', $this->trans('Version', [], 'Admin.Global'), 'version_html'))
            ->add($this->dataColumn('language', $this->trans('Language', [], 'Admin.Global'), 'language_label'))
            ->add($this->dataColumn('source', $this->trans('Source', [], 'Modules.CciConsentHistory.Admin'), 'source_label'))
            ->add($this->dataColumn('status', $this->trans('Status', [], 'Modules.CciConsentHistory.Admin'), 'status_label'))
            ->add($this->dataColumn('accepted_at', $this->trans('Recorded on', [], 'Modules.CciConsentHistory.Admin'), 'accepted_at'))
            ->add($this->htmlColumn('wording', $this->trans('Wording snapshot', [], 'Modules.CciConsentHistory.Admin'), 'wording_html'))
            ->add((new ActionColumn('actions'))->setName(''));
    }

    protected function getFilters(): FilterCollection
    {
        return (new FilterCollection())
            ->add(
                (new Filter('query', TextType::class))
                    ->setTypeOptions([
                        'required' => false,
                        'attr' => [
                            'placeholder' => $this->trans('Name or email address', [], 'Modules.CciConsentHistory.Admin'),
                        ],
                    ])
                    ->setAssociatedColumn('customer')
            )
            ->add(
                (new Filter('source', ChoiceType::class))
                    ->setTypeOptions([
                        'required' => false,
                        'placeholder' => $this->trans('All', [], 'Admin.Actions'),
                        'choices' => array_merge([
                            $this->trans('Account creation', [], 'Modules.CciConsentHistory.Admin') => 'account_creation',
                            $this->trans('Profile update', [], 'Modules.CciConsentHistory.Admin') => 'profile_update',
                            $this->trans('Consent granted', [], 'Modules.CciConsentHistory.Admin') => 'customer_choice_granted',
                            $this->trans('Consent withdrawn', [], 'Modules.CciConsentHistory.Admin') => 'customer_choice_withdrawn',
                            $this->trans('Information accepted', [], 'Modules.CciConsentHistory.Admin') => 'customer_field_accepted',
                            $this->trans('GDPR form submission', [], 'Modules.CciConsentHistory.Admin') => 'gdpr_form',
                            $this->trans('Renewed acceptance', [], 'Modules.CciConsentHistory.Admin') => 'renewal',
                        ], $this->importedSourceChoices()),
                    ])
                    ->setAssociatedColumn('source')
            )
            ->add(
                (new Filter('actions', SearchAndResetType::class))
                    ->setTypeOptions([
                        'reset_route' => 'admin_common_reset_search_by_filter_id',
                        'reset_route_params' => ['filterId' => self::GRID_ID],
                        'redirect_route' => 'cci_consent_history_index',
                    ])
                    ->setAssociatedColumn('actions')
            );
    }

    /** @return array<string, string> */
    private function importedSourceChoices(): array
    {
        $module = \Module::getInstanceByName('cci_consent_history');
        if (!$module instanceof \Cci_Consent_History) {
            return [];
        }

        $choices = [];
        foreach ($module->getImportManager()->registry()->acceptanceSources() as $source => $label) {
            $choices[$this->trans($label, [], 'Modules.CciConsentHistory.Admin')] = $source;
        }

        return $choices;
    }

    private function dataColumn(string $id, string $name, string $field): DataColumn
    {
        return (new DataColumn($id))
            ->setName($name)
            ->setOptions([
                'field' => $field,
                'clickable' => false,
            ]);
    }

    private function htmlColumn(string $id, string $name, string $field): HtmlColumn
    {
        return (new HtmlColumn($id))
            ->setName($name)
            ->setOptions([
                'field' => $field,
                'clickable' => false,
            ]);
    }
}
