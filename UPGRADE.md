# Upgrade Guide

This guide covers upgrades for the PeakRack Upstream WHMCS Integration.

## Before Upgrading

1. Back up both WHMCS file trees.
2. Back up both WHMCS databases.
3. Record the currently installed module versions.
4. Keep a protected record of the upstream API key bindings. Do not place API secrets in the backup notes.
5. Review [CHANGELOG.md](CHANGELOG.md).
6. Stop the upstream worker and downstream synchronization Cron jobs during the file replacement.

## Upgrade Steps

1. Download the intended release from:

   https://github.com/Techshrr/whmcs_peakrack_upstream

2. Replace only these module directories with the matching release packages:

   - Upstream: `/modules/addons/peakrack_upstream_api/`
   - Downstream: `/modules/servers/peakrackupstream/`

3. Log in to the upstream WHMCS admin area and open the Addon Module.
4. Confirm the Addon settings, API keys, product policies, system-health results, and worker status.
5. In downstream WHMCS, confirm the server credentials and product configuration options.
6. Restore both Cron jobs.
7. Run a downstream connection test and inspect the next synchronization result before normal traffic resumes.

## Database Changes

Version `1.0.0` is the initial release. Activating the upstream Addon creates its Addon-owned tables. Deactivation preserves those tables and their data.

Future releases that require schema changes will document them in this file and in [CHANGELOG.md](CHANGELOG.md).

## Rollback

1. Stop both Cron jobs.
2. Restore the previous module directories.
3. Restore the database backup if the attempted upgrade changed Addon-owned tables or data.
4. Clear the WHMCS template cache if Client Area output does not match the restored files.
5. Review the Addon operation list and WHMCS activity log before restoring traffic.

Do not rotate or replace production API secrets merely to perform a file rollback unless the secret was exposed.
