# Dono

[![PHPUnit](https://github.com/dono-platform/dono/actions/workflows/phpunit.yml/badge.svg)](https://github.com/dono-platform/dono/actions/workflows/phpunit.yml)
[![e2e](https://github.com/dono-platform/dono/actions/workflows/e2e.yml/badge.svg)](https://github.com/dono-platform/dono/actions/workflows/e2e.yml)
[![License: GPL v2 or later](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](LICENSE)
[![PHP 8.1+](https://img.shields.io/badge/php-8.1%2B-777bb4.svg)](https://www.php.net/)
[![WordPress 7.0+](https://img.shields.io/badge/wordpress-7.0%2B-21759b.svg)](https://wordpress.org/)

### A complete fundraising platform for WordPress

Dono gives an organization the whole fundraising stack in one plugin: a
block-based donation form builder, one-time and recurring giving, campaigns and
funds, encrypted donor records with a self-service portal, receipts, and
advanced reporting.

## Requirements

| | |
|---|---|
| WordPress | 7.0 or later |
| PHP | 8.1 or later |
| MySQL | 8.0 or later |
| Node | 18.12 or later |

## Getting started

```bash
git clone https://github.com/dono-platform/dono.git
cd dono
composer install
npm install
npm run build
```

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

PHP tests need a WordPress test library and a throwaway database, set up once per
machine. See [TESTING.md](TESTING.md).

## Extending Dono

Dono is built to be extended by other plugins, and uses its own seams to do it:

- **Modules.** A plugin registers on `dono.modules.register` with an id,
  version, dependencies and migrations, and is booted in dependency order.
- **Settings tabs.** `dono.settings.groups` adds a server-side settings group;
  `window.dono.tabs.register( 'settings', ... )` mounts the panel that edits it.
- **Form fields and gateways.** New donation-form blocks and new payment
  gateways register through the same registries the built-in ones use.
- **Commands.** `dono.commands.register` adds a capability-gated, schema-checked
  action, which also becomes available to anything driving Dono programmatically.

Persistence goes through [Queryable](https://github.com/dono-platform/queryable), a
small first-party query builder and schema tool. Models declare their own schema
and migrate themselves.

## Contributing

Issues and pull requests are welcome. Run `composer test` and `npm run lint:js`
before opening one, and keep it to a single concern.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
