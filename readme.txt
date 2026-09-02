=== Verification Expiry for HivePress ===
Contributors: chrisb
Tags: hivepress, vendors, verified, verification, expiry
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Give a vendor's verified status an expiry date, per vendor or site-wide, so vendors must keep their profile up to date to stay verified.

== Description ==

HivePress lets you mark a vendor as verified, and they then stay verified until you untick the box. This plugin gives that verified status an expiry date. When the date passes, the badge is removed and the vendor is emailed to check that their profile, listings and contact details are still up to date and to get in touch to be verified again.

The aim is to stop vendors setting up a profile, getting verified and then forgetting about it. A verified badge that has to be renewed is a badge that says the details behind it were checked recently.

Features:

* A Verification Period and a Verified Until date on every vendor's edit screen, shown as soon as the Verified box is ticked. Choose 1 month, 3 months, 6 months or 1 year, pick a date of your own, or say that this vendor's verification does not expire.
* A site-wide default period under HivePress, Settings, Verification Expiry, so you do not have to choose for every vendor. Each vendor can still have their own.
* The date is filled in automatically each time a vendor is verified, so re-verifying someone after their badge has expired starts the clock again with one tick of the box.
* An email when a vendor is marked as verified, telling them the badge now shows and, if their verification has a date, when it is due for review.
* A reminder email a set number of days before the date, so the vendor has time to update their details. Set the number of days, or set 0 for no reminder.
* An email when the badge is removed. All three emails can be reworded under HivePress, Emails, like any other HivePress email, and the verified and expiry emails can each be switched off.
* A choice of which badges the expiry applies to. HivePress keeps separate verified badges for vendor profiles and for listings; by default only the vendor badge is managed, or you can have every listing badge follow its vendor's, so verifying, expiry and unticking all apply to the listings too.
* A Verification column on the Vendors screen showing who is verified, until when, and whose verification has expired.
* Two bulk actions on the Vendors screen: apply a period to vendors you verified before installing this plugin, or remove the expiry date from vendors who should stay verified.
* Nothing changes for vendors you do not give a date to. A vendor with no date stays verified exactly as before.
* Your settings and every vendor's dates are kept if you delete the plugin, unless you tick the box that asks for them to be removed. Deleting the plugin never removes anyone's verified status.

The checks run hourly through HivePress's own scheduler, so nothing needs setting up.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/verification-expiry-for-hivepress` directory, or install the plugin zip through the WordPress admin.
2. Activate the plugin through the Plugins screen. HivePress must be installed and active.
3. Go to HivePress, Settings, Verification Expiry, and choose a default period and how many days before expiry to send the reminder.
4. To put a date on vendors you had already verified, go to Vendors, select them, and choose the "Apply verification period" bulk action.

Once installed, the plugin checks for new versions automatically and updates through the normal WordPress Plugins screen, just like a plugin from the WordPress.org directory.

== Frequently Asked Questions ==

= What happens when the date passes? =

On the first hourly check of the following day the Verified box is unticked for that vendor, exactly as if you had unticked it yourself, so the badge disappears from their profile and their listings straight away. If the expiry email is switched on, the vendor is emailed. Nothing else about the vendor or their listings changes.

= How do I verify a vendor again? =

Open the vendor, tick Verified and save. The Verified Until date is filled in again from the vendor's period, or from the site default, so the clock starts afresh from today.

= I had verified vendors before installing this plugin. Do they expire? =

Not until you give them a date. Go to Vendors, select them, and choose the "Apply verification period" bulk action. Each vendor gets a date counted from today, using their own period if one is set and the site default otherwise. Vendors that are not verified are skipped.

= I changed the default period. Why did the dates not change? =

The default is used when a vendor is verified, not applied to dates already set. To move existing dates to the new default, select those vendors on the Vendors screen and use the "Apply verification period" bulk action.

= Can I set a different period for one vendor? =

Yes. The Verification Period on the vendor's edit screen overrides the site default for that vendor, and "Does not expire" keeps them verified whatever the default says. You can also type or pick any date in Verified Until.

= Does this affect the verified badge on listings, or only on the vendor profile? =

HivePress has two separate verified badges: one on the vendor profile, set by the Verified box on the vendor, and one on each listing, set by the Verified box on that listing. By default this plugin manages only the vendor badge and leaves listing badges exactly as HivePress does. Under HivePress, Settings, Verification Expiry, set "Badges to Expire" to "Vendor and listing badges" to make every listing badge follow its vendor's: verifying a vendor verifies all their listings, expiry or unticking removes all of them, and a new listing from a verified vendor is verified straight away. Choosing that option also verifies the listings of every vendor who is verified at the time.

= Can I change the wording of the emails? =

Yes. All three emails appear under HivePress, Emails, where you can edit the subject and body like any other HivePress email. The tokens available are %user_name%, %vendor_name%, %vendor_url% and %expiry_date%. The verified email also has %badges%, which reads "your profile" or "your profile and listings" depending on the Badges to Expire setting, and %expiry_note%, a ready-made sentence about the review date that is left out when the verification has no date.

= Does deleting the plugin remove my settings or unverify anyone? =

Deleting the plugin never removes anyone's verified status. Your settings and every vendor's dates are kept by default, even though the WordPress delete screen warns that data will be removed, so a reinstall picks up where you left off. If you want everything gone, tick "Delete all data when this plugin is deleted" on the settings tab before deleting.

== Changelog ==

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
