<?php
defined('ABSPATH') || exit;
/**
 * @var int    $imageId
 * @var string $imageAlt
 * @var string $ratio
 * @var bool   $rounded
 * @var bool   $priority
 * @var string $styleVars
 */
$classes = 'dono-block dono-block--image is-ratio-' . $ratio . ($rounded ? ' is-rounded' : '');
?>
<figure <?php
// Core escapes these attributes; its own blocks print them the same way.
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
echo get_block_wrapper_attributes(array_filter([
    'class' => $classes,
    'style' => $styleVars,
]));
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
?> data-block="dono/campaign-image">
    <?php
    // By attachment, not URL: that is what supplies srcset and sizes.
    echo wp_get_attachment_image($imageId, 'large', false, [
        'class'         => 'dono-block__image',
        'alt'           => $imageAlt,
        'decoding'      => 'async',
        'loading'       => $priority ? 'eager' : 'lazy',
        'fetchpriority' => $priority ? 'high'  : 'auto',
    ]);
    ?>
</figure>
