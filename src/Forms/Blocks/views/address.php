<?php
defined('ABSPATH') || exit;
/**
 * @var string $label
 * @var bool   $showLine1
 * @var bool   $showLine2
 * @var bool   $showCity
 * @var bool   $showRegion
 * @var bool   $showPostal
 * @var bool   $showCountry
 * @var bool   $requireLine1
 * @var bool   $requireCity
 * @var bool   $requireRegion
 * @var bool   $requirePostal
 * @var bool   $requireCountry
 * @var string $line1Label
 * @var string $line2Label
 * @var string $cityLabel
 * @var string $regionLabel
 * @var string $postalLabel
 * @var string $countryLabel
 */
$labelText   = $label !== ''        ? $label        : __('Mailing address', 'fundraising-toolkit');
$line1Text   = $line1Label !== ''   ? $line1Label   : __('Address line 1', 'fundraising-toolkit');
$line2Text   = $line2Label !== ''   ? $line2Label   : __('Apartment, suite, etc.', 'fundraising-toolkit');
$cityText    = $cityLabel !== ''    ? $cityLabel    : __('City', 'fundraising-toolkit');
$regionText  = $regionLabel !== ''  ? $regionLabel  : __('State / region', 'fundraising-toolkit');
$postalText  = $postalLabel !== ''  ? $postalLabel  : __('Postal code', 'fundraising-toolkit');
$countryText = $countryLabel !== '' ? $countryLabel : __('Country', 'fundraising-toolkit');
?>
<fieldset class="fundkit-block fundkit-block--address fundkit-address">
    <legend class="fundkit-address__legend"><?php echo esc_html($labelText); ?></legend>
    <div class="fundkit-address__grid">
        <?php if ($showLine1): ?>
            <label class="fundkit-address__field fundkit-address__field--full">
                <span class="fundkit-address__label"><?php echo esc_html($line1Text); ?></span>
                <input type="text" name="profile[address][line1]" autocomplete="address-line1"
                       <?php echo esc_attr($requireLine1 ? 'required' : ''); ?>>
            </label>
        <?php endif; ?>

        <?php if ($showLine2): ?>
            <label class="fundkit-address__field fundkit-address__field--full">
                <span class="fundkit-address__label"><?php echo esc_html($line2Text); ?></span>
                <input type="text" name="profile[address][line2]" autocomplete="address-line2">
            </label>
        <?php endif; ?>

        <?php if ($showCity): ?>
            <label class="fundkit-address__field">
                <span class="fundkit-address__label"><?php echo esc_html($cityText); ?></span>
                <input type="text" name="profile[address][city]" autocomplete="address-level2"
                       <?php echo esc_attr($requireCity ? 'required' : ''); ?>>
            </label>
        <?php endif; ?>

        <?php if ($showRegion): ?>
            <label class="fundkit-address__field">
                <span class="fundkit-address__label"><?php echo esc_html($regionText); ?></span>
                <input type="text" name="profile[address][region]" autocomplete="address-level1"
                       <?php echo esc_attr($requireRegion ? 'required' : ''); ?>>
            </label>
        <?php endif; ?>

        <?php if ($showPostal): ?>
            <label class="fundkit-address__field">
                <span class="fundkit-address__label"><?php echo esc_html($postalText); ?></span>
                <input type="text" name="profile[address][postal]" autocomplete="postal-code"
                       <?php echo esc_attr($requirePostal ? 'required' : ''); ?>>
            </label>
        <?php endif; ?>

        <?php if ($showCountry): ?>
            <label class="fundkit-address__field">
                <span class="fundkit-address__label"><?php echo esc_html($countryText); ?></span>
                <input type="text" name="profile[address][country]" autocomplete="country-name"
                       <?php echo esc_attr($requireCountry ? 'required' : ''); ?>>
            </label>
        <?php endif; ?>
    </div>
</fieldset>
