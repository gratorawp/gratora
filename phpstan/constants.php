<?php

/**
 * Constants static analysis needs and cannot get any other way.
 *
 * The plugin's own are defined at the top of giveflow.php from
 * plugin_dir_path(), which cannot run outside WordPress, so the real file
 * cannot be bootstrapped here. WordPress's time constants and ABSPATH are not
 * in the stub package. Only the fact that they exist matters, except the paths,
 * which have to point at the real plugin root so `require` targets resolve.
 */

declare(strict_types=1);

define('GIVEFLOW_VERSION', '0.0.0');
define('GIVEFLOW_DB_VERSION', '0.0.0');
define('GIVEFLOW_FILE', dirname(__DIR__) . '/giveflow.php');
define('GIVEFLOW_DIR', dirname(__DIR__) . '/');
define('GIVEFLOW_URL', 'https://example.test/');

define('ABSPATH', dirname(__DIR__, 4) . '/');
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);
define('WEEK_IN_SECONDS', 604800);
define('MONTH_IN_SECONDS', 2592000);
define('YEAR_IN_SECONDS', 31536000);
