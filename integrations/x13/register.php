<?php

use Module\CciConsentHistory\Import\ImporterInterface;

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/CciX13ConsentStorage.php';
require_once __DIR__ . '/CciX13ConsentImporter.php';

return static function (CciConsentHistoryRepository $repository): ImporterInterface {
    return new CciX13ConsentImporter($repository);
};
