<?php
defined('ABSPATH') || exit;
/**
 * @var string $label
 * @var string $align  left | center | right | full
 */
?>
<div class="fundkit-block fundkit-block--submit fundkit-block--align-<?php echo esc_attr($align); ?>"
     data-block="fundkit/submit-button">
<?php // type=button: this SSR fallback shows only without JS (the runtime
      // replaces the form's innerHTML on mount). A submit here would GET the
      // donor's inputs into the URL, since the form has no action/method. ?>
    <button type="button" class="fundkit-submit" disabled>
        <span class="fundkit-submit__label"><?php echo esc_html($label); ?></span>
    </button>
</div>
