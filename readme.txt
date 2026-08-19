=== Dono Fundraising Platform ===
Contributors: donodp
Tags: donations, donation form, fundraising, recurring donations, nonprofit
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Donation forms, campaigns, recurring giving, donor management, receipts and reporting.

== Description ==

**Dono is a fundraising platform for WordPress.**

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

Nothing here is contacted until you configure the feature that needs it. A fresh
install talks to no one.

**Stripe** (api.stripe.com, plus js.stripe.com in the donor's browser)
Only with Stripe connected. Sends the amount, currency, donor name and email, and
the order or subscription id when a donation is made or a plan is managed. Card
details go straight to Stripe and never reach this site. Their script must be
loaded from their domain to keep your site out of PCI scope.
Terms: https://stripe.com/legal/ssa | Privacy: https://stripe.com/privacy

**PayPal** (api-m.paypal.com, api-m.sandbox.paypal.com, plus www.paypal.com in the
donor's browser)
Only with PayPal connected. Sends the same donation details; the sandbox host is
used in test mode. Their checkout script carries your PayPal client id.
Terms: https://www.paypal.com/legalhub/useragreement-full | Privacy: https://www.paypal.com/legalhub/privacy-full

**Frankfurter** (api.frankfurter.app, which redirects to api.frankfurter.dev, so
allowlist both)
Only when you accept a currency other than your own. Requests European Central
Bank rates once a day, and again whenever you press "Fetch rates now" on
Settings > Currency. Sends a three-letter currency code, along with the site
address and WordPress version that WordPress itself puts in the user agent of
every outbound request. The service is served through Cloudflare, which sees the
request in transit.
Terms and privacy: https://frankfurter.dev

== Installation ==

1. Install Dono from Plugins > Add New, or upload the plugin folder to `/wp-content/plugins/` and activate it.
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

1. The dashboard: what came in, where it came from, and what needs attention.
2. Every donation, filterable by status, campaign, gateway and fund.
3. A donor record: lifetime giving, their whole history, and their recurring plan.
4. Donor insights: lifecycle stages, segments, lifetime value and cohort retention.
5. Campaigns, each with its goal and progress.
6. A campaign in detail, with its own figures, forms and recent donations.
7. The block-based form builder. Fields and layout are blocks, so the editor is the one you already know.
8. Recurring donations, with pause, resume, skip and cancel on each plan.
9. Funds, so a donor can choose what their donation pays for.
10. Receipt settings: numbering, the PDF, and when it is sent.

== Changelog ==

= 1.0.0 =
* Initial release.
