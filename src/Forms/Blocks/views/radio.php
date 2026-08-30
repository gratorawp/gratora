<?php
defined('ABSPATH') || exit;
/**
 * @var string                                                          $label
 * @var list<array{label:string,value:string,isDefault:bool}>           $options
 * @var bool                                                            $required
 * @var string                                                          $field
 * @var string                                                          $layout
 * @var string                                                          $defaultValue
 */
?>
<fieldset class="fundkit-block fundkit-block--radio fundkit-radio fundkit-radio--<?php echo esc_attr($layout); ?>">
    <?php if ($label !== ''): ?>
        <legend class="fundkit-radio__legend"><?php echo esc_html($label); ?></legend>
    <?php endif; ?>
    <div class="fundkit-radio__options">
        <?php foreach ($options as $i => $o):
            $optLabel = (string) $o['label'];
            $optValue = (string) $o['value'];
            $checked  = ($optValue === $defaultValue);
            ?>
            <label class="fundkit-radio__option<?php echo esc_attr($checked ? ' is-selected' : ''); ?>">
                <input
                    type="radio"
                    name="custom[<?php echo esc_attr($field); ?>]"
                    value="<?php echo esc_attr($optValue); ?>"
                    <?php echo esc_attr($checked ? 'checked' : ''); ?>
                    <?php echo esc_attr($required ? 'required' : ''); ?>
                >
                <span class="fundkit-radio__option-label"><?php echo esc_html($optLabel !== '' ? $optLabel : $optValue); ?></span>
            </label>
        <?php endforeach; ?>
    </div>
</fieldset>
