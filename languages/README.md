# Gratora translations

Notes for whoever regenerates the template. Kept out of the distributed zip by
`.distignore`.

## Text domain

Every translatable string uses the `gratora-donation-platform` text domain,
which is the plugin slug and has to stay that way: WordPress.org derives the
slug from the plugin name and refuses a domain that disagrees with it. File
naming follows from the domain:

```
gratora-donation-platform.pot                   - template
gratora-donation-platform-de_DE.po/.mo          - German
gratora-donation-platform-de_DE-<md5>.json      - German, one per script bundle
gratora-donation-platform-es_ES.po/.mo          - Spanish
```

## Regenerating the POT file

From the plugin root:

```bash
npm run i18n
```

That merges the strings from `@gratora/ui` and then runs `wp i18n make-pot` over
the plugin. Requires `wp-cli` with the `i18n` command.

## Translations shipped with the plugin

German (`de_DE`) and Spanish (`es_ES`) ship in this directory, for sites
whose locale has no language pack from WordPress.org. Once a pack exists it
wins for PHP strings, while scripts keep reading the files here, because
every `wp_set_script_translations()` call names this directory.

The `.po` file is the source and stays out of the zip; the `.mo` file and the
`.json` files are generated from it and committed, so the zip carries them:

```bash
npm run build
npm run i18n:locales
```

Run both after editing a `.po` file, and after `npm run i18n` when strings
changed, so new strings can be translated. The extraction parses the built
bundles and needs more memory than PHP gives the command line by default:
run `wp` as `php -d memory_limit=-1 wp-cli.phar`.

## JavaScript strings

The template references `assets/**`, the sources, while WordPress asks for a
JSON file named after the md5 of the *enqueued* path, which is the compiled
`build/<entry>/index.js`. Language packs from translate.wordpress.org line up
because WordPress.org parses the shipped files itself and the zip carries
`build/`. `npm run i18n:locales` does the same here: it runs make-pot over
`build/` into `.cache/scripts.pot` to learn which strings each bundle
carries, then `bin/make-translations.mjs` writes one JSON file per bundle and
locale.

## Locale switching at runtime

Receipts (PDF + email) are rendered in the **donor's** locale, not the
site's. `ReceiptIssuer::switchLocale()` calls `switch_to_locale()` per render,
then `restore_previous_locale()`. Donor locale comes from `donation->locale`,
set at intent time from the form's language.
