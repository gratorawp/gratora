<?php
defined('ABSPATH') || exit;
/**
 * @var string                                                                            $label
 * @var string                                                                            $helpText
 * @var list<array{key:string,label:string,description:string,required:bool,default:bool}> $purposes
 */
$labelText = $label !== '' ? $label : __('How can we stay in touch?', 'giveflow-fundraising-campaigns');
?>
<fieldset class="giveflow-block giveflow-block--consent giveflow-consent">
    <legend class="giveflow-consent__legend"><?php echo esc_html($labelText); ?></legend>
    <?php if ($helpText !== ''): ?>
        <p class="giveflow-consent__help"><?php echo esc_html($helpText); ?></p>
    <?php endif; ?>
    <div class="giveflow-consent__purposes">
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
            <label class="giveflow-consent__purpose">
                <input type="checkbox"
                       name="consents[<?php echo esc_attr($id); ?>]"
                       value="1"
                       <?php echo esc_attr($checked ? 'checked' : ''); ?>
                       <?php echo esc_attr($required ? 'required' : ''); ?>>
                <span class="giveflow-consent__purpose-body">
                    <span class="giveflow-consent__purpose-label">
                        <?php echo esc_html($pLabel !== '' ? $pLabel : $id); ?>
                        <?php if ($required): ?>
                            <span class="giveflow-consent__required-pill"><?php esc_html_e('Required', 'giveflow-fundraising-campaigns'); ?></span>
                        <?php endif; ?>
                    </span>
                    <?php if ($desc !== ''): ?>
                        <span class="giveflow-consent__purpose-desc"><?php echo esc_html($desc); ?></span>
                    <?php endif; ?>
                </span>
            </label>
        <?php endforeach; ?>
    </div>
</fieldset>
