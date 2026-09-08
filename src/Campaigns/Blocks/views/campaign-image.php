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
$classes = 'fundkit-block fundkit-block--image is-ratio-' . $ratio . ($rounded ? ' is-rounded' : '');
?>
<figure <?php
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() escapes what it returns; core's own blocks print it the same way.
echo get_block_wrapper_attributes(array_filter([
    'class' => $classes,
    'style' => $styleVars,
]));
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
?> data-block="fundkit/campaign-image">
    <?php
    // Attachment IDs supply srcset and sizes.
    echo wp_get_attachment_image($imageId, 'large', false, [
        'class'         => 'fundkit-block__image',
        'alt'           => $imageAlt,
        'decoding'      => 'async',
        'loading'       => $priority ? 'eager' : 'lazy',
        'fetchpriority' => $priority ? 'high'  : 'auto',
    ]);
    ?>
</figure>
