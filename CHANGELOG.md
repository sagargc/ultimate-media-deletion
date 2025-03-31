
---

### **CHANGELOG.md**

```markdown
# Changelog

All notable changes to this project will be documented in this file.

## [2.1.1] - 2023-11-15

### Added
- Orphaned metadata auto-cleanup system
- Revision management with parent post deletion
- WP-CLI integration for bulk operations
- Enhanced media reference scanner (now checks 5+ sources)

### Improved
- Daily cleanup routine efficiency (+50% faster)
- Multisite logging interface
- Memory usage optimization

### Fixed
- PHP 8.2 compatibility issues
- Log display problems on multisite
- ACF flexible content field handling

## [2.1.0] - 2023-09-20

### Added
- Comprehensive deletion logging system
- Scheduled daily maintenance tasks
- Native WebP/AVIF image support
- Bulk deletion interface

### Improved
- Admin UI for media tracking
- Database query optimization
- Error handling for large sites

### Fixed
- Attachment counting in media library
- False positives in content scanning
- Cron scheduling on some hosts

## [2.0.0] - 2023-06-10
- Initial stable release
- Core media deletion functionality
- Basic ACF field support
- Minimal logging system

---

> **Versioning**: This project uses [Semantic Versioning](https://semver.org/).