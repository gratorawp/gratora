# Dono translations

Translation files (`.po` / `.mo`) live here.

## Text domain

All translatable strings use the `dono` text domain. Naming convention for files:

```
dono.pot           - template (regenerated from source)
dono-de_DE.po/.mo  - German
dono-fr_FR.po/.mo  - French
dono-hr.po/.mo     - Croatian
…
```

## Regenerating the POT file

From the plugin root:

```bash
wp i18n make-pot . languages/dono.pot --domain=dono
```

(Requires `wp-cli` with the `i18n` command - bundled with recent WP-CLI versions.)

## Locale switching at runtime

Receipts (PDF + email) are rendered in the **donor's** locale, not the
site's - `ReceiptIssuer::switchLocale()` calls `switch_to_locale()` per
render, then `restore_previous_locale()`. Donor locale comes from
`donation->locale` (set at intent time from the form's language).
