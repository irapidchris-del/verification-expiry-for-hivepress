=== Verification Expiry for HivePress ===
Contributors: chrisb
Tags: hivepress, vendors, verified, verification, expiry
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.1
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
* A reminder email a set number of days before the date, so the vendor has time to update their details. Set the number of days, or set 0 for no reminder.
* An email when the badge is removed. Both emails can be reworded under HivePress, Emails, like any other HivePress email.
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

= Can I change the wording of the emails? =

Yes. Both emails appear under HivePress, Emails, where you can edit the subject and body like any other HivePress email. The tokens available are %user_name%, %vendor_name%, %vendor_url% and %expiry_date%.

= Does deleting the plugin remove my settings or unverify anyone? =

Deleting the plugin never removes anyone's verified status. Your settings and every vendor's dates are kept by default, even though the WordPress delete screen warns that data will be removed, so a reinstall picks up where you left off. If you want everything gone, tick "Delete all data when this plugin is deleted" on the settings tab before deleting.

== Changelog ==

= 1.0.1 =
* Fixed: choosing a Verification Period on the vendor edit screen and pressing Update saved the period but left Verified Until empty, so the badge would never have expired. The date is now filled in after every field on the screen has been saved.

= 1.0.0 =
* Initial release.
* Adds a Verification Period and a Verified Until date to the vendor edit screen, shown while the Verified box is ticked.
* Adds a Verification Expiry tab under HivePress settings with a site-wide default period, a reminder lead time and an expiry email switch.
* Removes the verified status on the day after the date passes and emails the vendor; sends a reminder a set number of days beforehand.
* Adds a Verification column and two bulk actions to the Vendors screen.
