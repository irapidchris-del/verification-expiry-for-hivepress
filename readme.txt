=== Verification Expiry for HivePress ===
Contributors: chrisb
Tags: hivepress, vendors, verified, verification, identity
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.1.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Verification requests with private documents, a review queue, paid verification and five check providers, plus badge expiry dates.

== Description ==

HivePress lets you mark a Vendor as verified with one tick of a box. This plugin turns that box into a complete verification system: Vendors apply from their account by uploading documents, you review them in a queue in wp-admin, approving ticks the box and starts an expiry clock, and the badge is removed and the Vendor emailed when the date passes. Verification can be free, paid through a WooCommerce product, or checked automatically by one of four services, two of which cost nothing per check.

Everything new is off or harmless by default. A site running 1.2.0 updates with no visible change except a new Verification page in every Vendor's account and new sections on the settings tab.

**Applying**

* A Verification page in the Vendor's account (and a "Verification" block and `[hivepress_hpve_verification]` shortcode for any public page) with a status card: not started, payment needed, pending review, more information needed, verified with the review date, not approved with the reason, expired.
* A document form built from the document types you choose: photo ID, qualification certificate, insurance certificate and proof of address by default, each on or off, required or optional, with its own file types (JPG, PNG, WEBP, PDF), size limit and number of files.
* A "Get verified" prompt on the Vendor dashboard and account settings page while the Vendor is not verified or has expired.
* Emails to the applicant when their documents are received, when more information is needed (with your note), and when the request is not approved (with your reason). Approval sends the existing Vendor Verified email.

**Documents are private**

* Files are stored outside the folder your web server publishes when the host allows it, otherwise in a randomly named folder inside uploads with a deny rule, and the settings tab says which.
* Every link to a document goes through a download gate that checks who you are first. Only the applicant and reviewers (Editors, Administrators and Shop Managers) can open one, and reviewer views are recorded.
* Documents are deleted a set number of days after a decision (30 by default); the request and its history are kept.
* A privacy exporter and eraser cover verification requests.

**Reviewing**

* A Verifications screen under HivePress in wp-admin with a count badge for pending requests, columns for status, documents, payment, submission date and reviewer, and a filter for requests that need information, were not approved or have expired.
* A review screen showing every document inline, the applicant's note, and three decisions: Approve, Ask for more information (with a note that is emailed) and Reject (with a reason that is emailed). Decided requests can be re-opened.
* A History box recording who did what and when, including automated checks and payments.
* A second line on the Vendors screen column showing the state of the Vendor's request.

**Paid verification (optional)**

* Choose a WooCommerce product and Vendors see a Buy button. A paid order marks the request Paid and sorts it first in the queue, or creates the request and emails the buyer to send their documents. Optionally, payment can be required before documents are accepted.
* Choose a WooCommerce Subscriptions product and each paid renewal restarts the verification period.
* Verified Vendors can renew early during the reminder window and keep their badge while the new documents are reviewed.
* A refund or cancellation is recorded and shown, and never removes a badge by itself.
* Payment never approves anybody: documents are still checked.

**Automated checks (optional)**

Pick one provider. Every result is mapped to approved, needs more information or not approved, with an attempt limit before the document form is offered instead, and a pass can be held for an admin to confirm.

* **Manual review** (the default). A person reads the documents in wp-admin. Free, and the only option that needs no account anywhere.
* **Stripe Identity.** Applicants are sent to Stripe's hosted page to photograph their ID and optionally a selfie. Uses the WooCommerce Stripe gateway's existing key, in whichever mode the gateway is in. Charged per completed check.
* **Persona.** The same shape of hosted flow, with a free allowance for low volumes. Needs an API key, an inquiry template with a one-time link, and a webhook secret.
* **ComplyCube.** UK based, usually cheaper per check. The key decides the mode: one starting test_ runs in their sandbox where nothing is charged.
* **Companies House.** Free. The applicant types their company number instead of uploading anything, and the register is asked whether the company exists, whether it is still trading and whether its registered name matches the Vendor's. Needs a free API key.
* **VAT number check.** Free and needs no key at all: UK numbers go to HMRC and other member-state numbers to VIES.

The last two check a **business**, not a person, and the settings say so: someone could type a real company number that is not theirs, and plenty of sole traders have neither number. Pair them with documents, or use an identity provider, if proving the person matters on your site.

* Every call to every service runs in a background job, so no visitor's page load ever waits on a third party.
* No API key is ever written into a log, an email or a request record.
* Other providers can be added through a small interface; see the developer note in the FAQ.

**Expiry (from 1.x)**

* A Verification Period and a Verified Until date on every Vendor and Listing, a site-wide default, reminder and expiry emails, columns and bulk actions on the Vendors and Listings screens.

The checks run through HivePress's own scheduler, so nothing needs setting up.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/verification-expiry-for-hivepress` directory, or install the plugin zip through the WordPress admin.
2. Activate the plugin through the Plugins screen. HivePress must be installed and active.
3. Go to HivePress, Settings, Verification Expiry. Choose a default period, check the document types under Documents, and read the sentence there that says where documents are stored.
4. Optionally pick a product under Paid Verification, and choose a provider under Automated Checks. The free ones are set up in Free Business Registers; Persona and ComplyCube in Other Identity Services.
5. Vendors find the Verification page in their account menu. To add it to a public page, insert the Verification block or the `[hivepress_hpve_verification mode="button"]` shortcode.

Once installed, the plugin checks for new versions automatically and updates through the normal WordPress Plugins screen, just like a plugin from the WordPress.org directory.

== Frequently Asked Questions ==

= Who can see the uploaded documents? =

The applicant and reviewers. A reviewer is anyone who can edit other people's posts: Editors, Administrators and, on WooCommerce sites, Shop Managers. A developer can raise that to Administrators only with the `hpve_verification_review_capability` filter. Nobody else can open a document, even with the link, and there is no public address for the file.

= Where are documents stored? =

Outside the folder your web server publishes when the plugin can write there, which is the safest place. When it cannot, they go into a folder with a random name inside uploads, protected by a deny rule; on nginx and many managed hosts that rule is not enforced, so the download gate's sign-in check is the only protection. The Documents section of the settings tab says which applies to your site. To use a folder of your own, add `define( 'HPVE_PRIVATE_DIR', '/path/outside/web/root' );` to wp-config.php. Backup and migration tools that copy only the uploads folder will miss an external folder.

= Why is HEIC not accepted? =

Browsers cannot show HEIC inline and WordPress only reads it with extra server software. iPhones send a JPEG instead when Settings, Camera, Formats is set to Most Compatible, or when the photo is shared from Safari.

= What happens when I approve a request? =

The Verified box on the Vendor is ticked, exactly as if you had ticked it yourself, so the badge shows, the Vendor Verified email is sent and the Verification Period starts. Approving a renewal from a Vendor who is still verified adds a full period from the old expiry date instead, so renewing early loses nothing. Rejecting leaves the box unticked and emails the reason. If you tick the box on the Vendor's own edit screen while a request is pending, the request is approved and the History says so.

= How does paid verification work? =

Pick a virtual, non-downloadable product whose author is an administrator who is not a Vendor (so the order is not credited to a Vendor as a sale). When an order containing it is paid, the buyer's request is marked Paid and sorted to the top of the queue; if they have no request yet one is created and they are emailed to send their documents. With "Payment Required" ticked, an unpaid Vendor sees a Buy button instead of the form. Guest checkout cannot be linked to an account, so require an account for that product.

= What does Stripe Identity need? =

The WooCommerce Stripe gateway with its keys entered, the Identity application completed in your Stripe Dashboard, and a webhook endpoint added under Developers, Webhooks for the `identity.verification_session.*` events pointing at the address shown on the settings tab, with its signing secret pasted into the matching field (test or live). Stripe charges a fee per completed check; nothing is charged in test mode. The settings tab shows whether the gateway is in test or live mode and whether a key is present.

= Which provider should I choose? =

If you need to prove that the person holding the account is who they say, you need an identity check: Stripe Identity, Persona or ComplyCube. All three charge per completed check, so compare their pricing; Persona has a free allowance for low volumes and ComplyCube is often cheaper than Stripe. If your Vendors are registered businesses and what matters is that the business is real and trading under the name on their profile, Companies House or the VAT number check does that for nothing. If you would rather read the documents yourself, leave it on Manual review.

= What do the free checks actually prove? =

That a business with that number exists, that the register still lists it as trading, and that the name on the register matches the Vendor name. They do not prove who the applicant is: someone could type a real company number that is not theirs. They also miss honest Vendors, because a sole trader under the VAT threshold has no VAT number and an unincorporated business has no company number. Treat them as a business check alongside documents, not as a replacement for identity.

= What do Persona and ComplyCube need? =

Persona needs an API key from its Dashboard, the id of an inquiry template with "Create a one-time link" switched on, and a webhook pointing at the Persona address shown on the settings tab, with its secret pasted in. ComplyCube needs an API key, a workflow template id, and a webhook pointing at its own address with its secret. Each service has its own address, so the two never cross. The webhook secret is required rather than optional for both: without it a result can only arrive if the applicant returns to the site, which they may simply not do.

= Does a check hold up the page? =

No. Every call to every service, including the free registers, runs in a background job. Nothing a visitor does makes their page wait on a third party, and a service being down leaves the request pending with a line in its history rather than refusing the applicant.

= Can I change the wording of the emails? =

Yes. All twelve emails appear under HivePress, Emails, where you can edit the subject and body like any other HivePress email. The tokens each one accepts are listed on its edit screen.

= Can I add another verification provider? =

Yes. A provider is a class implementing `Verification_Expiry\Providers\Hpve_Provider_Interface` (see `includes/providers/interface-hpve-provider.php`), registered through the `hpve_verification_providers` filter. It starts a check, tells the applicant where to go, receives the result and hands it back to the request component; it never changes a request's status itself.

= Does deleting the plugin remove my data or unverify anyone? =

Deleting the plugin never removes anyone's verified status. Your settings, every Vendor's dates and every verification request with its documents are kept by default, even though the WordPress delete screen warns that data will be removed, so a reinstall picks up where you left off. If you want everything gone, including the private folder, tick "Delete all data when this plugin is deleted" on the settings tab before deleting.

== Changelog ==

= 2.1.2 =
* New: verification requests. Vendors apply from a Verification page in their account by uploading documents of the types chosen on the settings tab (photo ID, qualification certificate, insurance certificate and proof of address by default), with a status card for every stage.
* Important: requests are switched on after updating, so every Vendor who is not verified sees the Verification page and a "Get verified" prompt straight away. To keep things as they were, untick Let Vendors apply for verification under HivePress, Settings, Verification Expiry, Verification Requests.
* New: emails to the applicant when a request is received, needs more information or is not approved, all editable under HivePress, Emails.
* New: private document storage. Files are kept outside the published folder where the host allows it, every link goes through a download gate, only the applicant and reviewers can open a file, reviewer views are recorded, and documents are deleted a set number of days after a decision. The settings tab says which storage mode the site is in.
* New: a Verifications screen under HivePress in wp-admin with a pending count, filters, a review screen showing every document, Approve, Ask for more information and Reject actions, and a History of who did what. The Vendors screen column gains a second line for the request.
* New: early renewal. During the reminder window a verified Vendor sees "Renewal due" on their Verification page and can send up-to-date documents straight away. The badge stays on during review, and an approved renewal adds a full period from the old date.
* New: paid verification through a WooCommerce product, with paid requests marked and sorted first, an optional "payment required" mode, subscription renewals restarting the period, and refunds recorded without removing a badge. A renewal needs no second payment.
* New: automated checks through Stripe Identity using the WooCommerce Stripe gateway's key, with a signed webhook, an attempt limit, optional admin sign-off and optional redaction at Stripe. Off by default.
* New: Persona and ComplyCube as alternative hosted identity checks, each with its own signed webhook address.
* New: free Companies House and VAT number checks. The applicant types a company or VAT number, the public register confirms the business exists and is trading, and the registered name is compared with the Vendor name. A company listed as in liquidation or administration always waits for a person.
* New: a Verification block and [hivepress_hpve_verification] shortcode for a public Get Verified page, and a "Get verified" prompt on the Vendor dashboard and account settings page.
* New: a privacy exporter and eraser for verification requests.
* Changed: while requests are switched on, the default reminder email links to the Verification page and says the Vendor can renew now. A reminder email already edited under HivePress, Emails keeps its own text; add the %verification_url% token to link to the page.
* Changed: "Delete all data" now also removes every request, its documents, history and the private folder. Nothing is removed unless that box is ticked.

= 1.2.0 =
* New: listing verification expiry. With "Badges to Expire" left at "Vendor badge only", every listing's own Verified box now has a Verification Period and a Verified Until date under it, with a separate site default under HivePress, Settings, Verification Expiry, Verified Listings. The badge is removed on the day after the date, exactly as for vendors.
* New: three emails to the listing's owner, when the listing is verified, a set number of days before its date, and when the badge is removed. Editable under HivePress, Emails, and the verified and expiry ones can be switched off.
* New: a Verification column and the Apply verification period and Remove expiry date bulk actions on the Listings screen.
* Changed: choosing "Vendor and listing badges, kept in sync" now clears any period and date set on individual listings, since in that mode listing badges follow the vendor.

= 1.1.2 =
* Fixed: updating two of these extensions one after the other could fail on the second with "up to date" until Check for updates was pressed again. WordPress rebuilds its update list after each update by asking wordpress.org first, and gives up on the whole list when that call is slow; the plugin now keeps its own update in the list regardless.
* Changed: a release found more than an hour ago is refreshed in the background whenever the Plugins screen is opened, so the newest release is offered rather than an intermediate one.
* New: a Check for updates bulk action on the Plugins screen, which checks every selected extension in one go, and the row that says Updating no longer shrinks on phones.

= 1.1.1 =
* Changed: on the settings tab the help icon now sits directly after each label, and its tooltip opens to the right at full width instead of being cut into a narrow strip to the left. The same placement is used across every extension in this family.

= 1.1.0 =
* New: an email to the vendor when they are marked as verified, telling them the badge now shows on their profile (and listings, in sync mode) and, when their verification has a date, when it is due for review. Editable under HivePress, Emails, and can be switched off under HivePress, Settings, Verification Expiry. Not sent for the bulk action or when only the period changes.

= 1.0.3 =
* Fixed: switching "Badges to Expire" to "Vendor and listing badges" on the very first save of the settings tab did not verify the listings of vendors who were already verified. WordPress adds an option the first time it is saved rather than updating it, and the plugin was only listening for updates.

= 1.0.2 =
* New: a "Badges to Expire" setting. HivePress keeps separate verified badges for vendor profiles and for listings; choose "Vendor and listing badges" to make every listing badge follow its vendor's, so verifying, expiry and unticking all apply to the listings too, and a new listing from a verified vendor is verified straight away.
* Fixed: the 1.0.1 correction did not take effect on a real site, because WordPress fires the vendor-specific save hook before the general one. The date is now filled in at the end of the general save, after every field on the screen has been written.
* Changed: the two emails now talk about the vendor's verified status rather than a badge on their profile, since the badge may be on their listings as well.

= 1.0.1 =
* Fixed: choosing a Verification Period on the vendor edit screen and pressing Update saved the period but left Verified Until empty, so the badge would never have expired. The date is now filled in after every field on the screen has been saved.

= 1.0.0 =
* Initial release.
* Adds a Verification Period and a Verified Until date to the vendor edit screen, shown while the Verified box is ticked.
* Adds a Verification Expiry tab under HivePress settings with a site-wide default period, a reminder lead time and an expiry email switch.
* Removes the verified status on the day after the date passes and emails the vendor; sends a reminder a set number of days beforehand.
* Adds a Verification column and two bulk actions to the Vendors screen.

== Upgrade Notice ==

= 2.1.0 =
Vendors can renew during the reminder window and keep their badge while the new documents are reviewed. Also fixes documents sent again after an expiry never being deleted.

= 2.0.0 =
Adds verification requests, private documents, a review queue, paid verification and five automated check providers, two of them free. Nothing changes for existing verified Vendors; open Settings, Verification Expiry once to check the new sections.
