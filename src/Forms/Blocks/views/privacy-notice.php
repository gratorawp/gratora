<?php
defined('ABSPATH') || exit;
/**
 * @var string $text
 * @var string $linkText
 * @var string $align
 * @var string $url
 */

$cls = 'giveflow-block giveflow-block--privacy giveflow-privacy giveflow-privacy--' . preg_replace('/[^a-z]/', '', $align);
?>
<p class="<?php echo esc_attr($cls); ?>">
    <?php if ($text !== ''): ?>
        <?php echo esc_html($text); ?>
    <?php endif; ?>
    <?php if ($url !== '' && $linkText !== ''): ?>
        <?php if ($text !== ''): ?> <?php endif; ?>
        <a class="giveflow-privacy__link" href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener noreferrer">
            <?php echo esc_html($linkText); ?>
        </a>
    <?php endif; ?>
</p>
