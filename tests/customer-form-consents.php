<?php

// Included by customer-history-integration.php, using the same isolated database prefix.
$pdo->exec('CREATE TABLE `' . _DB_PREFIX_ . 'customer` (id_customer INT PRIMARY KEY, newsletter TINYINT, optin TINYINT)');
$pdo->exec('INSERT INTO `' . _DB_PREFIX_ . 'customer` VALUES (700,0,0),(701,0,0),(703,1,1)');
$preferencesContext = new Context();
$preferencesContext->shop = (object) ['id' => 1];
$preferencesContext->language = (object) ['id' => 1];
$preferencesContext->customer = new Customer(700);
$preferencesContext->controller = new FrontController();
$preferencesContext->cookie = (object) [];
$sourceRegistry = new CciCustomerConsentSourceRegistry();
foreach (['newsletter' => 'newsletter_subscription', 'optin' => 'partner_offers'] as $field => $purpose) {
    $sourceRegistry->register($field, $purpose, 'prestashop', 'choice', static function (int $customer) use ($repository, $field): ?bool {
        $values = $repository->getCustomerPreferences($customer);

        return array_key_exists($field, $values) ? (bool) $values[$field] : null;
    });
}
$sourceRegistry->register('customer_privacy', 'customer_data_privacy', 'ps_dataprivacy', 'acknowledgement');
$preferencesRecorder = new CciCustomerConsentRecorder($repository, $preferencesContext, $sourceRegistry);

$renderChoices = static function (CciCustomerConsentRecorder $recorder, string $newsletter = 'Newsletter<br><em>Cancel anytime.</em>'): array {
    $labels = [
        'newsletter' => $newsletter,
        'optin' => 'Receive offers from our partners',
        'customer_privacy' => 'Customer data privacy<br><em>We use your details for your account.</em>',
    ];
    $snapshots = [];
    foreach ($labels as $name => $label) {
        $field = ['type' => 'checkbox', 'name' => $name, 'label' => $label];
        $recorder->decorateField($field);
        check((bool) preg_match('/value="([^"]+)"/', $field['label'], $matches), 'Each supported native field must carry a signed snapshot.');
        $snapshots[$name] = $matches[1];
    }

    return $snapshots;
};
$savePreferences = static function (bool $enabled, array $snapshots) use ($pdo, $preferencesContext, $preferencesRecorder): void {
    $_POST = ['cci_customer_consent' => $snapshots, 'customer_privacy' => '1'];
    if ($enabled) {
        $_POST['newsletter'] = '1';
        $_POST['optin'] = '1';
    }
    $preferencesRecorder->captureBeforeUpdate($preferencesContext->customer);
    $pdo->exec('UPDATE `' . _DB_PREFIX_ . 'customer` SET newsletter=' . (int) $enabled . ', optin=' . (int) $enabled . ' WHERE id_customer=700');
    $preferencesRecorder->recordSubmission($preferencesContext->customer);
};

$_SERVER['REQUEST_METHOD'] = 'POST';
$snapshots = $renderChoices($preferencesRecorder);
check($repository->countCustomerHistory(700, 1) === 0, 'Rendering checkboxes must not create consent events.');
$savePreferences(true, $snapshots);
$history = $repository->getCustomerHistory(700, 1);
check(count($history) === 3, 'A successful save must record newsletter, partner offers and separate data privacy.');
$newsletterHistory = array_values(array_filter($history, static fn ($row) => $row['purpose_key'] === 'newsletter_subscription'));
check($newsletterHistory[0]['content_text'] === 'Newsletter Cancel anytime.', 'Retain both the newsletter label and conditions, with readable HTML line breaks.');
$savePreferences(true, $snapshots);
check($repository->countCustomerHistory(700, 1) === 3, 'Retrying the same signed form must be idempotent.');
$savePreferences(true, $renderChoices($preferencesRecorder));
check($repository->countCustomerHistory(700, 1) === 4, 'An unchanged marketing choice must not be recorded again on every profile save.');
$savePreferences(false, $renderChoices($preferencesRecorder));
$history = $repository->getCustomerHistory(700, 1);
check(count($history) === 7, 'Unchecking previously granted marketing choices must save two withdrawals and the privacy acknowledgement.');
check($repository->getLatestCustomerConsent(700, 1, 'newsletter_subscription')['acceptance_source'] === 'customer_choice_withdrawn', 'Keep a distinct newsletter withdrawal.');
check($repository->getLatestCustomerConsent(700, 1, 'partner_offers')['acceptance_source'] === 'customer_choice_withdrawn', 'Keep a distinct partner-offer withdrawal.');
$savePreferences(true, $renderChoices($preferencesRecorder, 'Newsletter wording B'));
check($repository->countCustomerHistory(700, 1) === 10, 'Granting a choice again after withdrawal must create new events.');
$savePreferences(false, $renderChoices($preferencesRecorder, 'Newsletter wording C'));
$lastNewsletter = $repository->getLatestCustomerConsent(700, 1, 'newsletter_subscription');
check($lastNewsletter['content_text'] === 'Newsletter wording B', 'Withdrawal must refer to the previously granted wording when known.');

$presenter = (new ReflectionClass(Cci_Consent_History::class))->newInstanceWithoutConstructor();
$presenter->context = $preferencesContext;
$presented = $presenter->getCustomerHistory(700, 1);
$withdrawals = array_filter($presented, static fn ($row) => $row['status_label'] === 'Withdrawn');
check(count($withdrawals) === 4, 'Present grants and withdrawals separately to the customer.');
$export = json_decode($presenter->hookActionExportGDPRData(['id' => 700]), true, 512, JSON_THROW_ON_ERROR);
check(count(array_filter($export, static fn ($row) => $row['Status'] === 'Withdrawn')) === 4, 'Native GDPR exports must include withdrawals.');

$original = $renderChoices($preferencesRecorder);
$preferencesContext->customer = new Customer(701);
$_POST = ['cci_customer_consent' => $original, 'newsletter' => '1', 'optin' => '1', 'customer_privacy' => '1'];
$preferencesRecorder->recordSubmission($preferencesContext->customer);
check($repository->countCustomerHistory(701, 1) === 0, 'A form signed for another customer must not create history.');
$preferencesContext->customer = new Customer(700);
$beforeTampering = $repository->countCustomerHistory(700, 1);
$_POST['cci_customer_consent'] = ['newsletter' => $original['optin']];
$preferencesRecorder->recordSubmission($preferencesContext->customer);
check($repository->countCustomerHistory(700, 1) === $beforeTampering, 'A signature for another checkbox must not be accepted.');
$sourceRegistry->register('newsletter', 'newsletter_subscription', 'ps_emailsubscription', 'choice');
Module::$enabled = false;
$field = ['name' => 'newsletter', 'type' => 'checkbox', 'label' => 'Newsletter'];
$preferencesRecorder->decorateField($field);
check($field['label'] === 'Newsletter', 'A disabled source must not produce a signed consent field.');
Module::$enabled = true;
$sourceRegistry->register('newsletter', 'newsletter_subscription', 'prestashop', 'choice', static fn (int $id) => (bool) $repository->getCustomerPreferences($id)['newsletter']);

// The same native fields also exist during account creation, before a customer ID exists.
$preferencesContext->controller->php_self = 'registration';
$preferencesContext->customer = new Customer(0);
$registrationSnapshots = $renderChoices($preferencesRecorder);
$preferencesContext->customer = new Customer(703);
$_POST = ['cci_customer_consent' => $registrationSnapshots, 'newsletter' => '1', 'optin' => '1', 'customer_privacy' => '1'];
$preferencesRecorder->recordSubmission($preferencesContext->customer, true);
check($repository->countCustomerHistory(703, 1) === 3, 'Successful registration must record the three native choices under the new customer ID.');
foreach ($presenter->getCustomerHistory(703, 1) as $row) {
    check($row['source_label'] === 'Account creation', 'Registration choices must retain their registration source.');
}

// A third-party checkbox uses the same engine without any changes to product code.
$sourceRegistry->register('sms_permission', 'sms_marketing', 'example_sms_module', 'choice');
$preferencesContext->controller->php_self = 'identity';
$preferencesContext->customer = new Customer(701);
$customField = ['name' => 'sms_permission', 'type' => 'checkbox', 'label' => 'Send me SMS offers<br><em>Withdraw at any time.</em>'];
$preferencesRecorder->decorateField($customField);
check((bool) preg_match('/value="([^"]+)"/', $customField['label'], $customMatch), 'A registered third-party field must receive a snapshot automatically.');
$_POST = ['cci_customer_consent' => ['sms_permission' => $customMatch[1]], 'sms_permission' => '1'];
$preferencesRecorder->recordSubmission($preferencesContext->customer);
$customHistory = $presenter->getCustomerHistory(701, 1);
check(count($customHistory) === 1 && $customHistory[0]['purpose_label'] === 'Send me SMS offers', 'Discover the purpose label from the rendered custom field.');
unset($_POST['sms_permission']);
$preferencesRecorder->recordSubmission($preferencesContext->customer);
check($repository->getLatestCustomerConsent(701, 1, 'sms_marketing')['acceptance_source'] === 'customer_choice_withdrawn', 'Custom sources must use the same withdrawal mechanism.');
