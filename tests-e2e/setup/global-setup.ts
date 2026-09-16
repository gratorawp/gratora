import { request, type FullConfig } from '@playwright/test';

import { assertCanonicalForm } from './fixture-contract';

function requireEnv(name: string): string {
    const v = process.env[name];
    if (! v || v.trim() === '') {
        throw new Error(
            `Missing env ${name}. Set GRATORA_E2E_URL (e.g. http://localhost:10075) in ` +
            `your shell or tests-e2e/.env. See tests-e2e/README.md.`,
        );
    }
    return v;
}

/**
 * Reachability-only setup. The donor-form suite needs a kitchen-sink form
 * (GRATORA_E2E_FORM_PATH), and the variant forms their own paths. Each check runs
 * only when its env is present, so a partially seeded site still runs what it
 * can. Specs whose fixture is missing skip themselves with a clear reason
 * rather than fail the run.
 */
export default async function globalSetup(_config: FullConfig): Promise<void> {
    const baseURL = requireEnv('GRATORA_E2E_URL');
    const ctx     = await request.newContext({ baseURL });

    try {
        const home = await ctx.get('/');
        if (home.status() >= 500) {
            throw new Error(`Site not reachable: GET ${baseURL}/ -> ${home.status()}.`);
        }

        const formPath = process.env.GRATORA_E2E_FORM_PATH;
        if (formPath && formPath.trim() !== '') {
            const res = await ctx.get(formPath);
            if (! res.ok()) {
                throw new Error(
                    `Form page not reachable: GET ${baseURL}${formPath} -> ${res.status()}. ` +
                    `Run \`wp --require=tests-e2e/cli/E2eSeedCommand.php gratora e2e-seed\`. See tests-e2e/README.md.`,
                );
            }
            const html = await res.text();
            if (! /<form[^>]*class="[^"]*gratora-donation-form/.test(html)) {
                throw new Error(
                    `Page ${formPath} did not render a Gratora donation form. ` +
                    `Run \`wp --require=tests-e2e/cli/E2eSeedCommand.php gratora e2e-seed\` and ensure the form is published.`,
                );
            }
            assertCanonicalForm(html, formPath);
        }

        const paymentPath = process.env.GRATORA_E2E_PAYMENT_FORM_PATH;
        if (paymentPath && paymentPath.trim() !== '') {
            const res = await ctx.get(paymentPath);
            if (! res.ok()) {
                throw new Error(
                    `Payment form page not reachable: GET ${baseURL}${paymentPath} -> ${res.status()}. ` +
                    `Run \`wp --require=tests-e2e/cli/E2eSeedCommand.php gratora e2e-seed\`. See tests-e2e/README.md.`,
                );
            }
        }

        const condPath = process.env.GRATORA_E2E_CONDITIONAL_FORM_PATH;
        if (condPath && condPath.trim() !== '') {
            const res = await ctx.get(condPath);
            if (! res.ok()) {
                throw new Error(
                    `Conditional form page not reachable: GET ${baseURL}${condPath} -> ${res.status()}. ` +
                    `Run \`wp --require=tests-e2e/cli/E2eSeedCommand.php gratora e2e-seed\`. See tests-e2e/README.md.`,
                );
            }
        }

        const customFieldsPath = process.env.GRATORA_E2E_CUSTOM_FIELDS_FORM_PATH;
        if (customFieldsPath && customFieldsPath.trim() !== '') {
            const res = await ctx.get(customFieldsPath);
            if (! res.ok()) {
                throw new Error(
                    `Custom-fields form page not reachable: GET ${baseURL}${customFieldsPath} -> ${res.status()}. ` +
                    `Run \`wp --require=tests-e2e/cli/E2eSeedCommand.php gratora e2e-seed\`. See tests-e2e/README.md.`,
                );
            }
        }

        const layoutPath = process.env.GRATORA_E2E_LAYOUT_FORM_PATH;
        if (layoutPath && layoutPath.trim() !== '') {
            const res = await ctx.get(layoutPath);
            if (! res.ok()) {
                throw new Error(
                    `Layout form page not reachable: GET ${baseURL}${layoutPath} -> ${res.status()}. ` +
                    `Run \`wp --require=tests-e2e/cli/E2eSeedCommand.php gratora e2e-seed\`. See tests-e2e/README.md.`,
                );
            }
        }
    } finally {
        await ctx.dispose();
    }
}
