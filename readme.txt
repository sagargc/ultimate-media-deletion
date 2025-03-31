=== Ultimate Media Deletion ===
Contributors: Sagar GC
Donate link: https://sagargc.com.np
Tags: media, deletion, cleanup, acf, attachments, logging, maintenance
Requires at least: 5.6
Tested up to: 6.7.2
Stable tag: 2.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

**Important**: Always backup your database and media files before using this plugin.

== Description ==

Building on 2.1.0's foundation, version 2.1.1 adds:

**Enhanced Cleanup Systems**
  - Automatic orphaned metadata removal
  - Revision cleanup with parent post deletion
  - Optimized daily maintenance tasks

**Safety Improvements**
  - 3-level media usage verification
  - Skip media referenced in ACF/other posts
  - Configurable cleanup thresholds

**Existing 2.1.0 Features**
  - Detailed deletion logging
  - Scheduled daily cleanup
  - WebP/AVIF support
  - Bulk operations

== Installation ==

**Backup your site before proceeding**

1. Upload plugin files to `/wp-content/plugins/ultimate-media-deletion`
2. Activate through WordPress admin
3. View logs under Tools → Media Deletion Logs

== Frequently Asked Questions ==

== What's different from 2.1.0? ==
New in 2.1.1:
- Smart orphaned data detection
- Revision management
- Enhanced reference checking
- Better WP-CLI integration

== Are 2.1.0's logging features still available? ==
Yes! All existing features remain:
- Deletion audit logs
- Daily automated cleanups
- Bulk action support

== How do I access the logs? ==
1. Go to Tools → Media Deletion Logs
2. Filter by date/post type
3. Export as CSV if needed

== Is this safe for production sites? ==
**Always test in staging first**
Maintain current backups
Start with small batches

== Screenshots ==

1. Media deletion log interface
2. Daily cleanup status report
3. Orphan detection results
4. WP-CLI usage example

== Changelog ==

= 2.1.1 =
* NEW: Orphaned metadata cleanup
* NEW: Revision management
* IMPROVED: Media reference checks (now covers 5+ sources)
* OPTIMIZED: Daily cleanup routine (50% faster)
* FIXED: Log display issues on multisite

= 2.1.0 =
* ADDED: Comprehensive deletion logging
* ADDED: Daily maintenance scheduler
* ADDED: WebP/AVIF support
* IMPROVED: Bulk deletion interface

== Upgrade Notice ==

🛑 **Before upgrading**:
1. Backup your database
2. Backup wp-content/uploads
3. Test in staging environment

This update:
- Enhances data integrity checks
- Reduces database bloat
- Maintains all existing logging features
No configuration changes required.