<?php
defined('ABSPATH') || exit;
/**
 * @var array  $types       array of ['id' => string, 'label' => string]
 * @var bool   $allowNotify
 * @var bool   $allowAnnual
 * @var string $label
 */
?>
<fieldset class="dono-block dono-block--tribute dono-tribute">
    <legend><?php echo esc_html((string) $label); ?></legend>
    <div class="dono-tribute__types">
        <?php foreach ($types as $t): ?>
            <label>
                <input type="radio" name="tribute_type" value="<?php echo esc_attr((string) $t['id']); ?>">
                <?php echo esc_html((string) $t['label']); ?>
            </label>
        <?php endforeach; ?>
    </div>
    <input type="text" name="tribute_name" placeholder="<?php esc_attr_e('Name of the person', 'dono'); ?>">
    <?php if ($allowNotify): ?>
        <input type="email" name="tribute_notify_email" placeholder="<?php esc_attr_e('Notify someone (optional email)', 'dono'); ?>">
        <textarea name="tribute_message" placeholder="<?php esc_attr_e('Personal message (optional)', 'dono'); ?>" rows="2" maxlength="500"></textarea>
    <?php endif; ?>
    <?php if ($allowAnnual): ?>
        <label class="dono-tribute__annual">
            <input type="checkbox" name="tribute_convert_to_annual" value="1">
            <?php esc_html_e('Remember this person every year on this date with a matching donation', 'dono'); ?>
        </label>
    <?php endif; ?>
</fieldset>
