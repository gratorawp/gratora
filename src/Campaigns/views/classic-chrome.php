<?php
/**
 * Classic-theme blank-canvas template for a campaign page whose Appearance
 * settings hide the theme header and/or footer. Renders the page content with a
 * minimal document shell so wp_head()/wp_footer() still run.
 *
 * @var array{header:bool,footer:bool} $gratora_chrome_flags (via $GLOBALS)
 */

defined('ABSPATH') || exit;

use Gratora\Campaigns\CampaignChrome;

CampaignChrome::openDocument((bool) ($GLOBALS['gratora_chrome_flags']['header'] ?? false));
?>
<main class="gratora-chrome-main">
    <?php
    while (have_posts()) {
        the_post();
        the_content();
    }
    ?>
</main>
<?php
CampaignChrome::closeDocument((bool) ($GLOBALS['gratora_chrome_flags']['footer'] ?? false));
