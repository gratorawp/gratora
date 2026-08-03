=== Dono - Fundraising Platform ===
Contributors: donodp
Tags: donations, donation form, fundraising, stripe, nonprofit
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Donation forms, recurring giving, campaigns, funds and encrypted donor records. Your own Stripe or PayPal account, no platform fee.

== Description ==

Dono is a fundraising platform for WordPress. Donations are taken through your own payment account, stored in your own database, and reported on from your own admin. Nothing is routed through a third party and no platform fee is taken.

**Donation forms**

Forms are built from blocks in the WordPress editor, so the builder is the editor you already know. Start from a template or compose your own.

* Suggested amounts, custom amounts, and a minimum
* One-time, weekly, fortnightly, monthly, quarterly and yearly giving from the same form
* Multi-step forms with progress, or a single page
* Conditional fields that appear based on what the donor has chosen
* Name, email, phone, address, country, dropdowns, checkboxes, radios, dates, numbers and free text
* Ask donors to cover the processing fee
* Anonymous giving, donor comments, and a fund picker
* Consent checkboxes and a privacy notice, recorded per donation
* Currency switcher when you accept more than one currency

**Campaigns and funds**

* Campaigns with goals by amount, donation count or donor count, and an end date
* Campaign pages you edit as ordinary WordPress content
* Blocks for progress bars, stats, campaign grids, recent donations, top donors and a supporter wall
* Funds so a donation can be designated, with its own totals

**Recurring giving**

* Subscriptions managed through Stripe or PayPal
* Donors cancel or update their own plans from the portal
* Failed and cancelled plans are reflected in your totals

**Donors**

* Donor records with lifetime totals, donation history and notes
* Names, emails, phone numbers, addresses and tax IDs are encrypted at rest
* A passwordless portal where donors see their giving, download receipts and manage consent
* Household grouping, donor types, and per-donor annual tax statements

**Receipts and documents**

* Automatic email receipts, with your own branding
* PDF receipts and year-end annual statements
* Sequential, configurable reference numbers for donations, receipts and refunds

**Reporting**

* Dashboard with revenue, donation and donor metrics over any period
* Campaign performance reports as PDF
* CSV exports for donations, donors and month-by-month revenue
* Choose exactly which donor columns leave the site

**Running the site**

* Refunds, including partial ones, recorded against the donation
* Record offline donations by hand: cash, cheque, bank transfer
* Test mode per form or site-wide, kept out of your reporting
* Roles and capabilities, so a bookkeeper is not also a data exporter
* Data retention, donor erasure and consent records for GDPR
* Multi-currency with automatic or hand-entered exchange rates

== Installation ==

1. Install Dono from Plugins > Add New, or upload the `dono` folder to `/wp-content/plugins/` and activate it from the Plugins screen.
2. Open **Dono** in the admin menu and follow the short onboarding to set your organisation name and currency.
3. Under **Dono > Settings**, add your Stripe or PayPal keys, or enable offline donations.
4. Create a campaign, then add a donation form to any page using the Dono donation form block.

== Frequently Asked Questions ==

= Is Dono free? =

Yes. Card payments run through your own Stripe or PayPal account, so their usual processing fees apply. Dono adds no platform fee of its own and never touches the money, so you keep everything your payment provider passes on.

= Which payment methods are supported? =

Stripe and PayPal, plus offline and manual donations for cash, cheques and bank transfers.

= Does it support recurring donations? =

Yes. One-time, weekly, fortnightly, monthly, quarterly and yearly giving are built in, and donors can manage their own plans from the donor portal.

= Where do donations go? =

Straight to your own Stripe or PayPal account. Dono never holds your funds.

= Is donor data secure? =

Names, emails, phone numbers, addresses and tax IDs are encrypted at rest. Each donor gets a passwordless portal to view their giving history and manage their consent, and you can erase a donor's data on request.

= Do I need a separate page builder or form plugin? =

No. Forms and campaign pages are built from blocks in the WordPress editor.

= Can I take donations in more than one currency? =

Yes. Add the currencies you accept and donors choose at the form. Reporting is converted to your organisation's currency using a daily exchange rate, or rates you enter yourself.

== External services ==

Dono connects to the following third-party services. Nothing is sent to any of them unless the relevant feature is in use.

**Stripe** - used when you enable Stripe as a payment method. Donation amount, currency, and the donor's name and email are sent to Stripe to create and confirm a payment, and Stripe sends webhooks back to your site. Data is sent only when a donation is made through Stripe, and only using the API keys you supply.
Terms: https://stripe.com/legal
Privacy: https://stripe.com/privacy

**PayPal** - used when you enable PayPal as a payment method. Donation amount, currency, and the donor's name and email are sent to PayPal to create and capture a payment, and PayPal sends webhooks back to your site. Data is sent only when a donation is made through PayPal, and only using the API credentials you supply.
Terms: https://www.paypal.com/legalhub/useragreement-full
Privacy: https://www.paypal.com/legalhub/privacy-full

**Frankfurter** - used to fetch daily currency exchange rates so donations in other currencies can be reported in your organisation's currency. Only the currency code is sent. No donor or site data is sent. Turn it off under Dono > Settings > Currency, or enter rates by hand instead.
Service: https://frankfurter.dev
Terms and privacy: https://frankfurter.dev

== Screenshots ==

1. The block-based donation form builder.
2. A campaign page with a live progress bar and donation form.
3. The admin dashboard with donation and donor metrics.
4. Donor records and the self-service donor portal.
5. Settings: payment methods, currency, and receipts.

== Changelog ==

= 1.0.0 =
* Initial release.
