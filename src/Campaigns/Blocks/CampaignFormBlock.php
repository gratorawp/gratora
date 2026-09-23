<?php

declare(strict_types=1);

namespace Gratora\Campaigns\Blocks;

/**
 * A page block that carries the donation form. The form prints its own config
 * as a script and its editor preview as a whole document, neither of which an
 * allow-list can pass, so the block hands back its markup and the form apart.
 *
 * @since 1.0.0
 */
abstract class CampaignFormBlock extends CampaignPageBlock
{
    /** @since 1.0.0 */
    abstract public function render(array $attrs): EmbeddedForm;
}
