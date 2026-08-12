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
$labelText      = $label !== '' ? $label : __('Direct my donation to', 'dono-fundraising-platform');
$emptyLabelText = $emptyLabel !== '' ? $emptyLabel : __('No specific fund', 'dono-fundraising-platform');
?>
<fieldset class="dono-block dono-block--fund dono-fund">
    <legend class="dono-fund__legend"><?php echo esc_html($labelText); ?></legend>
    <div class="dono-fund__options" role="radiogroup">
        <?php if ($allowEmpty):
            $checked = ($defaultId === '');
            ?>
            <label class="dono-fund__option<?php echo $checked ? ' is-selected' : ''; ?>">
                <input type="radio" name="fund_id" value="" <?php echo $checked ? 'checked' : ''; ?>>
                <span class="dono-fund__option-label"><?php echo esc_html($emptyLabelText); ?></span>
                <?php if ($emptyDescription !== ''): ?>
                    <span class="dono-fund__option-desc"><?php echo esc_html($emptyDescription); ?></span>
                <?php endif; ?>
            </label>
        <?php endif; ?>

        <?php foreach ($options as $o):
            $id     = (string) $o['id'];
            $oLabel = (string) $o['label'];
            $desc   = (string) $o['description'];

            if (empty($o['selectable'])): ?>
                <div class="dono-fund__group"><?php echo esc_html($oLabel !== '' ? $oLabel : $id); ?></div>
            <?php continue; endif;

            $checked = ($id === $defaultId);
            $isChild = ! empty($o['depth']);
            ?>
            <label class="dono-fund__option<?php echo $checked ? ' is-selected' : ''; ?><?php echo $isChild ? ' is-child' : ''; ?>">
                <input type="radio" name="fund_id" value="<?php echo esc_attr($id); ?>" <?php echo $checked ? 'checked' : ''; ?>>
                <span class="dono-fund__option-label"><?php echo esc_html($oLabel !== '' ? $oLabel : $id); ?></span>
                <?php if ($desc !== ''): ?>
                    <span class="dono-fund__option-desc"><?php echo esc_html($desc); ?></span>
                <?php endif; ?>
            </label>
        <?php endforeach; ?>
    </div>
</fieldset>
