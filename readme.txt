=== Gratora - Donation and Fundraising Platform ===
Contributors: donodp
Tags: donation, donate, fundraising, recurring donations, nonprofit
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.1.3
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Free donation plugin for nonprofits and charities. Donation forms, recurring donations, fundraising campaigns, donor management and receipts.

== Description ==

Gratora is a donation and fundraising platform for WordPress. Not a form plugin
you then extend, and not a starting point you pay to finish. The donation form,
the campaign page around it, the recurring plans, the donor record, the receipt
and the report are all here, in one free plugin, on day one.

It is made for nonprofits of every size: a charity, a church, a school, a club,
or anyone else who takes donations on a WordPress site. Supporters donate once
or set up a recurring donation, and pay by card, Apple Pay, Google Pay, SEPA
debit or PayPal.

See it before you install it: [the live demo](https://gratora.net/demo) runs in
your browser on a year of sample data. Guides, support and add-ons are at
[gratora.net](https://gratora.net).

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
* Put a form on any page with the Donation form block or its shortcode, or add
  a Donate button that opens the form in a pop-up

= Recurring donations =

* Weekly, every two weeks, monthly, quarterly or yearly, and you pick which of
  those each form offers
* Donors change, pause or cancel their own plan in the donor portal
* A receipt for every renewal, and an email to the donor the first time a
  payment is declined
* Part of the free plugin, with no add-on to buy

= Fundraising campaigns and funds =

* Gratora builds the campaign page for you, donation form included
* Goals by amount raised, by number of donations or by number of donors, with an
  optional end date
* Progress bars, recent donations, top donors and a supporter wall: the parts
  of a crowdfunding page, on your own site
* Funds let donors choose what their donation pays for.

= Donor management and a donor portal =

* Lifetime totals, every donation, receipts, consent and private staff notes
* Donors sign in with an email link, so there is no password to forget
* Lifecycle stages, segments, lifetime value and retention, worked out for you
* Export donors to CSV

= Donation receipts and reports =

* Receipts emailed automatically, plus branded PDF receipts and year-end
  statements donors can download themselves
* Sequential reference numbers with your own prefix and padding
* Revenue, donation and donor figures over any period, per campaign or overall
* A campaign report as a one-page PDF, and revenue as CSV

= Payments with Stripe and PayPal =

Take payments with your own Stripe or PayPal account, so the money goes straight
to you. Additional fees may apply from your payment provider. Stripe takes cards,
Apple Pay, Google Pay and SEPA debit. Cash, check and bank transfer are recorded
next to online donations, full and partial refunds are supported, and test mode
is kept out of your reporting.

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
contrast check so text stays readable. Gratora comes in English, German and
Spanish, and is ready to be translated into other languages.

= Your donor data stays yours =

Donors' email addresses, phone numbers, postal addresses and tax IDs are
encrypted at rest, as are private notes and answers to your own form fields.
Names, company and country are stored as plain text, so they can be searched and
sorted. Consent is recorded per donation, IP anonymization is on by default, and
you can erase or anonymize a donor on request. Gratora gives you the tools;
compliance depends on how you use them.

= Add-ons =

Gratora needs nothing else to run. When you need more, add-ons are available at
[gratora.net/add-ons](https://gratora.net/add-ons/):

* Peer-to-Peer: supporters raise money on their own pages, alone or in teams
* Event Tickets: sell tickets to fundraising events and check guests in by phone
* AI Assistant: ask about your fundraising and make changes in plain language
* Payment Gateways: Authorize.Net, Square, GoCardless, Moneris and Razorpay
* Conversion Tracking: report completed donations to GA4, Google Ads and Meta
* Connect: send donation and donor events to webhooks, Slack and Mailchimp
* Tributes: donations in honor or in memory of someone
* Gift Aid: collect UK Gift Aid declarations and prepare your claim for HMRC
* GiveWP Importer, free: move donors, donations, campaigns and recurring
  donations over from GiveWP

== External services ==

Gratora contacts these services only when you use the feature that needs them.

**Gratora** (gratora.net)
Fetches the list of add-ons and plans when you open the Add-ons screen, and
keeps it for a day, or for an hour when gratora.net does not answer. That sends
no information about your site beyond the request itself, which carries the
plugin's version.
When you deactivate the plugin it asks why. If you pick a reason, and only then,
the reason is sent with what you typed, the versions of Gratora, WordPress and
PHP, how many days ago the plugin was first activated and which setup steps were
done (a campaign made, a test donation, payments connected, a first donation).
The request does not include your site's address or name, or any email address.
Like any request it reaches gratora.net from your server's IP address, which is
not stored with the answer.
Terms: https://gratora.net/terms/ | Privacy: https://gratora.net/privacy/

**Stripe** (api.stripe.com, js.stripe.com)
Takes card and wallet payments once you connect Stripe. Receives the amount,
currency and donation reference, and the donor's name and email. Card details go
straight to Stripe.
Terms: https://stripe.com/legal/ssa | Privacy: https://stripe.com/privacy

**PayPal** (api-m.paypal.com, api-m.sandbox.paypal.com, www.paypal.com)
Takes PayPal payments once you connect PayPal. Receives the amount, currency and
donation reference.
Terms: https://www.paypal.com/legalhub/useragreement-full | Privacy: https://www.paypal.com/legalhub/privacy-full

**Frankfurter** (api.frankfurter.app, which redirects to api.frankfurter.dev)
Fetches exchange rates daily while automatic rates are on and you accept more
than one currency, and whenever you ask for them. Sends your base currency code.
Terms and privacy: https://frankfurter.dev/#faq

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

= Is Gratora free? =

Yes. Donation forms, recurring donations, campaigns, donor management, receipts
and reports are all in the free plugin. The add-ons are optional.

= Does Gratora take a cut of donations? =

No. You connect your own Stripe or PayPal account and donations settle straight
into it. Additional fees may apply from your payment provider.

= Can I take recurring donations without buying an add-on? =

Yes. Recurring donations are part of the free plugin: weekly, every two weeks,
monthly, quarterly or yearly. Donors change, pause or cancel their own plan in
the donor portal.

= Which payment methods can donors use? =

Cards, Apple Pay, Google Pay and SEPA debit through Stripe, and PayPal. Cash,
check and bank transfer are recorded as offline donations. The Payment Gateways
add-on adds Authorize.Net, Square, GoCardless, Moneris and Razorpay.

= How do I add a donation form to my site? =

Create a campaign and Gratora builds its page, donation form included. To put a
form anywhere else, use the Donation form block, the Donate button block, or the
shortcode shown on the form's own screen.

= Is there a demo? =

Yes, at [gratora.net/demo](https://gratora.net/demo). It runs in your browser on
sample data, so there is nothing to install.

= Can donors pay the processing fee for me? =

Yes. Add the "Cover the fees" block to a donation form, set the percentage and
the fixed amount, and choose whether it starts ticked.

= Can I change how a campaign page looks? =

All of it. A campaign page is an ordinary WordPress page, so you can move,
restyle or remove anything on it.

= Do I need a page builder or a separate form plugin? =

No. Campaign pages and donation forms are built from blocks in the WordPress
editor.

= Can I move from GiveWP or another donation plugin? =

Yes. The free GiveWP Importer add-on moves donors, donations, campaigns and
recurring donations over. From anything else, import a CSV of donors, or donors
and donations together, mapping your columns to Gratora fields.

= Can I try it without taking real money? =

Yes. A new site starts in test mode. Give with the Test donation method, or run
donations through your provider's sandbox, and none of it reaches your
reporting.

= Does Gratora help with GDPR? =

It gives you the tools: consent is recorded per donation, IP anonymization is on
by default, contact details are encrypted at rest, and you can erase or
anonymize a donor on request. Compliance depends on how you use them.

= Which languages does Gratora come in? =

English, German and Spanish, and it is ready to be translated into others.

= Which database does Gratora need? =

MySQL 5.7 or newer, or MariaDB 10.3 or newer. To see what your site runs, open
Tools > Site Health > Info and look under Database.

== Screenshots ==

1. The fundraising dashboard: what came in, where it came from, and what needs attention.
2. Every donation, filterable by status, campaign, gateway and frequency.
3. A donor record: lifetime giving, their whole history, receipts, consent and notes.
4. Donor insights: lifecycle stages, segments, lifetime value and retention.
5. Fundraising campaigns, each with its goal and progress.
6. A campaign in detail, with its own figures and a report to download.
7. The donation form builder. Fields and layout are blocks, so the editor is the one you already know.
8. Recurring donations, with monthly recurring revenue and the renewals that need attention.
9. Funds, so a donor can choose what their donation pays for.
10. Donation receipt settings: the template, your logo, and the merge tags it fills in.

== Changelog ==

= 1.1.3 =
* New: the Add-ons screen loads its add-ons and plans from gratora.net.
* Fixed: recurring donation screens on MySQL 5.7.
* Fixed: fields added by add-ons are saved when a form is shown on a fundraiser's page.
* Fixed: a deprecation notice in the log when the form editor opens.

Earlier releases are in [changelog.txt](https://plugins.svn.wordpress.org/gratora-donation-platform/trunk/changelog.txt).
