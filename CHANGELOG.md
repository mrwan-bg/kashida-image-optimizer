# Changelog

All notable changes to Kashida Image Optimizer are documented in this file.

## [5.1.35] - 2026-10-12

### Added
- Added Arabic translations for additional administration interface strings, batch-processing notices, and Help content.
- Added the default translation template `languages/kashida-image-optimizer.pot` alongside the Arabic `.po` and compiled `.mo` files.
- Expanded Help guidance covering recommended workflows, server compatibility, interrupted batches, resuming with **Continue**, HTTP 403 errors, and safe troubleshooting.

### Fixed
- Added translator context for formatted strings containing placeholders to address the reported WordPress Plugin Check internationalization error.
- Updated version references and release documentation to 5.1.35.

### Compatibility and data safety
- Preserved original JPG, JPEG, and PNG files during optimization.
- Kept batch checkpoint/resume behavior and server-block diagnostics.
- Optional uninstall cleanup removes generated WebP/AVIF derivatives only when enabled; original image files are preserved.

## [5.1.34] - 2026-10-12

### Added
- Added a highlighted notice explaining that hosting limits, firewalls, security rules, or large libraries may interrupt batch processing.
- Expanded Help content with recovery steps, server compatibility notes, and uninstall-cleanup guidance.
- Added Arabic translation catalog files and translated major admin controls and Help content.

### Improved
- Improved guidance for resuming interrupted batches in the same browser and website.
- Improved internationalized output escaping for admin strings.

## [5.1.33] - 2026-10-12

### Fixed
- Fixed PHP interpolation of the JavaScript resume-button variable that could break batch controls.
- Corrected the version label shown in the plugin dashboard.
- Retained the existing Help tab and batch workflow.

## [5.1.30] - 2026-10-12

### Improved
- Added adaptive delays for transient batch request errors.
- Added clearer diagnostics for HTTP 403 responses instead of repeatedly retrying blocked requests.
- Preserved the unfinished batch offset in the current admin page so users can resume from the last unfinished image.

## [5.1.28] - 2026-10-12

### Improved
- Added automatic retries for temporary connection failures.
- Preserved batch progress while retrying transient request failures.
- Added request timeouts to prevent indefinite loading.

## [5.1.27] - 2026-10-11

### Fixed
- Fixed JavaScript localization on the WordPress Media Library screen.
- Fixed the **Re-optimize** and **Delete optimized** actions in the Media Library list view.
- Improved compatibility of media optimization actions with the WordPress admin interface.

## Previous Releases

See the [GitHub Releases](https://github.com/mrwan-bg/kashida-image-optimizer/releases) page for earlier release notes.
