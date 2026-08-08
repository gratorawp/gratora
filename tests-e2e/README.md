# Dono e2e tests (Playwright)

Browser-driven end-to-end tests. Specs live in `tests-e2e/specs/`, helpers in
`tests-e2e/helpers/`, fixtures in `tests-e2e/fixtures/`. Config:
`playwright.config.ts` at the plugin root.

Two suites, split into Playwright projects:

- **core** (`specs/*.spec.ts`) - the donor form. Always runs.
- **p2p** (`specs/p2p/*.spec.ts`) - the peer-to-peer campaign: start page,
  fundraiser/team/campaign public pages, a sandbox donation on a fundraiser page,
  the hide-header/footer chrome toggle, wp-admin (View page, appearance,
  fundraisers/teams lists, pause/resume, approval queue), and the donor portal
  (magic-link sign-in + manage). Requires the `dono-p2p` add-on active. The
  project only registers when `DONO_E2E_P2P_START_PATH` is set, so a core-only
  run never runs it unseeded.

## One-time setup

1. Install browsers (once per machine):
   ```sh
   npx playwright install chromium
   ```

2. Seed the canonical forms:
   ```sh
   wp dono e2e-seed
   ```
   Idempotent - re-run anytime to converge to whatever the current spec set
   expects. It:
   - Creates / updates the campaign `dono-e2e` (published).
   - Creates / updates the single-page form `dono-e2e-form` (published) with
     every block the specs assert against (amount, name, email, country,
     address, phone, comment, anonymous, cover-fees, consent, custom
     date/dropdown, currency-switcher, payment-gateways, submit).
   - Creates / updates the multi-step form `dono-e2e-wizard` (published) for
     the multi-step regression spec.
   - Creates / updates `/dono-e2e/` and `/dono-e2e-wizard/` pages with their
     respective shortcodes.
   - Enables EUR / USD / GBP in org settings so the currency-switcher specs
     have something to switch between.
   - Clears AntiSpamGuard rate-limit transients so the suite isn't penalised
     by prior runs.

   The command prints the env vars you need.

3. Export them (or drop them into `tests-e2e/.env`):
   ```sh
   export DONO_E2E_URL='http://localhost:10075'
   export DONO_E2E_FORM_PATH='/dono-e2e/'
   export DONO_E2E_MULTI_STEP_FORM_PATH='/dono-e2e-wizard/'
   export DONO_E2E_CONDITIONAL_FORM_PATH='/dono-e2e-conditional/'
   export DONO_E2E_CUSTOM_FIELDS_FORM_PATH='/dono-e2e-custom-fields/'
   export DONO_E2E_LAYOUT_FORM_PATH='/dono-e2e-layout/'
   ```

If you'd rather build the canonical form by hand instead of running the CLI,
the kitchen-sink block set is documented at the bottom of this file.

### Peer-to-peer suite (dono-p2p)

The P2P specs need the `dono-p2p` add-on active and its own fixture campaign:

```sh
wp dono-p2p e2e-seed
```

Idempotent. It creates the published `p2p-e2e` peer-to-peer campaign (with its
fundraiser/team/start layout pages), a solo fundraiser (`solo-sam`), a team
(`trailblazers`) of two, a `dono-e2e-admin` administrator for the wp-admin
specs, and a single-use donor-portal magic link. Distinct slugs from the core
`dono-e2e` campaign, so the two seeders never clobber each other.

It prints the env to export; the portal link is single-use, so re-run the
command before each session that exercises the portal spec:

```sh
export DONO_E2E_P2P_CAMPAIGN_ID='20'
export DONO_E2E_P2P_CAMPAIGN_PATH='/campaigns/p2p-e2e/'
export DONO_E2E_P2P_START_PATH='/campaigns/p2p-e2e/start/'
export DONO_E2E_P2P_FUNDRAISER_PATH='/campaigns/p2p-e2e/fundraiser/solo-sam/'
export DONO_E2E_P2P_TEAM_PATH='/campaigns/p2p-e2e/team/trailblazers/'
export DONO_E2E_ADMIN_USER='dono-e2e-admin'
export DONO_E2E_ADMIN_PASS='dono-e2e-pass'
export DONO_E2E_PORTAL_URL='http://localhost:10075/donor-portal/?token=...'
```

Two specs are gated behind extra env so they stay off a shared site:
`DONO_E2E_P2P_SUBMIT=1` runs the start-page happy-path submit (creates a real
fundraiser + sends a welcome email); the portal spec runs only when
`DONO_E2E_PORTAL_URL` is set.

## Run

```sh
npm run test:e2e               # headless (core; + p2p when its env is set)
npm run test:e2e -- --ui       # Playwright UI mode
npm run test:e2e -- --headed   # see the browser
npm run test:e2e -- specs/amount.spec.ts   # one spec
npm run test:e2e -- --project=p2p          # just the peer-to-peer suite
```

Reports / traces / screenshots on failure land in `test-results/` (gitignored).

If you hit `dono_rate_limited` (429), re-run `wp dono e2e-seed` to clear the
AntiSpamGuard IP transients and start fresh. Better: put the fixture site in
org test mode (Settings, or `dono_gateway_config['test_mode']`), which the
guard short-circuits. Repeated local runs trip the IP quota otherwise, at ten
attempts per fifteen minutes, and the suite is well past that.

### The payment suite needs a browser-paying gateway

`specs/payment-placement.spec.ts` exercises the phase after submit, where the
gateway mounts its own element. Offline and sandbox settle server-side and
never mount one, so on a site offering only those the suite skips seven of its
ten specs and says so by name:

> no gateway on this site pays in the browser (offered: offline, sandbox).
> Configure Stripe test keys on the fixture site.

Stripe test keys on the fixture site are the whole prerequisite. No Stripe
account is contacted: the specs stub the donation POST with the payload the
server would return, so the keys only need to exist for the gateway to be
offered.

## Visual regression

Screenshot goldens for the donor-facing surfaces, in a dedicated `visual`
project (`specs/visual/`). Element-scoped to the form so theme chrome never
bleeds into a golden. Covered: the kitchen-sink form (initial desktop +
mobile, currency switched, field-error styling), each page
of the multi-step wizard, and the layout/content form (goal block masked, its
totals drift as the functional suite donates).

```sh
npm run test:visual            # compare against committed goldens
npm run test:visual:update     # re-bless after an intentional styling change
```

Needs the same env as the functional suite (`DONO_E2E_URL` +
`DONO_E2E_FORM_PATH`, plus the wizard/layout paths for those specs). The
project is opt-in (the scripts set `DONO_E2E_VISUAL=1`) so a plain
`npm run test:e2e` never fails on missing snapshots.

Goldens are committed under `specs/visual/__screenshots__/<platform>/`, keyed
by OS because font rasterisation differs per platform - the darwin set is
canonical for local work; a Linux CI runner would grow its own set on first
`--update-snapshots` run. On a diff, the report in `test-results/` contains
expected / actual / diff images side by side (`npx playwright show-report`).

Comparison settings live in `playwright.config.ts` (`expect.toHaveScreenshot`):
animations disabled, caret hidden, `maxDiffPixels: 120`, and
`specs/visual/vrt.css` injected at capture time to neutralise motion.

The goldens assume the seeded e2e site state, including test mode being ON
(the form renders the test-mode banner). If a golden fails after reseeding,
check the site state before re-blessing.

## Hermetic (wp-env) and CI

The committed `.wp-env.json` loads core only, so a standalone checkout boots.
For local both-plugin runs, add an override (gitignored):

```sh
echo '{ "plugins": [ ".", "../dono-p2p" ] }' > .wp-env.override.json
npx wp-env start
npx wp-env run cli wp dono e2e-seed
npx wp-env run cli wp dono-p2p e2e-seed
```

> macOS note: a Docker Desktop named-volume bug can leave the mounted plugin
> dir empty when the path contains spaces (e.g. `~/Local Sites/`). It does not
> affect the Linux CI runners.

`.github/workflows/e2e.yml` runs the suite hermetically on every push/PR. It
covers core out of the box; the P2P job activates when the repo variable
`DONO_P2P_REPO` (and secret `DONO_P2P_TOKEN`) point at the add-on repo, since
`dono-p2p` ships from its own private repository.

## Conventions

- TypeScript only (no JS in `tests-e2e/`).
- Specs use the `donor` fixture from `fixtures/donor-form.ts`. The fixture
  opens the form page, waits for `data-dono-ready` (the runtime cloak), and
  assert-fails the spec on any `[dono] render error contained by boundary`
  console message - that's how the donor form signals a renderer crash that
  ErrorBoundary swallowed.
- `submit()` on `DonorFormPage` waits the `MIN_RENDER_SECONDS` (2s) remainder
  before clicking the primary button; AntiSpamGuard's HMAC form-token rejects
  faster submissions.
- Add new blocks as `tests-e2e/specs/<block>.spec.ts`; reuse `DonorFormPage`
  helpers or extend the page object for new interactions.
- Default gateway for submission tests is `offline` (no money flow).
- Each spec generates a unique donor email per run with `Date.now()` to avoid
  cross-test donor collisions.

## Manual canonical form (if you skip `wp dono e2e-seed`)

The canonical specs assume the form behind `DONO_E2E_FORM_PATH` includes
these blocks (the required minimum + every block any spec targets). Specs
whose target block is missing skip themselves with a clear reason.

Required for the core specs:
- donation-amount (with at least one preset)
- name (first + last, both required)
- email (required)
- currency-switcher (offering EUR + at least one of USD / GBP - and the org's
  Settings → Currency → Supported currencies must enable them)
- payment-gateways (with `offline` enabled)
- submit-button

Add these to also run the donor-block specs (country, address, anonymous,
comment, phone, consent, cover-fees, custom-fields):
- country
- address (with country sub-field visible; sub-fields can be optional)
- anonymous-toggle
- comment
- phone
- consent (one optional + one required-by-law purpose)
- cover-fees
- tribute, when the dono-tributes add-on is installed (with at least "In honor of" enabled)
- custom date field
- custom dropdown
