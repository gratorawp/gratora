<?php
defined('ABSPATH') || exit;
/**
 * @var string $label
 * @var string $align  left | center | right | full
 */
?>
<div class="gratora-block gratora-block--submit gratora-block--align-<?php echo esc_attr($align); ?>"
     data-block="gratora/submit-button">
<?php // type=button: this SSR fallback shows only without JS (the runtime
      // replaces the form's innerHTML on mount). A submit here would GET the
      // donor's inputs into the URL, since the form has no action/method. ?>
    <button type="button" class="gratora-submit" disabled>
        <span class="gratora-submit__label"><?php echo esc_html($label); ?></span>
    </button>
</div>
