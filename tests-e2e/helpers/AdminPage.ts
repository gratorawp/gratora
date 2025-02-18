import { type Page } from '@playwright/test';

export const ADMIN_USER = process.env.DONO_E2E_ADMIN_USER ?? 'admin';
export const ADMIN_PASS = process.env.DONO_E2E_ADMIN_PASS ?? 'password';

/**
 * wp-admin session helper. Logs in via the wp-login.php form using env creds
 * (defaults match wp-env; the P2P seed provisions `dono-e2e-admin` for Local).
 */
export class AdminPage {
    constructor(readonly page: Page) {}

    async login(user = ADMIN_USER, pass = ADMIN_PASS): Promise<void> {
        await this.page.goto('/wp-login.php');
        if (this.page.url().includes('/wp-admin/')) return; // already authenticated
        await this.page.fill('#user_login', user);
        await this.page.fill('#user_pass', pass);
        await this.page.click('#wp-submit');
        await this.page.waitForURL(/\/wp-admin\//, { timeout: 15_000 });
    }

    /** Open the Dono campaign-detail React screen for a campaign id + main tab. */
    async openCampaign(id: number, tab = 'overview'): Promise<void> {
        await this.page.goto(`/wp-admin/admin.php?page=dono-campaigns&view=detail&id=${id}&tab=${tab}`);
    }
}
