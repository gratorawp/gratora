<?php

declare(strict_types=1);

namespace Gratora\Forms\Rendering;

/**
 * Core's statement of the embed shape: the fields it reads out of
 * `config.embed`, the body keys it sends on submit, and the `postMessage`
 * envelope it emits. Bumped whenever any of those change.
 *
 * Core ships from wordpress.org and auto-updates; an add-on rendering a
 * document on a partner page ships from the licence server and does not. A
 * number it can read lets it refuse to mint a snippet or serve a document
 * against a core release whose branch it does not know, rather than degrade
 * silently on every partner page.
 *
 * @since 1.1.0
 */
final class EmbedContract
{
    public const VERSION = 1;
}
