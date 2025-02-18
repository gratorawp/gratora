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
<fieldset class="dono-block dono-block--radio dono-radio dono-radio--<?php echo esc_attr($layout); ?>">
    <?php if ($label !== ''): ?>
        <legend class="dono-radio__legend"><?php echo esc_html($label); ?></legend>
    <?php endif; ?>
    <div class="dono-radio__options">
        <?php foreach ($options as $i => $o):
            $optLabel = (string) $o['label'];
            $optValue = (string) $o['value'];
            $checked  = ($optValue === $defaultValue);
            ?>
            <label class="dono-radio__option<?php echo $checked ? ' is-selected' : ''; ?>">
                <input
                    type="radio"
                    name="custom[<?php echo esc_attr($field); ?>]"
                    value="<?php echo esc_attr($optValue); ?>"
                    <?php echo $checked ? 'checked' : ''; ?>
                    <?php echo $required ? 'required' : ''; ?>
                >
                <span class="dono-radio__option-label"><?php echo esc_html($optLabel !== '' ? $optLabel : $optValue); ?></span>
            </label>
        <?php endforeach; ?>
    </div>
</fieldset>
