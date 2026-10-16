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

After this one-time transition, updates preserve each installation's existing
folder (including `md-control-receiver-main` and `md-control-receiver-1.2.6`).
Signed login links now reject missing API keys and malformed parameters; login
redirects use WordPress safe redirects. REST authentication rejects missing headers
without a PHP error.

Fresh installations should use the attached release asset, which installs as
`md-control-receiver`. Folder normalization on existing sites is a separate
migration; it is not performed by this update. The existing `md_control_api_key`
option is retained.

## Validation

Run `php tests/updater.php`, `php tests/auth.php`, and lint all PHP files before releasing. These are
isolated regression checks; also verify a real WordPress update on staging,
including cron and bulk updates, before deploying across sites.
