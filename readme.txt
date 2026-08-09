=== Dono - Fundraising Platform ===
Contributors: donodp
Tags: donations, donation form, fundraising, recurring donations, nonprofit
Requires at least: 7.0
Tested up to: 7.0.3
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Donation forms, recurring giving, campaigns and encrypted donor records, self-hosted on the WordPress site you already run.

== Description ==

**Take donations on your own site, through your own payment account, into your own database.**

Dono is a complete fundraising platform for WordPress: a fundraising stack, not just a donate button. Build a campaign page, put a donation form on it, and start accepting one-time and recurring donations the same afternoon. Every donation lands in your database. Every donor record is yours. Dono never touches the money and never takes a cut.

The form builder, recurring billing, campaigns, donor records and reporting are one tool that lives entirely on the WordPress site you already control. There is no account to be locked out of, no onboarding call and no sales process.

It is built the way WordPress is built. Campaign pages are pages. Donation forms are blocks. If you know the editor, you already know how to change any of it.

= 💚 Everything here is free =

Not a trial, and not a teaser. Recurring giving, both payment gateways, unlimited campaigns and forms, the donor portal, PDF receipts and reporting are all in this plugin.

There is no donation cap. There is no platform fee. You will not hit a wall at your fiftieth donation or discover that monthly giving costs extra.

= 🎨 Campaigns you design, not templates you tolerate =

Most donation plugins hand you a campaign page and let you change the colours. Dono hands you a WordPress page.

Open it in the editor and it behaves like every other page on your site, because it is one. Move the progress bar. Delete the stats. Put your own content between them. Nothing is locked, including the blocks Dono added when it created the page.

* Progress bars, stats, recent donations, top donors and a supporter wall, placed wherever you want them
* Goals by amount raised, number of donations or number of donors, with an optional end date
* A grid of campaigns for a landing page or a sidebar
* Save a look as a brand preset and reuse it across every campaign and form

= 🧱 Forms built from blocks =

The form builder is the WordPress editor. There is no separate interface to learn and no proprietary builder to fight. Start from one of the templates and adjust it, or start from an empty canvas.

* Suggested amounts, a custom amount, and a minimum you set
* One-time, weekly, fortnightly, monthly, quarterly and yearly, offered from the same form
* Split a long form into steps, or keep everything on one screen
* Show and hide fields based on what the donor has already chosen
* Name, email, phone, address, country, dropdowns, checkboxes, radio buttons, dates, numbers and free text
* Ask donors to cover the processing fee
* Anonymous giving, a message from the donor, and a fund picker
* A currency switcher when you accept more than one currency
* Consent checkboxes and a privacy notice, recorded against the donation

= 🔁 Recurring giving, included =

Recurring donors are worth many times a one-off, which is exactly why so many plugins put them behind a paid tier. Dono does not.

* Subscriptions through Stripe or PayPal
* Five recurring frequencies, and you decide which ones a form offers
* Donors cancel or change their own plans from the portal, without emailing you first
* Cancelled and failed plans are reflected in your totals rather than quietly inflating them

= 👥 Donors, and a portal they can use themselves =

Every donation builds a donor record, and every donor gets a way in without you creating an account for them.

They receive a link, and from there they can see their giving history, download a receipt, change a subscription or update their consent. No password to forget, and no account admin for you.

* Donor records with lifetime totals, full history and private notes
* Households, donor types, and per-donor annual tax statements
* Segment and export by campaign or by when the record was created

= 🔒 Privacy and GDPR, handled properly =

Donor data is treated as something you are responsible for, rather than something to collect as much of as possible. This is not a checkbox and a privacy policy link.

* Names, emails, phone numbers, addresses and tax IDs are encrypted at rest
* Consent is recorded per donation, with the purpose, the time and where it was given
* Erase a donor on request, with a retention window before the record is fully cleared
* Anonymise donors who have been inactive for a number of years you choose
* IP anonymisation, on by default
* Let donors export or delete their own data from the portal, or turn either off
* Decide how long activity data is kept
* Choose exactly which columns leave the site in an export, because an export is a file that travels

= 📊 Reporting you can act on =

* A dashboard with revenue, donation and donor metrics over any period
* Campaign performance as a one-page PDF you can hand to a board
* CSV exports for donations, donors, and revenue month by month
* An error log in the admin, so a declined payment or a bounced receipt is something you can see rather than something you hear about later

= 💳 Payments =

* Stripe and PayPal, using your own account and your own API keys
* Offline and manual donations for cash, cheques and bank transfers
* Refunds, including partial ones, recorded against the donation
* Test mode for a single form or the whole site, kept out of your reporting

= 🧾 Receipts and documents =

* Email receipts sent automatically, in your branding
* PDF receipts and year-end annual statements
* Sequential reference numbers for donations, receipts and refunds, in a format you set

= 🌱 Your first campaign =

Install the plugin and a short setup asks for your organisation name, your country and your currency. Add your Stripe or PayPal keys, or skip payments entirely and start with offline donations.

Create a campaign and Dono builds the page for you, form included. Edit it like any other page. When it looks right, publish.

= 🤝 Who Dono is for =

Nonprofits and registered charities that need receipts, tax statements and a donor list they actually own. Churches, schools, clubs and mutual aid groups collecting from a community. Individuals running a personal cause or a memorial fund. Developers building any of the above for a client.

= 📈 Built to scale =

A donation plugin is easy to make feel fast on a demo site with forty donations. Dono is built for the year you have fifty thousand.

* Donations, donors, campaigns and funds live in Dono's own database tables, indexed for the questions the admin actually asks, rather than being spread across WordPress post meta
* Campaign, fund and donor totals are kept as you go, so a campaign page shows its progress bar without recounting every donation that built it
* The donation list, donor list and reporting screens page and filter in the database, not in PHP after the fact
* Exports stream to the browser as they are generated, so a large CSV does not have to fit in memory first
* Importing years of history runs in batches across requests and resumes where it stopped, rather than depending on one long request surviving
* A data backfill after an upgrade is queued rather than run inside the request that happened to notice the plugin had been updated

Tested with 50,000 donations across 8,000 donors and a dozen campaigns, which is more than most organisations will raise in a decade.

= 🛠️ Open source, and built to extend =

Dono is GPL and developed in the open. It is ordinary WordPress underneath: blocks are blocks, pages are pages, and your data sits in your own database in tables you can query.

Filters and actions are provided for settings, capabilities, admin screens and the donation lifecycle, and the REST API the admin runs on is available to you as well.

== Installation ==

1. Install Dono from Plugins > Add New, or upload the `dono` folder to `/wp-content/plugins/` and activate it from the Plugins screen.
2. Open **Dono** in the admin menu and follow the short onboarding to set your organisation name and currency.
3. Under **Dono > Settings**, add your Stripe or PayPal keys, or enable offline donations.
4. Create a campaign, then add a donation form to any page using the Dono donation form block.

== Frequently Asked Questions ==

= Is Dono really free? =

Yes, and there is no paid tier you have to reach before it is useful. Recurring giving, both payment methods, unlimited campaigns and forms, the donor portal, PDF receipts and reporting are all in this plugin.

= Does Dono take a percentage of donations? =

No. Card payments run through your own Stripe or PayPal account, so their usual processing fees apply, but Dono adds nothing on top and never touches the money.

= Why not just use a PayPal button? =

A button takes a payment. It does not give you a donor record, a recurring plan the donor can manage, a receipt with a sequential number, a campaign page with a live total, or a report at the end of the year. Dono is the part around the payment.

= Why not a general form plugin with a payment add-on? =

Because a form plugin gives you submissions, not donors. There is no lifetime total, no recurring management, no tax statement, no refund recorded against the original donation, and no answer when someone asks you to delete their data.

= This is version 1.0. Why should I trust it with donations? =

Fair question. Dono is new, so judge it on what you can check rather than on how long it has existed. The code is open source and readable. Payments go through Stripe and PayPal directly with your own keys, so your money never depends on Dono staying in business. Your data is in your own database in plain tables, and you can export all of it as CSV at any time. If Dono is not for you, you are not locked in.

= Will it slow my site down once donations add up? =

Dono keeps its data in its own tables rather than in WordPress post meta, and those tables are indexed for the queries the admin screens run. Totals are stored as donations arrive, so a campaign page reads one number instead of adding up its whole history on every visit. It has been tested with 50,000 donations, and the admin screens are paged and filtered in the database so the work does not grow with the size of your donor list.

The donation form itself is a block on your page. It does not query your donation history to render, so a busy fundraising year does not make your public pages slower.

= Can I change how a campaign page looks? =

All of it. A campaign page is an ordinary WordPress page, so you can move, restyle or remove anything on it, including the blocks Dono added.

= Do I need a page builder or a separate form plugin? =

No. Campaign pages and donation forms are both built from blocks in the WordPress editor.

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

**Frankfurter** - used to fetch daily currency exchange rates so donations taken in other currencies can be reported in your organisation's currency. Only the currency code is sent. No donor or site data is sent. Nothing is requested at all unless you accept a second currency, so a single-currency site never contacts it. You can also turn it off under Dono > Settings > Currency, or enter rates by hand instead.
Service: https://frankfurter.dev
Terms and privacy: https://frankfurter.dev

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
