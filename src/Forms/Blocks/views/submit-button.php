<?php
defined('ABSPATH') || exit;
/**
 * @var string $label
 * @var string $align  left | center | right | full
 */
?>
<div class="dono-block dono-block--submit dono-block--align-<?php echo esc_attr($align); ?>"
     data-block="dono/submit-button">
<?php // type=button: this SSR fallback shows only without JS (the runtime
      // replaces the form's innerHTML on mount). A submit here would GET the
      // donor's inputs into the URL, since the form has no action/method. ?>
    <button type="button" class="dono-submit" disabled>
        <span class="dono-submit__label"><?php echo esc_html($label); ?></span>
    </button>
</div>
