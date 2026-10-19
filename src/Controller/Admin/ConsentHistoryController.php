<?php

declare(strict_types=1);

namespace Module\CciConsentHistory\Controller\Admin;

use Module\CciConsentHistory\Grid\Definition\Factory\ConsentAcceptanceGridDefinitionFactory;
use Module\CciConsentHistory\Grid\Filters\ConsentAcceptanceFilters;
use PrestaShop\PrestaShop\Core\Grid\GridFactoryInterface;
use PrestaShopBundle\Controller\Admin\PrestaShopAdminController;
use PrestaShopBundle\Form\Admin\Type\SwitchType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ConsentHistoryController extends PrestaShopAdminController
{
    #[\PrestaShopBundle\Security\Attribute\AdminSecurity("is_granted('read', request.get('_legacy_controller'))")]
    public function indexAction(
        Request $request,
        ConsentAcceptanceFilters $filters,
        #[Autowire(service: 'cci_consent_history.grid.factory.acceptances')]
        GridFactoryInterface $acceptanceGridFactory,
    ): Response {
        $module = \Module::getInstanceByName('cci_consent_history');
        if (!$module instanceof \Cci_Consent_History) {
            throw $this->createNotFoundException('CCI Consent History is not available.');
        }

        if ($request->isMethod('POST')) {
            $this->denyAccessUnlessGranted('update', $request->get('_legacy_controller'));
        }
        $module->prepareModernAdminPage();
        $settingsForm = $this->createFormBuilder([
            'require_renewal' => $module->isRenewalRequired(),
        ], [
            'csrf_token_id' => 'cci_consent_history_settings',
        ])
            ->add('require_renewal', SwitchType::class, [
                'label' => $this->trans(
                    'Require acceptance after a wording change',
                    [],
                    'Modules.CciConsentHistory.Admin'
                ),
                'help' => $this->trans(
                    'This applies to the account privacy consent configured in Official GDPR Compliance. Do not use a mandatory account consent for newsletters, analytics or advertising.',
                    [],
                    'Modules.CciConsentHistory.Admin'
                ),
                'required' => false,
            ])
            ->getForm();
        $settingsForm->handleRequest($request);

        if ($settingsForm->isSubmitted() && $settingsForm->isValid()) {
            $settings = $settingsForm->getData();
            $this->addFlash(
                'success',
                $module->saveRenewalSetting(!empty($settings['require_renewal']))
            );

            return $this->redirectToRoute('cci_consent_history_index');
        }

        if ($request->isMethod('POST') && !$settingsForm->isSubmitted()) {
            $this->addFlash('error', $this->trans('The requested action is not available.', [], 'Admin.Notifications.Error'));

            return $this->redirectToRoute('cci_consent_history_index');
        }

        $grid = $acceptanceGridFactory->getGrid($filters);

        return $this->render('@Modules/cci_consent_history/views/templates/admin/consent_history.html.twig', [
            'enableSidebar' => true,
            'layoutTitle' => 'CCI Consent History',
            'dashboard' => $module->renderDashboardForModernAdmin(),
            'settingsForm' => $settingsForm->createView(),
            'importSources' => $module->getImportManager()->sources(
                \Shop::getContext() === \Shop::CONTEXT_SHOP ? (int) \Context::getContext()->shop->id : 0,
                (int) \Context::getContext()->language->id
            ),
            'importCanUpdate' => $this->isGranted('update', 'AdminCciConsentHistory'),
            'acceptanceGrid' => $this->presentGrid($grid),
            'brandPanel' => $module->renderBrandPanelForModernAdmin(),
            'adminCssVersion' => $module->getAdminCssVersion(),
        ]);
    }

    public function searchAction(
        Request $request,
        ConsentAcceptanceGridDefinitionFactory $definitionFactory,
    ): RedirectResponse {
        return $this->buildSearchResponse(
            $definitionFactory,
            $request,
            ConsentAcceptanceGridDefinitionFactory::GRID_ID,
            'cci_consent_history_index'
        );
    }
}
