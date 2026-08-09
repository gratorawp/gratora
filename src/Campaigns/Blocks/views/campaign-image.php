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
<figure <?php echo get_block_wrapper_attributes(array_filter([
    'class' => $classes,
    'style' => $styleVars,
])); ?> data-block="dono/campaign-image">
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
