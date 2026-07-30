# Dono

[![PHPUnit](https://github.com/getdono/dono/actions/workflows/phpunit.yml/badge.svg)](https://github.com/getdono/dono/actions/workflows/phpunit.yml)
[![e2e](https://github.com/getdono/dono/actions/workflows/e2e.yml/badge.svg)](https://github.com/getdono/dono/actions/workflows/e2e.yml)
[![License: GPL v2 or later](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](LICENSE)
[![PHP 8.1+](https://img.shields.io/badge/php-8.1%2B-777bb4.svg)](https://www.php.net/)
[![WordPress 7.0+](https://img.shields.io/badge/wordpress-7.0%2B-21759b.svg)](https://wordpress.org/)

A fundraising platform for WordPress: a block-based donation form builder,
one-time and recurring giving, campaigns and funds, encrypted donor records with
a self-service portal, receipts, and reporting.

Card payments run through Stripe on the organisation's own account, so donations
settle directly to them. Offline and hand-recorded donations are first-class too,
because money arrives by cheque and by bank transfer and the books have to agree
either way.

This repository is the plugin itself. If you are looking to install Dono on a
site rather than work on it, start from the release rather than a clone.

## Requirements

| | |
|---|---|
| WordPress | 7.0 or later |
| PHP | 8.1 or later |
| MySQL | 8.0 or later |
| Node | 18.12 or later, for the asset build only |

There is no support for older WordPress or PHP. The floor is deliberate: the
codebase uses typed properties, enums and readonly promotion throughout rather
than shimming around them.

## Getting started

```bash
git clone https://github.com/getdono/dono.git
cd dono
composer install
npm install
npm run build
```

`npm run build` compiles the admin screens, the donation-form runtime and the
editor blocks into `build/`. Nothing in `build/` is committed, so a fresh clone
renders nothing until you run it once.

For iterating on the front end, `npm run start` rebuilds on change.

## Commands

| Command | What it does |
|---|---|
| `npm run build` | Production asset build into `build/` |
| `npm run start` | Watch and rebuild on change |
| `npm run lint:js` | ESLint over the JS and JSX |
| `npm run format` | Prettier over the same |
| `composer test:unit` | PHP unit tests, no WordPress, milliseconds |
| `composer test:integration` | Boots WordPress against a real database |
| `composer test` | Both PHP suites |
| `npm run test:e2e` | Playwright, against a running site |

## Testing

PHP tests need a WordPress test library and a throwaway database, set up once
per machine:

```bash
composer test:setup dono_tests root '' 127.0.0.1 latest
```

That downloads WordPress into `~/.dono-wp-tests`, which every Dono repository on
the machine then finds. It drops and recreates the database you name, so name one
you do not care about. The end-to-end suite runs against a real site and needs
its URL and admin credentials in the environment.

See [TESTING.md](TESTING.md) for the detail, including the environment variables
the e2e suite reads.

## How the code is organised

`src/` is PSR-4 under the `Dono\` namespace, split by domain rather than by
layer, so everything about donations sits together rather than being spread
across a controllers directory and a models directory.

| Directory | |
|---|---|
| `src/Campaigns`, `src/Donations`, `src/Donors`, `src/Forms`, `src/Funds` | The domain objects and their services |
| `src/Gateways` | Payment gateway contracts and the Stripe and offline implementations |
| `src/Receipts`, `src/Mail` | Receipt rendering and every transactional email |
| `src/Recurring` | Subscriptions and renewals |
| `src/Rest` | REST controllers, split into admin, public and donor-portal surfaces |
| `src/Settings`, `src/Admin`, `src/Onboarding` | The settings model and the admin screens behind it |
| `src/Analytics`, `src/Dashboard`, `src/Reports` | Metrics, the dashboard and generated documents |
| `src/Foundation` | Container, hooks, crypto, capabilities, the command registry |
| `assets/` | Admin screens and the donation-form runtime, built into `build/` |
| `views/`, `tests/`, `tests-e2e/` | Templates, PHP suites, Playwright suites |

Persistence goes through [Queryable](https://github.com/getdono/queryable), a
small first-party query builder and schema tool. Models declare their own schema
and migrate themselves; `$wpdb` is not called directly outside migrations.

## Extending Dono

Dono is built to be extended by other plugins, and uses its own seams to do it:

- **Modules.** A plugin registers on `dono.modules.register` with an id,
  version, dependencies and migrations, and gets booted in dependency order.
- **Settings tabs.** `dono.settings.groups` adds a server-side settings group;
  `window.dono.tabs.register( 'settings', ... )` mounts the panel that edits it.
- **Form fields and gateways.** New donation-form blocks and new payment
  gateways register through the same registries the built-in ones use.
- **Commands.** `dono.commands.register` adds a capability-gated, schema-checked
  action, which also becomes available to anything driving Dono programmatically.

## Contributing

Issues and pull requests are welcome. Two things that will save a round trip:
run `composer test` and `npm run lint:js` before opening, and keep a pull request
to one concern, since a change that touches money is reviewed line by line.

Code style is enforced by `.eslintrc.js` and `.prettierrc.js` for JavaScript.
PHP follows the surrounding code: four spaces, single quotes, typed properties,
constructor promotion, and comments only where the reason for something is not
obvious from reading it.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
