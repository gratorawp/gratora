<?php

declare(strict_types=1);

namespace Gratora\Campaigns\Blocks;

/**
 * A page block whose output is one string of markup.
 *
 * @since 1.0.0
 */
abstract class CampaignBlock extends CampaignPageBlock
{
    /** @since 1.0.0 */
    abstract public function render(array $attrs, string $content): string;
}
