/**
 * What the canonical seeded form must carry.
 *
 * Every donor-form spec locates its block and asserts on it. A block that stops
 * reaching the form - dropped from the seeder, stripped on save, renamed in the
 * runtime - turns those specs into skips, and a green run then means nothing was
 * checked. Pinned here so the run fails at setup instead, naming what is gone.
 */
export const CANONICAL_FORM_KINDS = [
    'name',
    'email',
    'country',
    'address',
    'phone',
    'comment',
    'anonymous',
    'cover_fees',
    'consent',
    'date',
    'dropdown',
    'currency-switcher',
    'payment-gateways',
] as const;

type FormConfig = {
    steps?: Array<{ items?: Array<{ kind?: string; purposes?: Array<{ required?: boolean }> }> }>;
};

/**
 * The runtime config the shortcode inlines. JSON_HEX_TAG means no `</script>`
 * can appear inside a string, so the non-greedy match is safe. Core prints the
 * element, so the match holds for any attribute order.
 */
export function parseFormConfig(html: string): FormConfig {
    const match = /<script\b[^>]*\bdata-gratora-form-config\b[^>]*>([\s\S]*?)<\/script>/.exec(html);
    if (! match) {
        throw new Error('The page carries no Gratora form config. Run `wp --require=tests-e2e/cli/E2eSeedCommand.php gratora e2e-seed`.');
    }

    return JSON.parse(match[1]) as FormConfig;
}

/** @throws when the seeded form is missing a block the specs assert on. */
export function assertCanonicalForm(html: string, path: string): void {
    const items = (parseFormConfig(html).steps ?? []).flatMap((step) => step.items ?? []);
    const kinds = new Set(items.map((item) => item.kind));

    const missing = CANONICAL_FORM_KINDS.filter((kind) => ! kinds.has(kind));
    if (missing.length > 0) {
        throw new Error(
            `The form at ${path} is missing ${missing.join(', ')}. Every spec for those ` +
            'blocks would skip and the run would still pass. Re-run `wp --require=tests-e2e/cli/E2eSeedCommand.php gratora e2e-seed`.',
        );
    }

    // consent.spec.ts branches on exactly this, and the shortcode drops the
    // whole item when the org has no purposes matching the block's keys.
    const purposes = items.find((item) => item.kind === 'consent')?.purposes ?? [];
    const required = purposes.filter((p) => p.required === true).length;
    const optional = purposes.filter((p) => p.required !== true).length;
    if (required < 1 || optional < 1) {
        throw new Error(
            `The consent block at ${path} offers ${required} required and ${optional} optional ` +
            'purposes; the specs need one of each. Re-run `wp --require=tests-e2e/cli/E2eSeedCommand.php gratora e2e-seed`.',
        );
    }
}
