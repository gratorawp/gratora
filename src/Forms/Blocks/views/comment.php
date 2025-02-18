<?php
defined('ABSPATH') || exit;
/**
 * @var string $label
 * @var string $placeholder
 * @var bool   $required
 */
?>
<label class="dono-block dono-block--comment dono-comment">
    <span class="dono-comment__label"><?php echo esc_html((string) $label); ?></span>
    <textarea
        name="note_to_org"
        placeholder="<?php echo esc_attr((string) $placeholder); ?>"
        rows="3"
        maxlength="5000"
        <?php echo $required ? 'required' : ''; ?>
    ></textarea>
</label>
