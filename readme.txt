=== Dono ===
Contributors: donodp
Tags: donations, donation form, fundraising, recurring donations, nonprofit
Requires at least: 7.0
Tested up to: 7.0.3
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Donation forms, campaigns, recurring giving, donor management, receipts and reporting.

== Description ==

**Dono is a complete fundraising platform for WordPress.**

Build a campaign, add a donation form, and start taking one-time and recurring donations today.

= Everything is included =

Recurring giving, funds and designated giving, a donor-facing currency switcher, fee recovery, conditional fields, the donor portal, PDF receipts and annual tax statements, offline donations and more.

Unlimited campaigns and forms.

= Donation forms =

Create beautiful, fully customizable donation forms in the WordPress editor.

* Suggested amounts, a custom amount, and a minimum you set
* One-time, weekly, biweekly, monthly, quarterly and yearly
* Multi-step, or everything on one screen
* Conditional fields
* Fee recovery, anonymous giving, a donor message and a fund picker
* Currency switcher and consent checkboxes

= Campaigns =

* Dono builds the campaign page for you, form included
* Progress bars, stats, recent donations, top donors and a supporter wall
* Goals by amount raised, donations or donors, with an optional end date
* Brand presets you reuse across campaigns and forms

= Recurring giving =

* Subscriptions through your payment provider
* Five frequencies, and you choose which ones each form offers
* Donors cancel or change their own plans
* Retry a failed renewal on Stripe

= Donors =

* Lifetime totals, full giving history and private notes
* Donor types and annual tax statements
* A donor portal reached by email link, with no password to forget
* Segment and export

= Payments =

* Stripe and PayPal, using your own accounts
* Cash, check and bank transfer recorded alongside online giving
* Full and partial refunds
* Test mode, kept out of reporting

= Receipts =

* Receipts emailed automatically
* Branded PDF receipts and year-end statements
* Sequential reference numbers with your own prefix and padding

= Reporting =

* Revenue, donation and donor metrics over any period
* Campaign performance as a one-page PDF
* CSV exports for donations, donors and revenue

= Privacy =

* Email, phone, address and tax ID encrypted at rest
* Consent recorded per donation
* Erase or anonymize a donor on request
* IP anonymization, on by default

== External services ==

Dono contacts the following third-party services. Nothing below runs unless the
feature that needs it is configured and in use.

**Stripe** (api.stripe.com, and js.stripe.com in the donor's browser)

Used only when Stripe is connected as a payment gateway. When a donor submits a
donation, or an administrator manages a recurring plan or issues a refund, Dono
sends the donation amount, currency, the donor's email address and name, and the
related order and subscription identifiers to Stripe's API. Card details are
entered into Stripe's own payment fields and never reach this site or its
database.

The js.stripe.com script is loaded into the donor's browser at the payment step,
on return from a redirect, and when a donor changes a saved card in the donor
portal. Stripe requires that this script be served from their domain rather than
bundled, because doing so keeps the site out of PCI scope.

Terms: https://stripe.com/legal/ssa
Privacy: https://stripe.com/privacy

**PayPal** (api-m.paypal.com, api-m.sandbox.paypal.com, and www.paypal.com in the
donor's browser)

Used only when PayPal is connected as a payment gateway. When a donor pays with
PayPal, Dono sends the donation amount, currency, the donor's email address and
name, and the related order and subscription identifiers to PayPal's API. The
sandbox host is contacted instead when the gateway is in test mode. PayPal's
checkout script is loaded into the donor's browser when the PayPal payment
option is shown, and carries the organisation's PayPal client id.

Terms: https://www.paypal.com/legalhub/useragreement-full
Privacy: https://www.paypal.com/legalhub/privacy-full

**Frankfurter** (api.frankfurter.app)

Used only when the site accepts a currency other than its own, which is what the
currency switcher and multi-currency reporting need. Once a day Dono requests
European Central Bank reference rates. The request contains a three-letter
currency code and nothing else: no donor, donation or site data is sent.

Terms and privacy: https://frankfurter.dev

**Gravatar** (gravatar.com)

Off by default. When "Show Gravatar profile pictures" is enabled under Settings,
Privacy, donor lists request avatars through WordPress's own avatar functions,
which sends a hash of the donor's email address to Gravatar from the browser of
whoever is viewing the page. Anonymous donors are never shown one.

Terms: https://automattic.com/terms/
Privacy: https://automattic.com/privacy/

== Installation ==

1. Install Dono from Plugins > Add New, or upload the `dono` folder to `/wp-content/plugins/` and activate it.
2. Open **Dono** in the admin menu and follow the short onboarding.
3. Under **Dono > Settings**, add your payment provider keys, or enable offline donations.
4. Create a campaign, then add a donation form to any page.

== Frequently Asked Questions ==

= Can I change how a campaign page looks? =

All of it. A campaign page is an ordinary WordPress page, so you can move, restyle or remove anything on it.

= Do I need a page builder or a separate form plugin? =

No. Campaign pages and donation forms are built from blocks in the WordPress editor.

= Is Dono GDPR-friendly? =

Email, phone, address and tax ID are encrypted at rest, consent is recorded per donation, and you can erase or anonymize a donor on request. Dono gives you the tools; compliance depends on how you use them.

= Can I bring donations in from somewhere else? =

Yes. Import a CSV of donors, or donors and donations together, mapping your columns to Dono fields.

== Screenshots ==

1. The block-based donation form builder.
2. A campaign page with a live progress bar and donation form.
3. The admin dashboard with donation and donor metrics.
4. A donor record with lifetime giving, history, and their recurring plan.
5. The self-service donor portal, where a donor sees their own giving.
6. Settings: payment methods, currency, and receipts.

== Changelog ==

= 1.0.0 =
* Initial release.

The full history lives in changelog.txt.
