<?php
defined('ABSPATH') || exit;
/**
 * @var string                                                          $label
 * @var list<array{label:string,value:string,isDefault:bool}>           $options
 * @var bool                                                            $required
 * @var string                                                          $field
 * @var int                                                             $min
 * @var int                                                             $max
 */
?>
<fieldset
    class="dono-block dono-block--multi-select dono-multi-select"
    data-min="<?php echo esc_attr((string) $min); ?>"
    data-max="<?php echo esc_attr((string) $max); ?>"
>
    <?php if ($label !== ''): ?>
        <legend class="dono-multi-select__legend"><?php echo esc_html($label); ?></legend>
    <?php endif; ?>
    <div class="dono-multi-select__options">
        <?php foreach ($options as $i => $o):
            $optLabel = (string) $o['label'];
            $optValue = (string) $o['value'];
            $checked  = ! empty($o['isDefault']);
            ?>
            <label class="dono-multi-select__option<?php echo $checked ? ' is-selected' : ''; ?>">
                <input
                    type="checkbox"
                    name="custom[<?php echo esc_attr($field); ?>][]"
                    value="<?php echo esc_attr($optValue); ?>"
                    <?php echo $checked ? 'checked' : ''; ?>
                    <?php echo ($required && $i === 0) ? 'required' : ''; ?>
                >
                <span class="dono-multi-select__option-label"><?php echo esc_html($optLabel !== '' ? $optLabel : $optValue); ?></span>
            </label>
        <?php endforeach; ?>
    </div>
</fieldset>
