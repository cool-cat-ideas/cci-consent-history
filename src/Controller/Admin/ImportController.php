<?php

declare(strict_types=1);

namespace Module\CciConsentHistory\Controller\Admin;

use Module\CciConsentHistory\Import\ImportException;
use PrestaShopBundle\Controller\Admin\PrestaShopAdminController;
use PrestaShopBundle\Security\Attribute\AdminSecurity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class ImportController extends PrestaShopAdminController
{
    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))")]
    public function statusAction(Request $request): JsonResponse
    {
        return $this->respond(function () use ($request) {
            $manager = $this->module()->getImportManager();
            $shop = $this->shopId();
            $id = $request->query->getString('job');

            return $id === '' ? $manager->latest($shop) : $manager->status($id, $shop);
        });
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))")]
    public function workAction(Request $request, CsrfTokenManagerInterface $csrf): JsonResponse
    {
        if (!$csrf->isTokenValid(new CsrfToken('cci_consent_import', $request->request->getString('_token')))) {
            return $this->json(['error' => 'csrf_expired'], 403);
        }

        return $this->respond(function () use ($request) {
            $manager = $this->module()->getImportManager();
            $shop = $this->shopId();
            $action = $request->request->getString('action');
            if ($action === 'start') {
                return $manager->start(
                    $request->request->getString('importer'), $shop,
                    (int) \Context::getContext()->language->id,
                    $request->request->getBoolean('backup'), $request->request->getBoolean('wording'),
                    (int) \Context::getContext()->employee->id
                );
            }
            $id = $request->request->getString('job');

            return $action === 'step' ? $manager->step($id, $shop) : $manager->control($id, $shop, $action);
        });
    }

    private function respond(callable $action): JsonResponse
    {
        try {
            $response = $this->json(['job' => $action()]);
        } catch (ImportException $exception) {
            $response = $this->json(['error' => $exception->reason], $exception->reason === 'job_not_found' ? 404 : 409);
        } catch (\Throwable) {
            // Never return exception messages containing SQL or customer data.
            $response = $this->json(['error' => 'request_failed'], 500);
        }
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    private function module(): \Cci_Consent_History
    {
        $module = \Module::getInstanceByName('cci_consent_history');
        if (!$module instanceof \Cci_Consent_History || !$module->active) {
            throw $this->createNotFoundException();
        }

        return $module;
    }

    private function shopId(): int
    {
        if (\Shop::getContext() !== \Shop::CONTEXT_SHOP) {
            throw new ImportException('select_one_shop');
        }

        return (int) \Context::getContext()->shop->id;
    }
}
