# Changelog

All notable changes to Kashida Image Optimizer are documented in this file.

## [5.1.35] - 2026-10-12

### Added
- Added Arabic localization for additional administration interface strings, batch notices, and Help content.
- Added the default translation template `kashida-image-optimizer.pot` alongside the Arabic `.po` and `.mo` catalogs.
- Added clearer guidance for interrupted batch processing, Continue/resume behavior, server-side HTTP 403 blocks, and safe troubleshooting.

### Fixed
- Added translator context for formatted strings containing placeholders to improve compatibility with translation-checking tools.
- Updated release documentation and version references to 5.1.35.

### Preserved
- Original JPG, JPEG, and PNG files remain unchanged during optimization.
- Existing resumable batch processing and server-block diagnostics remain available.
- Optional uninstall cleanup removes generated WebP/AVIF derivatives only when enabled; originals are preserved.

## [5.1.27] - 2026-10-11

### Fixed
- Fixed JavaScript localization on the WordPress Media Library screen.
- Fixed the Re-optimize and Delete optimized actions in the Media Library list view.
- Improved compatibility of media optimization actions with the WordPress admin interface.

### Changed
- Updated plugin version metadata.

## Previous Releases

See the [GitHub Releases](https://github.com/mrwan-bg/kashida-image-optimizer/releases) page for earlier release notes.
