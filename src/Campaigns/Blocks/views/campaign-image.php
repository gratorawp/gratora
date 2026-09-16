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
$classes = 'gratora-block gratora-block--image is-ratio-' . $ratio . ($rounded ? ' is-rounded' : '');
?>
<figure <?php
echo wp_kses_data(get_block_wrapper_attributes(array_filter([
    'class' => $classes,
    'style' => $styleVars,
])));
?> data-block="gratora/campaign-image">
    <?php
    // Attachment IDs supply srcset and sizes.
    echo wp_get_attachment_image($imageId, 'large', false, [
        'class'         => 'gratora-block__image',
        'alt'           => $imageAlt,
        'decoding'      => 'async',
        'loading'       => $priority ? 'eager' : 'lazy',
        'fetchpriority' => $priority ? 'high'  : 'auto',
    ]);
    ?>
</figure>
