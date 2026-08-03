=== Dono - Fundraising Platform ===
Contributors: donodp
Tags: donations, donation form, fundraising, stripe, nonprofit
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Donation forms, recurring giving, campaigns and encrypted donor records. Your own Stripe or PayPal account, and no platform fee.

== Description ==

Dono is a fundraising platform for WordPress. Donations run through your own payment account, land in your own database, and are reported on from your own admin. Nothing is routed through anyone else, and Dono takes no cut.

Everything below is in the free plugin. There is no donation cap, no second payment method held back, and recurring giving is not saved for a paid tier.

= Campaigns you design, not templates you tolerate =

A campaign in Dono is a real WordPress page. Open it in the editor and change it the way you change any other page, because it is any other page.

* Drop in progress bars, stats, recent donations, top donors and a supporter wall wherever you want them
* Rearrange, restyle or delete any of it, including the parts Dono put there
* Set a goal by amount raised, number of donations or number of donors, with an optional end date
* Show a grid of campaigns anywhere on your site
* Save a look as a brand preset and reuse it across campaigns and forms

= Forms built from blocks =

The form builder is the WordPress editor. If you can build a page, you can build a donation form. Start from a template and adjust it, or start from nothing.

* Suggested amounts, a custom amount, and a minimum you set
* One-time, weekly, fortnightly, monthly, quarterly and yearly, offered from the same form
* Split a long form into steps, or keep it on one screen
* Show and hide fields based on what the donor has already chosen
* Name, email, phone, address, country, dropdowns, checkboxes, radio buttons, dates, numbers and free text
* Ask donors to cover the processing fee
* Anonymous giving, a message from the donor, and a fund picker
* A currency switcher when you accept more than one currency
* Consent checkboxes and a privacy notice, recorded against the donation

= Recurring giving, included =

* Subscriptions through Stripe or PayPal
* Five recurring frequencies, and you choose which ones a form offers
* Donors cancel or change their own plans from the portal, without emailing you
* Cancelled and failed plans are reflected in your totals rather than quietly inflating them

= Donors, and a portal they can use themselves =

* Donor records with lifetime totals, full history and private notes
* Households, donor types, and per-donor annual tax statements
* A passwordless portal where donors see their giving, download receipts, change a subscription and manage consent
* No donor accounts to administer, and no passwords to reset

= Privacy and GDPR =

Donor data is treated as something you are responsible for, rather than something to collect as much of as possible.

* Names, emails, phone numbers, addresses and tax IDs are encrypted at rest
* Consent is recorded per donation, with the purpose, the time and where it was given
* Erase a donor on request, with a retention window before the record is fully cleared
* Anonymise donors who have been inactive for a number of years you choose
* IP anonymisation, on by default
* Let donors export or delete their own data from the portal, or turn either off
* Decide how long activity data is kept
* Point donors at your privacy policy from the form itself

= Receipts and documents =

* Email receipts sent automatically, in your branding
* PDF receipts and year-end annual statements
* Sequential reference numbers for donations, receipts and refunds, in a format you set

= Reporting =

* A dashboard with revenue, donation and donor metrics over any period
* Campaign performance as a PDF you can hand to a board
* CSV exports for donations, donors, and revenue month by month
* Choose exactly which donor columns leave the site, because an export is a file that travels

= Payments =

* Stripe and PayPal, using your own account and your own keys
* Offline and manual donations for cash, cheques and bank transfers
* Refunds, including partial ones, recorded against the donation
* Test mode for one form or the whole site, kept out of your reporting

= Running it day to day =

* Roles and capabilities, so whoever enters cheques is not also able to export your donor list
* Multiple currencies, with rates fetched daily or entered by hand
* An error log in the admin, so a failed payment or a bounced receipt is something you can see rather than something you hear about later

= Built to extend =

Dono is ordinary WordPress underneath. Blocks are blocks, pages are pages, and your data sits in your own database in tables you can query. Filters and actions are provided for settings, capabilities, admin screens and the donation lifecycle.

== Installation ==

1. Install Dono from Plugins > Add New, or upload the `dono` folder to `/wp-content/plugins/` and activate it from the Plugins screen.
2. Open **Dono** in the admin menu and follow the short onboarding to set your organisation name and currency.
3. Under **Dono > Settings**, add your Stripe or PayPal keys, or enable offline donations.
4. Create a campaign, then add a donation form to any page using the Dono donation form block.

== Frequently Asked Questions ==

= Is Dono really free? =

Yes, and there is no paid tier you have to reach before it is useful. Recurring giving, both payment methods, campaigns, the donor portal and reporting are all in the free plugin.

= Does Dono take a percentage of donations? =

No. Card payments run through your own Stripe or PayPal account, so their usual processing fees apply, but Dono adds nothing on top and never touches the money.

= Where do donations go? =

Straight to your own Stripe or PayPal account. Dono never holds your funds.

= Do I need a page builder or a separate form plugin? =

No. Campaign pages and donation forms are both built from blocks in the WordPress editor.

= Can I change how a campaign page looks? =

Yes, all of it. A campaign page is an ordinary WordPress page, so you can move, restyle or remove anything on it, including the blocks Dono added.

= Does it support recurring donations? =

Yes. One-time, weekly, fortnightly, monthly, quarterly and yearly, and donors manage their own plans from the donor portal.

= Is Dono GDPR-friendly? =

Donor data is encrypted at rest, consent is recorded per donation, and you can erase or anonymise a donor on request. You decide how long data is kept and whether donors can export or delete their own. Dono gives you the tools; whether your organisation is compliant depends on how you use them.

= Can I take donations in more than one currency? =

Yes. Add the currencies you accept and donors choose at the form. Reporting converts to your organisation's currency using a daily rate, or rates you enter yourself.

= Where is my data? =

In your WordPress database, in Dono's own tables. You can export donations and donors as CSV whenever you want.

== External services ==

Dono connects to the following third-party services. Nothing is sent to any of them unless the relevant feature is in use.

**Stripe** - used when you enable Stripe as a payment method. Donation amount, currency, and the donor's name and email are sent to Stripe to create and confirm a payment, and Stripe sends webhooks back to your site. Data is sent only when a donation is made through Stripe, and only using the API keys you supply.
Terms: https://stripe.com/legal
Privacy: https://stripe.com/privacy

**PayPal** - used when you enable PayPal as a payment method. Donation amount, currency, and the donor's name and email are sent to PayPal to create and capture a payment, and PayPal sends webhooks back to your site. Data is sent only when a donation is made through PayPal, and only using the API credentials you supply.
Terms: https://www.paypal.com/legalhub/useragreement-full
Privacy: https://www.paypal.com/legalhub/privacy-full

**Frankfurter** - used to fetch daily currency exchange rates so donations taken in other currencies can be reported in your organisation's currency. Only the currency code is sent. No donor or site data is sent. Turn it off under Dono > Settings > Currency, or enter rates by hand instead.
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

The full history lives in changelog.txt.
