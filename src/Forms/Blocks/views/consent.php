<?php
defined('ABSPATH') || exit;
/**
 * @var string                                                                            $label
 * @var string                                                                            $helpText
 * @var list<array{key:string,label:string,description:string,required:bool,default:bool}> $purposes
 */
$labelText = $label !== '' ? $label : __('How can we stay in touch?', 'fundkit-fundraising-campaigns');
?>
<fieldset class="fundkit-block fundkit-block--consent fundkit-consent">
    <legend class="fundkit-consent__legend"><?php echo esc_html($labelText); ?></legend>
    <?php if ($helpText !== ''): ?>
        <p class="fundkit-consent__help"><?php echo esc_html($helpText); ?></p>
    <?php endif; ?>
    <div class="fundkit-consent__purposes">
        <?php foreach ($purposes as $p):
            $id          = (string) $p['key'];
            $pLabel      = (string) $p['label'];
            $desc        = (string) $p['description'];
            $required    = (bool)   $p['required'];
            // A required purpose never starts granted: the consent row is
            // evidence of something the donor did, and a box they could not
            // move is not it. The registry decides the rest.
            $checked     = ! $required && (bool) $p['default'];
            ?>
            <label class="fundkit-consent__purpose">
                <input type="checkbox"
                       name="consents[<?php echo esc_attr($id); ?>]"
                       value="1"
                       <?php echo esc_attr($checked ? 'checked' : ''); ?>
                       <?php echo esc_attr($required ? 'required' : ''); ?>>
                <span class="fundkit-consent__purpose-body">
                    <span class="fundkit-consent__purpose-label">
                        <?php echo esc_html($pLabel !== '' ? $pLabel : $id); ?>
                        <?php if ($required): ?>
                            <span class="fundkit-consent__required-pill"><?php esc_html_e('Required', 'fundkit-fundraising-campaigns'); ?></span>
                        <?php endif; ?>
                    </span>
                    <?php if ($desc !== ''): ?>
                        <span class="fundkit-consent__purpose-desc"><?php echo esc_html($desc); ?></span>
                    <?php endif; ?>
                </span>
            </label>
        <?php endforeach; ?>
    </div>
</fieldset>
