# Dono e2e tests (Playwright)

Browser-driven end-to-end tests. Specs live in `tests-e2e/specs/`, helpers in
`tests-e2e/helpers/`, fixtures in `tests-e2e/fixtures/`. Config:
`playwright.config.ts` at the plugin root.

Three Playwright projects:

- **core** (`specs/*.spec.ts`) - the donor form. Always runs.
- **visual** (`specs/visual/`) - screenshot goldens. Opt-in (`DONO_E2E_VISUAL=1`).
- **screenshots** (`specs/screenshots/`) - wp-admin capture, asserts nothing.
  Opt-in (`DONO_E2E_SHOTS=1`).

### Add-on suites live in their add-ons

A feature's specs belong to the plugin that ships the feature, so a checkout of
core is self-contained and an add-on can be tested without it:

- **Peer-to-peer** (start page, fundraiser/team/campaign pages, sandbox
  donation, hide-chrome, wp-admin, donor portal) - `dono-p2p/tests-e2e/`, with
  its own `playwright.config.ts` and `wp dono-p2p e2e-seed`.
- **Tributes** (the tribute field, functional + visual) -
  `dono-tributes/tests-e2e/`.

Both still run against the form this plugin's seeder builds, so keep the
kitchen-sink block set below in step with them.

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
   - Creates / updates `/dono-e2e-form/` and `/dono-e2e-wizard/` pages with their
     respective shortcodes.
   - Enables EUR / USD / GBP in org settings so the currency-switcher specs
     have something to switch between.
   - Clears AntiSpamGuard rate-limit transients so the suite isn't penalised
     by prior runs.

   The command prints the env vars you need.

3. Export them (or drop them into `tests-e2e/.env`):
   ```sh
   export DONO_E2E_URL='http://localhost:10075'
   export DONO_E2E_FORM_PATH='/dono-e2e-form/'
   export DONO_E2E_MULTI_STEP_FORM_PATH='/dono-e2e-wizard/'
   export DONO_E2E_CONDITIONAL_FORM_PATH='/dono-e2e-conditional/'
   export DONO_E2E_CUSTOM_FIELDS_FORM_PATH='/dono-e2e-custom-fields/'
   export DONO_E2E_LAYOUT_FORM_PATH='/dono-e2e-layout/'
   ```

If you'd rather build the canonical form by hand instead of running the CLI,
the kitchen-sink block set is documented at the bottom of this file.

Two specs need env the seeder cannot mint for you, and skip themselves by name
without it:

- `specs/portal-magic-link.spec.ts` wants a fresh single-use portal link in
  `DONO_E2E_PORTAL_REOPEN_URL`; a donor's admin profile shows one.
- the `screenshots` project wants `DONO_E2E_ADMIN_USER` / `DONO_E2E_ADMIN_PASS`
  (the defaults match wp-env, so a hermetic run needs nothing).

## Run

```sh
npm run test:e2e               # headless
npm run test:e2e -- --ui       # Playwright UI mode
npm run test:e2e -- --headed   # see the browser
npm run test:e2e -- specs/amount.spec.ts   # one spec
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

## Demo data for screenshots

The admin screens are only worth photographing against a site that has a year
of history behind it. `wp dono demo-seed` builds one:

```sh
wp dono demo-seed          # prompts first
wp dono demo-seed --yes    # unattended
```

Not a test fixture, and nothing in the suites depends on it. It is also
distinct from `wp dono seed`, which writes **test-mode** donations: those are
excluded from money reporting by design, so a dashboard seeded with them shows
zeros. Demo rows are written **live** (`is_test = 0`), which is what makes the
KPIs, charts and reports render at all, and what makes this unsafe next to real
money. The command refuses when it finds live paid donations it did not write
itself; `--force` overrides that, and is only for an install you can throw away.

What it creates, on an install using EUR as its org currency:

| | |
|---|---|
| Campaigns | 7: published, archived and draft; goals at ~76%, 88%, 50%, 116%, 89% and 3%; one goal counted in donors rather than money; one ending in 5 days so the dashboard's attention list has a row |
| Funds | 5, restricted and unrestricted, two with their own goals |
| Donors | 140 (invented names, `example.org` / `example.com` addresses), a mix of individuals, households and organisations across 10 countries, with a long-tail giving distribution so retention and repeat-donor views have shape |
| Donations | ~810 across 12 months: paid, pending, failed, refunded, partially refunded and disputed; one-time and recurring; Stripe, PayPal and offline; spread across the utm channels the dashboard buckets by |
| Recurring plans | 44 across active, paused, past_due and cancelled, weekly through yearly, with their renewal donations |

Amounts, dates and donor picks come from one fixed seed, so two machines
running it on the same day get the same site. The dates are relative to the run
date: donations follow a seasonal curve with a December peak, quieter summers,
lighter weekends and a growth trend, so the revenue chart has a shape rather
than noise.

Idempotent. Every donation carries a stable `gateway_intent_id` (`demo-...`)
and every plan a stable subscription id, so a second run writes nothing and
converges. That prefix is also how the rows are identified: to start over,
delete the donations whose `gateway_intent_id` starts with `demo-`, the plans
whose `gateway_subscription_id` does, and the `demo-` campaigns and funds.

Two side effects are suppressed for the duration: outbound mail, and receipt
issuance (which would otherwise queue an Action Scheduler job and an email per
donation). Rollups are recomputed at the end, the same way
`wp dono recompute-aggregates` does, because the rows are backdated behind the
listeners that normally keep those columns current.

## Admin screenshots

`specs/screenshots/admin.spec.ts` walks every Dono wp-admin screen and writes a
PNG per screen, for docs, design review and the wp.org listing. It asserts
nothing: the goldens in `specs/visual/` are the regression suite, this one is a
camera. Donor-facing surfaces are out of scope.

```sh
npm run test:shots
```

Its own opt-in project (`DONO_E2E_SHOTS=1`), fixed at 1440x900 and 2x device
scale so a set is comparable run to run and survives being scaled down.
Captures land in `tests-e2e/screenshots/` (gitignored), or
`DONO_E2E_SHOTS_DIR`. Nothing is copied into `.wordpress-org/` automatically:
pick the winners by hand, and keep `readme.txt`'s captions in step.

Covered: dashboard; campaigns list and campaign detail (overview / forms /
settings); the form builder (build + settings views); donations list and a
donation detail; donors list, Donor Insights, and a donor profile with each of
its tabs; subscriptions; funds; every Settings tab; every Tools tab. There is
no top-level Forms screen: a campaign's Forms tab is the list, and the builder
opens from there.

Needs `DONO_E2E_URL` plus admin credentials (`DONO_E2E_ADMIN_USER` /
`DONO_E2E_ADMIN_PASS`), nothing else. Screens whose record does not exist yet
skip themselves by name, so a half-seeded site still yields everything it can.

Waiting is `helpers/capture.ts`: network idle, then DOM quiescence. Recharts
animates its paths from JS, so disabling CSS animation would still let a
dashboard chart be caught mid-sweep.

## Hermetic (wp-env) and CI

The committed `.wp-env.json` loads core only, so a standalone checkout boots:

```sh
npx wp-env start
npx wp-env run cli wp dono e2e-seed
```

An add-on suite needs its plugin mounted too. Add an override (gitignored) and
run that suite from the add-on's own directory:

```sh
echo '{ "plugins": [ ".", "../dono-p2p" ] }' > .wp-env.override.json
npx wp-env start
npx wp-env run cli wp dono e2e-seed
npx wp-env run cli wp dono-p2p e2e-seed
```

> macOS note: a Docker Desktop named-volume bug can leave the mounted plugin
> dir empty when the path contains spaces (e.g. `~/Local Sites/`). It does not
> affect the Linux CI runners.

`.github/workflows/e2e.yml` runs this suite hermetically on every push/PR. Its
optional `dono-p2p` checkout and seed steps (repo variable `DONO_P2P_REPO` +
secret `DONO_P2P_TOKEN`) now only provision site state; the p2p specs run from
the add-on's own repository.

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
- custom date field
- custom dropdown

The `dono-tributes` suite runs against this same form, so keep a tribute block
on it (with at least "In honor of" enabled) when that add-on is installed; its
specs skip themselves when the block is absent.
