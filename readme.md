# MD Control Receiver

Remote site status, user management, and signed login links for MD Control.
Backup creation, status, and download endpoints have been removed in 1.2.7.
Existing backup files and other backup plugins are left untouched.

## Releases

Update the plugin Version header, merge the change, then push a matching `v1.2.7`
(or later) tag. The release workflow validates PHP and builds
`md-control-receiver.zip` containing the fixed `md-control-receiver/` folder.
Review and publish the draft release only after the asset is attached.
The updater follows the latest published release in
`Evan-Matthews-502/md-control-receiver` and requires that asset; it never falls
back to GitHub's generated source ZIPs.

## Existing installations: first update

Version 1.2.6 points at a different GitHub owner and has an unreliable folder
repair hook. The replacement code cannot fix an update already running under
that old code. Deploy 1.2.7 into each existing plugin directory in place using
SSH/SFTP or your deployment tool, retaining the exact current directory and
`md-control-receiver.php` filename. Back up the existing plugin first and verify
its activation and API connection afterward. Do not install a second active copy
or rename an active plugin folder blindly.

For installations already on 1.2.7 in a legacy folder, use the individual Update Now
action or automatic update for the first upgrade to 1.2.8. The 1.2.7 bulk-update hook
requires fields WordPress does not supply during bulk updates; the new code cannot
repair that hook while the old version is running. Receiver 1.2.8 fixes this for
subsequent bulk updates.

After this one-time transition, supported updates preserve each installation's existing
folder (including `md-control-receiver-main` and `md-control-receiver-1.2.6`).
Signed login links now reject missing API keys and malformed parameters; login
redirects use WordPress safe redirects. REST authentication rejects missing headers
without a PHP error.

Fresh installations should use the attached release asset, which installs as
`md-control-receiver`. Folder normalization on existing sites is a separate
migration; it is not performed by this update. The existing `md_control_api_key`
option is retained.

## Validation

Run `php tests/updater.php`, `php tests/auth.php`, `php tests/admin-access.php`, `php tests/rotate-key.php`, and lint all PHP files before releasing. These are
isolated regression checks; also verify a real WordPress update on staging,
including cron and bulk updates, before deploying across sites.

## Admin visibility

The Plugins table entry and MD Control settings are visible only to logged-in
users with `manage_options` whose account email matches `evan@matthewsdesign.co`
or `webdev@matthewsdesign.co` (case-insensitive). This checks the user's email,
not the site's Administration Email Address. Other users cannot open the settings
page directly or regenerate its key. Activation redirects follow the same rule.
Background updates and API authentication are unaffected.

This controls the active plugin's admin interface, not access to server files,
WP-CLI, or other administrative tools. An inactive plugin cannot hide itself.
Run `php tests/admin-access.php` to validate visibility and settings restrictions.

## Key rotation (1.2.8)

`POST /wp-json/md-control/v1/rotate-key` replaces the stored `md_control_api_key` with the
`new_key` parameter. It is authenticated with the current key, accepts only 64 lowercase
hexadecimal characters, and returns `rotated`, or `unchanged` when the key is already set.
MD Control generates the key and confirms it with a ping before it stops using the old one. The receiver rechecks the database key and updates it atomically, rejecting stale or competing requests. Rotation must use HTTPS. Hub retains an unconfirmed candidate for recovery; it never silently discards a key that may have reached WordPress.
The ping response now includes `plugin_version`; the update-status endpoint and update
counts were removed because MD Control no longer uses them.
