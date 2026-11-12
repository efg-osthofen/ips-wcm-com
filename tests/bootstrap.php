<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/Support/IpsStubs.php';
require __DIR__ . '/Support/FakeWcm.php';
require __DIR__ . '/Support/PrivateAccess.php';

// module.php pulls in libs/wcm-client/wcm.php itself (with require, not
// require_once), so it must be the only place that loads the client.
require __DIR__ . '/../WCMConnector/module.php';
