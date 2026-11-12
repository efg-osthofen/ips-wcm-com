<?php

declare(strict_types=1);

// module.php starts with the short open tag "<?". Without short_open_tag the
// file is treated as plain text and WCMConnector is silently undefined.
if (!filter_var(ini_get('short_open_tag'), FILTER_VALIDATE_BOOLEAN)) {
    fwrite(STDERR, "short_open_tag must be enabled. Run the tests with: composer test\n");
    exit(1);
}

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/Support/IpsStubs.php';
require __DIR__ . '/Support/FakeWcm.php';
require __DIR__ . '/Support/PrivateAccess.php';

// module.php pulls in libs/wcm-client/wcm.php itself (with require, not
// require_once), so it must be the only place that loads the client.
require __DIR__ . '/../WCMConnector/module.php';
