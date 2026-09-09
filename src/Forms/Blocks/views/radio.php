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
<fieldset class="gratora-block gratora-block--radio gratora-radio gratora-radio--<?php echo esc_attr($layout); ?>">
    <?php if ($label !== ''): ?>
        <legend class="gratora-radio__legend"><?php echo esc_html($label); ?></legend>
    <?php endif; ?>
    <div class="gratora-radio__options">
        <?php foreach ($options as $i => $o):
            $optLabel = (string) $o['label'];
            $optValue = (string) $o['value'];
            $checked  = ($optValue === $defaultValue);
            ?>
            <label class="gratora-radio__option<?php echo esc_attr($checked ? ' is-selected' : ''); ?>">
                <input
                    type="radio"
                    name="custom[<?php echo esc_attr($field); ?>]"
                    value="<?php echo esc_attr($optValue); ?>"
                    <?php echo esc_attr($checked ? 'checked' : ''); ?>
                    <?php echo esc_attr($required ? 'required' : ''); ?>
                >
                <span class="gratora-radio__option-label"><?php echo esc_html($optLabel !== '' ? $optLabel : $optValue); ?></span>
            </label>
        <?php endforeach; ?>
    </div>
</fieldset>
