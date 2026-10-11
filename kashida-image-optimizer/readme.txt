=== Kashida Image Optimizer ===
Contributors: mrwanbg
Tags: image optimization, webp, avif, images, performance
Requires at least: 5.5
Requires PHP: 8.0
Tested up to: 7.1
Stable tag: 5.1.27
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lightweight WordPress image optimization with WebP and AVIF, while preserving original JPG, JPEG and PNG files.

== Description ==

Kashida Image Optimizer creates optimized WebP and AVIF versions of supported images while keeping the original files unchanged.

Key features:

* Automatic optimization for new uploads.
* WebP and AVIF output with independent quality settings.
* Smart PNG handling for transparent artwork.
* Smart Content Detection for photos and graphics.
* Protection for very large images during automatic processing.
* Batch conversion and regeneration for existing media.
* Media Library actions for individual images.
* Statistics and orphan-file cleanup tools.
* Optimized delivery without modifying the original image files.
* Internationalization support through WordPress gettext and WordPress language packs.

== Installation ==

1. Upload the `kashida-image-optimizer` folder to the `/wp-content/plugins/` directory, or install the ZIP from Plugins > Add New > Upload Plugin.
2. Activate the plugin through the Plugins screen in WordPress.
3. Open Kashida Image Optimizer from the WordPress admin menu.
4. Configure the quality and optimization settings and save.

== Frequently Asked Questions ==

= Are original images modified? =

No. Original JPG, JPEG and PNG files are preserved.

= Does the plugin optimize large images automatically? =

Images over 10 MB or wider/taller than 6000 pixels are skipped during automatic optimization. They can still be processed manually from the Media Library.

= Can I use AVIF without it being enabled? =

AVIF generation can be enabled when the server supports AVIF encoding. WebP remains available independently.

= Does it work with translation plugins? =

Yes. The plugin uses the `kashida-image-optimizer` text domain and standard WordPress gettext functions. Translations can be provided through WordPress.org language packs.

== Privacy ==

Kashida Image Optimizer does not send images or personal data to an external service. Image optimization is performed locally on the WordPress server.

== Changelog ==

= 5.1.27 =
* Fixed Media Library Re-optimize and Delete optimized actions by defining their JavaScript translation strings on the Media Library screen.


= 5.1.26 =
* Improved unique prefixes for plugin functions, constants, settings, and AJAX actions.
* Updated contributor metadata and removed bundled translation files for WordPress.org language-pack delivery.
* Added migration for existing settings saved under the legacy option key.

= 5.1.24 =
* Improved WordPress Plugin Check compatibility.
* Improved output escaping for translated admin markup.
* Replaced direct writability checks with the WordPress filesystem helper.
* Improved internationalization comments and translatable help strings.
* Removed unnecessary manual text-domain loading for WordPress.org language packs.
* Updated plugin version metadata to 5.1.24.

= 5.1.23 =
* WordPress.org submission preparation and compliance updates.

= 5.1.22 =
* WordPress.org compliance improvements.
* Added GPL-2.0-or-later licensing metadata.
* Added production readme documentation.
* Improved gettext compatibility by making translatable strings statically discoverable.
* Added POT, PO and MO translation files, including Arabic.
* Deferred local text-domain loading to the `init` hook.
* Maintained existing optimization, delivery and admin functionality.

= 5.1.21 =
* Added bundled translation resources and Arabic localization support.

= 5.1.20 =
* Added translation support for the plugin interface.
