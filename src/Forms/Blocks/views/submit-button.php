<?php
defined('ABSPATH') || exit;
/**
 * @var string $label
 * @var string $align  left | center | right | full
 */
?>
<div class="giveflow-block giveflow-block--submit giveflow-block--align-<?php echo esc_attr($align); ?>"
     data-block="giveflow/submit-button">
<?php // type=button: this SSR fallback shows only without JS (the runtime
      // replaces the form's innerHTML on mount). A submit here would GET the
      // donor's inputs into the URL, since the form has no action/method. ?>
    <button type="button" class="giveflow-submit" disabled>
        <span class="giveflow-submit__label"><?php echo esc_html($label); ?></span>
    </button>
</div>
