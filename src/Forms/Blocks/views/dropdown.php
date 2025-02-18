<?php
defined('ABSPATH') || exit;
/**
 * @var string                                                          $label
 * @var string                                                          $placeholder
 * @var list<array{label:string,value:string,isDefault:bool}>           $options
 * @var bool                                                            $required
 * @var string                                                          $field
 * @var string                                                          $defaultValue
 */
?>
<label class="dono-block dono-block--dropdown dono-dropdown">
    <?php if ($label !== ''): ?>
        <span class="dono-dropdown__label"><?php echo esc_html($label); ?></span>
    <?php endif; ?>
    <select
        name="custom[<?php echo esc_attr($field); ?>]"
        <?php echo $required ? 'required' : ''; ?>
    >
        <?php if ($placeholder !== ''): ?>
            <option value=""><?php echo esc_html($placeholder); ?></option>
        <?php endif; ?>
        <?php foreach ($options as $o):
            $optLabel = (string) $o['label'];
            $optValue = (string) $o['value'];
            $selected = ($optValue === $defaultValue);
            ?>
            <option value="<?php echo esc_attr($optValue); ?>" <?php echo $selected ? 'selected' : ''; ?>>
                <?php echo esc_html($optLabel !== '' ? $optLabel : $optValue); ?>
            </option>
        <?php endforeach; ?>
    </select>
</label>
