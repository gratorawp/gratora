<?php
/**
 * Classic-theme blank-canvas template for a campaign page whose Appearance
 * settings hide the theme header and/or footer. Renders the page content with a
 * minimal document shell so wp_head()/wp_footer() still run.
 *
 * @var array{header:bool,footer:bool} $giveflow_chrome_flags (via $GLOBALS)
 */

defined('ABSPATH') || exit;

use GiveFlow\Campaigns\CampaignChrome;

$flags = $GLOBALS['giveflow_chrome_flags'] ?? ['header' => false, 'footer' => false];

CampaignChrome::openDocument((bool) $flags['header']);
?>
<main class="giveflow-chrome-main">
    <?php
    while (have_posts()) {
        the_post();
        the_content();
    }
    ?>
</main>
<?php
CampaignChrome::closeDocument((bool) $flags['footer']);
