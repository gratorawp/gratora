# Dono translations

Notes for whoever regenerates the template. Kept out of the distributed zip by
`.distignore`.

## Text domain

Every translatable string uses the `dono-fundraising-platform` text domain,
which is the plugin slug and has to stay that way: WordPress.org derives the
slug from the plugin name and refuses a domain that disagrees with it. File
naming follows from the domain:

```
dono-fundraising-platform.pot           - template
dono-fundraising-platform-de_DE.po/.mo  - German
dono-fundraising-platform-fr_FR.po/.mo  - French
dono-fundraising-platform-hr.po/.mo     - Croatian
```

## Regenerating the POT file

From the plugin root:

```bash
npm run i18n
```

That merges the strings from `@dono/ui` and then runs `wp i18n make-pot` over
the plugin. Requires `wp-cli` with the `i18n` command.

## JavaScript strings

The template references `assets/**`, the sources, while WordPress asks for a
JSON file named after the md5 of the *enqueued* path, which is the compiled
`build/<entry>/index.js`. Language packs from translate.wordpress.org line up
because WordPress.org parses the shipped files itself and the zip carries
`build/`. Generating JSON locally with `wp i18n make-json` off this template
does not: run make-pot over `build/` first if you need that.

## Locale switching at runtime

Receipts (PDF + email) are rendered in the **donor's** locale, not the
site's. `ReceiptIssuer::switchLocale()` calls `switch_to_locale()` per render,
then `restore_previous_locale()`. Donor locale comes from `donation->locale`,
set at intent time from the form's language.
