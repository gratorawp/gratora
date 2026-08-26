<?php
defined('ABSPATH') || exit;
/**
 * @var string $label
 * @var string $helpText
 * @var string $style
 * @var string $defaultFrequency
 *
 * Server-rendered (no-JS) fallback. The runtime picks the allowed frequencies
 * from the form settings and renders the live pill row; this fallback shows
 * a single hidden value so the no-JS submission still includes a frequency.
 */
$labelText = $label !== '' ? $label : __('Make this recurring', 'giveflow-fundraising-campaigns');
?>
<fieldset class="giveflow-block giveflow-block--recurring giveflow-recurring giveflow-recurring--<?php echo esc_attr($style); ?>">
    <legend class="giveflow-recurring__legend"><?php echo esc_html($labelText); ?></legend>
    <?php if ($helpText !== ''): ?>
        <p class="giveflow-recurring__help"><?php echo esc_html($helpText); ?></p>
    <?php endif; ?>
    <input type="hidden" name="frequency" value="<?php echo esc_attr($defaultFrequency); ?>">
</fieldset>
