<?php
defined('ABSPATH') || exit;
/**
 * @var string                                                                              $label
 * @var list<array{id:string,label:string,description:string,depth:int,selectable:bool}>     $options
 * @var string                                                                              $defaultId
 * @var bool                                                                                $allowEmpty
 * @var string                                                                              $emptyLabel
 * @var string                                                                              $emptyDescription
 */
$labelText      = $label !== '' ? $label : __('Direct my donation to', 'fundkit-fundraising-campaigns');
$emptyLabelText = $emptyLabel !== '' ? $emptyLabel : __('No specific fund', 'fundkit-fundraising-campaigns');
?>
<fieldset class="fundkit-block fundkit-block--fund fundkit-fund">
    <legend class="fundkit-fund__legend"><?php echo esc_html($labelText); ?></legend>
    <div class="fundkit-fund__options" role="radiogroup">
        <?php if ($allowEmpty):
            $checked = ($defaultId === '');
            ?>
            <label class="fundkit-fund__option<?php echo esc_attr($checked ? ' is-selected' : ''); ?>">
                <input type="radio" name="fund_id" value="" <?php echo esc_attr($checked ? 'checked' : ''); ?>>
                <span class="fundkit-fund__option-label"><?php echo esc_html($emptyLabelText); ?></span>
                <?php if ($emptyDescription !== ''): ?>
                    <span class="fundkit-fund__option-desc"><?php echo esc_html($emptyDescription); ?></span>
                <?php endif; ?>
            </label>
        <?php endif; ?>

        <?php foreach ($options as $o):
            $id     = (string) $o['id'];
            $oLabel = (string) $o['label'];
            $desc   = (string) $o['description'];

            if (empty($o['selectable'])): ?>
                <div class="fundkit-fund__group"><?php echo esc_html($oLabel !== '' ? $oLabel : $id); ?></div>
            <?php continue; endif;

            $checked = ($id === $defaultId);
            $isChild = ! empty($o['depth']);
            ?>
            <label class="fundkit-fund__option<?php echo esc_attr($checked ? ' is-selected' : ''); ?><?php echo esc_attr($isChild ? ' is-child' : ''); ?>">
                <input type="radio" name="fund_id" value="<?php echo esc_attr($id); ?>" <?php echo esc_attr($checked ? 'checked' : ''); ?>>
                <span class="fundkit-fund__option-label"><?php echo esc_html($oLabel !== '' ? $oLabel : $id); ?></span>
                <?php if ($desc !== ''): ?>
                    <span class="fundkit-fund__option-desc"><?php echo esc_html($desc); ?></span>
                <?php endif; ?>
            </label>
        <?php endforeach; ?>
    </div>
</fieldset>
