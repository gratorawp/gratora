=== Gratora - Donation Platform ===
Contributors: donodp
Tags: donation, fundraising, nonprofit, recurring donations, donation form
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Donation forms, recurring donations, campaigns, funds, PDF receipts and donor records. Donors can cover the processing fee. Everything included.

== Description ==

Gratora is a donation and fundraising platform for WordPress. Not a form plugin
you then extend, and not a starting point you pay to finish. The donation form,
the campaign page around it, the recurring plans, the donor record, the receipt
and the report are all here, in one plugin, on day one.

= Donation forms built in the WordPress editor =

Fields and layout are blocks, so the editor is the one you already know. No page
builder and no separate form plugin.

* Suggested amounts with captions, a custom amount, and a minimum you set
* Everything on one screen, or split into steps
* Cover the fees, recurring toggle, fund picker and currency switcher
* Name, email, phone, address, country and a message of support
* Your own custom fields: text, number, dropdown, radio, checkbox, multi-select, date
* Show a field only when it applies, based on the amount, the frequency or
  another answer on the form
* Anonymous giving, consent checkboxes and a privacy notice

= Recurring donations =

* Weekly, every two weeks, monthly, quarterly or yearly, and you pick which of
  those each form offers
* Donors change or cancel their own plan

= Campaigns and funds =

* Gratora builds the campaign page for you, donation form included
* Goals by amount raised, by number of donations or by number of donors, with an
  optional end date
* Progress bars, recent donations, top donors and a supporter wall
* Funds let donors choose what their donation pays for.

= Donor management and a donor portal =

* Lifetime totals, every donation, receipts, consent and private staff notes
* Donors sign in with an email link, so there is no password to forget
* Lifecycle stages, segments, lifetime value and retention, worked out for you
* Export donors to CSV

= Receipts and reports =

* Receipts emailed automatically, plus branded PDF receipts and year-end
  statements donors can download themselves
* Sequential reference numbers with your own prefix and padding
* Revenue, donation and donor figures over any period, per campaign or overall
* A campaign report as a one-page PDF, and revenue as CSV

= Payments =

Take payments with your own Stripe or PayPal account, so the money goes straight
to you. Additional fees may apply from your payment provider. Cash, check and
bank transfer are recorded next to online donations, full and partial refunds are
supported, and test mode is kept out of your reporting.

Public donation endpoints are rate limited, so a run of automated card attempts
does not turn into a run of donation records.

= More than one currency =

Accept the currencies you choose, show donors a currency switcher on the form.

= Ask donors to cover the processing fee =

Add one block to your donation form and donors are offered the chance to add the
payment processing fee on top of their donation, so the fee comes out of the
payment rather than out of the donation. You set the percentage and the fixed
amount, and you decide whether it starts ticked.

= Made to look like your site =

Style presets you set once and reuse across every campaign and form, with a
contrast check so text stays readable. Gratora is translation ready and ships a
.pot file.

= Your donor data stays yours =

Email, phone, address and tax ID are encrypted at rest. Consent is recorded per
donation, IP anonymization is on by default, and you can erase or anonymize a
donor on request. Gratora gives you the tools; compliance depends on how you use
them.

== External services ==

Nothing here is contacted until you configure the feature that needs it. A fresh
install talks to no one. Every request this site's server sends to one of these
services carries the site address and WordPress version, which WordPress puts in
the user agent of every outbound request.

**Stripe** (api.stripe.com, plus js.stripe.com in the donor's browser)
Only with Stripe connected. Saving your keys checks them with Stripe and
registers this site's webhook address on your Stripe account, so Stripe can send
payment updates back here. A donation sends the amount, currency, the donation
reference and Gratora's ids for the donation, donor, form and campaign. The
donor's name and email are filled in on Stripe's payment form in their browser,
and a recurring donation also sends them to create a Stripe customer, along with
your site name on the recurring product. Stripe is contacted again when you
refund a donation (with any note you add), when you retry a failed renewal or a
recurring setup, when you or a donor change, pause, resume or cancel a plan,
when a donor replaces their card, when a paused plan's resume date arrives, when
you register this domain for Apple Pay, when a payment update from Stripe needs
details looked up, and to cancel an unfinished payment that was declined
repeatedly or that you trash or delete. Registering for Apple Pay also has this
site serve the domain association file that Apple checks. Depending on what you
enable in your Stripe account, Stripe's payment form can offer wallets such as
Apple Pay, Google Pay and Link, or send the donor to their bank to approve the
payment. Card details go straight to Stripe and never reach this site. Their
script must be loaded from their domain to keep your site out of PCI scope.
Terms: https://stripe.com/legal/ssa | Privacy: https://stripe.com/privacy

**PayPal** (api-m.paypal.com, api-m.sandbox.paypal.com, plus www.paypal.com in
the donor's browser)
Only with PayPal connected. Saving your credentials exchanges them with PayPal
for an access token and checks the webhook id you enter. A donation sends the
amount, currency and donation reference; a recurring donation also creates a
plan named after its amount and how often it renews. Gratora does not send the
donor's name or email: the donor approves the payment on PayPal, and PayPal
sends back the payer's email address with the payment. PayPal is contacted again
when you refund a donation (any note you add is shown to the donor), when you or
a donor change, pause, resume or cancel a plan (with the cancellation reason),
when a donor changes how they pay, when a paused plan's resume date arrives, to
verify each payment update PayPal sends this site and look up what it refers to,
and to check on PayPal payments that have not settled, about once an hour and
before you trash or delete one. The sandbox host is used in test mode. Their
checkout script carries your PayPal client id and the donation currency.
Terms: https://www.paypal.com/legalhub/useragreement-full | Privacy: https://www.paypal.com/legalhub/privacy-full

**Frankfurter** (api.frankfurter.app, which redirects to api.frankfurter.dev, so
allowlist both)
Requests European Central Bank rates once a day, but only while "Update rates
automatically every day" is on and this site has money in a currency other than
your own: a currency you accept, a donation already recorded without a rate, or
a live recurring plan that will renew in one. Also requests them whenever you
press "Fetch rates now" on Fundraising > Settings > Currency. Sends your base
currency's three-letter code. The service is served through Cloudflare, which
sees the request in transit. Frankfurter publishes no separate terms or privacy
policy; its FAQ covers commercial use and what it logs.
Terms and privacy: https://frankfurter.dev/#faq

**Gravatar** (secure.gravatar.com, in the browser of whoever views the page)
Only when you turn on "Show Gravatar profile pictures" on
Fundraising > Settings > Privacy. Donor lists in the campaign blocks (recent
donations, top donors, supporter wall) and on your admin donor screens then show
each donor's Gravatar, unless the donor uploaded a picture of their own. Each
picture is requested by the viewer's browser, which sends Gravatar a SHA-256
hash of the donor's email address and the viewer's IP address. On public pages,
anonymous donations and donors you have hidden never get one.
Terms: https://wordpress.com/tos/ | Privacy: https://automattic.com/privacy/

== Source code ==

The JavaScript and CSS in `build/` are compiled. The sources they are built
from, and the tooling that builds them, are in the public repository:

https://github.com/gratorawp/gratora

== Installation ==

1. Install Gratora from Plugins > Add New, or upload the plugin folder to `/wp-content/plugins/` and activate it.
2. Open **Fundraising** in the admin menu and follow the short onboarding.
3. Under **Fundraising > Settings**, add your payment provider keys, or enable offline donations.
4. Create a campaign, then add a donation form to any page.

== Frequently Asked Questions ==

= Does Gratora take a cut of donations? =

No. You connect your own Stripe or PayPal account and donations settle straight
into it. Additional fees may apply from your payment provider.

= Can donors pay the processing fee for me? =

Yes. Add the "Cover the fees" block to a donation form, set the percentage and
the fixed amount, and choose whether it starts ticked.

= Can I change how a campaign page looks? =

All of it. A campaign page is an ordinary WordPress page, so you can move,
restyle or remove anything on it.

= Do I need a page builder or a separate form plugin? =

No. Campaign pages and donation forms are built from blocks in the WordPress
editor.

= Can I bring donations in from somewhere else? =

Yes. Import a CSV of donors, or donors and donations together, mapping your
columns to Gratora fields.

= Can I try it without taking real money? =

Yes. Turn on test mode, run donations through your provider's sandbox, and none
of it reaches your reporting.

== Screenshots ==

1. The dashboard: what came in, where it came from, and what needs attention.
2. Every donation, filterable by status, campaign, gateway and frequency.
3. A donor record: lifetime giving, their whole history, receipts, consent and notes.
4. Donor insights: lifecycle stages, segments, lifetime value and retention.
5. Campaigns, each with its goal and progress.
6. A campaign in detail, with its own figures and a report to download.
7. The block-based form builder. Fields and layout are blocks, so the editor is the one you already know.
8. Recurring plans, with monthly recurring revenue and the renewals that need attention.
9. Funds, so a donor can choose what their donation pays for.
10. Receipt settings: the template, your logo, and the merge tags it fills in.

== Changelog ==

= 1.0.0 =
* Initial release.
