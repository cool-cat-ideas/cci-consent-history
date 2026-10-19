<?php

/** Back-office presentation; installation, hooks and business rules stay in the module. */
if (!defined('_PS_VERSION_')) {
    exit;
}

final class CciConsentHistoryAdminPresentation
{
    public function __construct(
        private readonly Module $module,
        private readonly Context $context,
        private readonly CciConsentHistoryRepository $repository,
    ) {
    }

    public function renderHeroSection(): string
    {
        $heroImage = $this->module->getPathUri() . 'views/img/cci-hello.png';

        return '<section class="cci-consent-history-card cci-consent-history-hero" style="--cci-consent-history-hero-image:url(\''
            . $this->escapeHtml($heroImage) . '\')"><div class="cci-consent-history-card-inner">'
            . '<div class="cci-consent-history-hero-content"><div class="cci-consent-history-title-row">'
            . '<span class="cci-consent-history-title">' . $this->escapeHtml($this->module->displayName) . '</span>'
            . '<span class="cci-consent-history-version-badge">v' . $this->escapeHtml($this->module->version) . '</span>'
            . '</div><p class="cci-consent-history-subtitle">'
            . $this->escapeHtml(
                $this->module->getTranslator()->trans(
                    'Keep wording versions and customer acceptance history for Official GDPR Compliance. Ask customers to accept the new wording after a change.',
                    [],
                    'Modules.CciConsentHistory.Admin'
                )
            )
            . '</p><nav class="cci-consent-history-resource-links" aria-label="'
            . $this->escapeHtml($this->module->getTranslator()->trans('Product resources', [], 'Modules.CciConsentHistory.Admin')) . '">'
            . '<a class="btn btn-outline-secondary" href="https://coolcatideas.com/docs/cci-consent-history/1.0.0/" target="_blank" rel="noopener noreferrer">'
            . '<i class="material-icons" aria-hidden="true">help_outline</i>'
            . $this->escapeHtml($this->module->getTranslator()->trans('Documentation', [], 'Modules.CciConsentHistory.Admin')) . '</a>'
            . '<a class="btn btn-outline-secondary" href="https://coolcatideas.com/products/cci-consent-history/" target="_blank" rel="noopener noreferrer">'
            . $this->escapeHtml($this->module->getTranslator()->trans('Product page', [], 'Modules.CciConsentHistory.Admin'))
            . '<i class="material-icons" aria-hidden="true">open_in_new</i></a>'
            . $this->renderConsentSettingsLinks()
            . '</nav></div></div></section>';
    }

    public function renderBrandPanel(): string
    {
        return '<aside class="cci-brand-panel"><div class="cci-brand-card">'
            . '<div class="cci-brand-title">'
            . $this->escapeHtml($this->module->getTranslator()->trans('About us', [], 'Modules.CciConsentHistory.Admin'))
            . '</div>'
            . '<div class="cci-brand-logos">'
            . '<img src="' . $this->escapeHtml($this->module->getPathUri() . 'views/img/cool-cat-ideas-logo.svg') . '" alt="Cool Cat Ideas">'
            . '<img src="' . $this->escapeHtml($this->module->getPathUri() . 'views/img/teastudio-logo.png') . '" alt="teastudio">'
            . '</div><p class="cci-brand-text">'
            . $this->escapeHtml(
                $this->module->getTranslator()->trans(
                    'This module is only a fraction of what we do. We deliver custom IT solutions that grow with your business. We ensure complete code optimization and security.',
                    [],
                    'Modules.CciConsentHistory.Admin'
                )
            )
            . '</p><div class="cci-brand-heading">'
            . $this->escapeHtml($this->module->getTranslator()->trans('We implement and develop:', [], 'Modules.CciConsentHistory.Admin'))
            . '</div><ul class="cci-check-list">'
            . '<li><span>' . $this->renderBrandFeature('Fast e-commerce on %platform% (from implementations to custom modules)', 'PrestaShop') . '</span></li>'
            . '<li><span>' . $this->renderBrandFeature('Flexible and modern %platform% websites', 'WordPress') . '</span></li>'
            . '<li><span>' . $this->renderBrandFeature('Scalable web applications in %platform%', 'Next.js') . '</span></li>'
            . '<li><span>' . $this->renderBrandFeature('Process automation and %platform%', 'API integrations') . '</span></li>'
            . '</ul><a class="cci-btn-primary" href="https://coolcatideas.com" target="_blank" rel="noopener noreferrer">'
            . $this->escapeHtml($this->module->getTranslator()->trans('See our offer', [], 'Modules.CciConsentHistory.Admin'))
            . '</a></div></aside>';
    }

    private function renderBrandFeature(string $source, string $platform): string
    {
        $translated = $this->escapeHtml($this->module->getTranslator()->trans($source, [], 'Modules.CciConsentHistory.Admin'));
        $translatedPlatform = $this->escapeHtml($this->module->getTranslator()->trans($platform, [], 'Modules.CciConsentHistory.Admin'));

        return str_replace('%platform%', '<strong>' . $translatedPlatform . '</strong>', $translated);
    }

    public function renderStats(?array $currentVersion): string
    {
        $stats = $this->repository->getStats((int) $this->context->shop->id);
        $versionLabel = $currentVersion === null
            ? '-'
            : 'v' . (int) $currentVersion['version_number'];

        return '<section class="cci-consent-history-metrics" aria-label="'
            . $this->escapeHtml($this->module->getTranslator()->trans('Consent history summary', [], 'Modules.CciConsentHistory.Admin'))
            . '">'
            . $this->renderMetric(
                $this->module->getTranslator()->trans('Current wording', [], 'Modules.CciConsentHistory.Admin'),
                $versionLabel,
                'description'
            )
            . $this->renderMetric(
                $this->module->getTranslator()->trans('Saved versions', [], 'Modules.CciConsentHistory.Admin'),
                (string) $stats['versions'],
                'history'
            )
            . $this->renderMetric(
                $this->module->getTranslator()->trans('Recorded events', [], 'Modules.CciConsentHistory.Admin'),
                (string) $stats['acceptances'],
                'verified_user'
            )
            . $this->renderMetric(
                $this->module->getTranslator()->trans('Customers recorded', [], 'Modules.CciConsentHistory.Admin'),
                (string) $stats['customers'],
                'people'
            )
            . '</section>';
    }

    public function renderMetric(string $label, string $value, string $icon): string
    {
        return '<article class="cci-consent-history-metric">'
            . '<span class="material-icons" aria-hidden="true">' . $this->escapeHtml($icon) . '</span>'
            . '<div><span class="cci-consent-history-metric-label">' . $this->escapeHtml($label) . '</span>'
            . '<strong>' . $this->escapeHtml($value) . '</strong></div>'
            . '</article>';
    }

    public function renderConsentSettingsLinks(): string
    {
        $links = '';
        $sources = [
            'psgdpr' => 'Official GDPR Compliance settings',
            'ps_dataprivacy' => 'Customer data privacy block settings',
        ];
        foreach ($sources as $module => $label) {
            if (!Module::isInstalled($module)) {
                continue;
            }

            $url = $this->context->link->getAdminLink('AdminModules', true, [], ['configure' => $module]);
            $links .= '<a class="btn btn-outline-secondary" href="' . $this->escapeHtml($url) . '">'
                . '<i class="material-icons" aria-hidden="true">settings</i>'
                . $this->escapeHtml($this->module->getTranslator()->trans($label, [], 'Modules.CciConsentHistory.Admin'))
                . '</a>';
        }

        return $links;
    }

    private function escapeHtml($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
