<?php
defined('ABSPATH') || exit;
/**
 * @var string                                                                            $label
 * @var string                                                                            $helpText
 * @var list<array{key:string,label:string,description:string,required:bool,default:bool}> $purposes
 */
$labelText = $label !== '' ? $label : __('How can we stay in touch?', 'dono');
?>
<fieldset class="dono-block dono-block--consent dono-consent">
    <legend class="dono-consent__legend"><?php echo esc_html($labelText); ?></legend>
    <?php if ($helpText !== ''): ?>
        <p class="dono-consent__help"><?php echo esc_html($helpText); ?></p>
    <?php endif; ?>
    <div class="dono-consent__purposes">
        <?php foreach ($purposes as $p):
            $id          = (string) $p['key'];
            $pLabel      = (string) $p['label'];
            $desc        = (string) $p['description'];
            $required    = (bool)   $p['required'];
            // Pre-ticked only where the registry says so, and the registry is
            // the one place that decides it.
            $checked     = $required || (bool) $p['default'];
            ?>
            <label class="dono-consent__purpose">
                <input type="checkbox"
                       name="consents[<?php echo esc_attr($id); ?>]"
                       value="1"
                       <?php echo $checked ? 'checked' : ''; ?>
                       <?php echo $required ? 'required disabled' : ''; ?>>
                <?php if ($required): ?>
                    <input type="hidden" name="consents[<?php echo esc_attr($id); ?>]" value="1">
                <?php endif; ?>
                <span class="dono-consent__purpose-body">
                    <span class="dono-consent__purpose-label">
                        <?php echo esc_html($pLabel !== '' ? $pLabel : $id); ?>
                        <?php if ($required): ?>
                            <span class="dono-consent__required-pill"><?php esc_html_e('Required', 'dono'); ?></span>
                        <?php endif; ?>
                    </span>
                    <?php if ($desc !== ''): ?>
                        <span class="dono-consent__purpose-desc"><?php echo esc_html($desc); ?></span>
                    <?php endif; ?>
                </span>
            </label>
        <?php endforeach; ?>
    </div>
</fieldset>
