<?php
/**
 * Plugin Name:       Kashida Image Optimizer
 * Description:       Convert WordPress images to WebP and AVIF while preserving originals, with automatic conversion, batch processing, statistics, and cleanup.
 * Version:           5.1.28
 * Author:             Kashida Media Services
 * Author URI:         https://kashida.ly
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least:  5.5
 * Requires PHP:       8.0
 * Text Domain:        kashida-image-optimizer
 * Domain Path:        /languages
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Register AVIF as a WordPress-supported image MIME type.
 * Static AVIF delivery still depends on the web server MIME map.
 */
add_filter( 'upload_mimes', function ( $mimes ) {
    $mimes['avif'] = 'image/avif';
    return $mimes;
} );

add_filter( 'wp_check_filetype_and_ext', function ( $data, $file, $filename, $mimes ) {
    if ( 'avif' === strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) ) ) {
        $data['ext']             = 'avif';
        $data['type']            = 'image/avif';
        $data['proper_filename'] = $filename;
    }
    return $data;
}, 10, 4 );

define( 'KASHIMOP_VERSION', '5.1.28' );
define( 'KASHIMOP_OPTION', 'kashimop_settings' );
define( 'KASHIMOP_BATCH_SIZE', 1 ); // Process one attachment per AJAX request for reliable conversion and progress on large images.
define( 'KASHIMOP_BATCH_CACHE_KEY', 'kashimop_batch_ids_cache' );
define( 'KASHIMOP_BATCH_CACHE_TTL', 10 * MINUTE_IN_SECONDS );
// Automatic optimization is skipped for unusually large source images.
// The original file is never modified; manual optimization remains available.
define( 'KASHIMOP_LARGE_IMAGE_MAX_BYTES', 10 * 1024 * 1024 ); // 10 MiB (~10 MB)
define( 'KASHIMOP_LARGE_IMAGE_MAX_DIMENSION', 6000 ); // px

/* ============================================================
 * SETTINGS
 * ============================================================ */

function kashimop_get_settings() {
    static $settings = null;
    if ( null !== $settings ) return $settings;

    $defaults = array(
        'webp_quality' => 85,
        'avif_quality' => 70,
        'auto_convert' => 1,
        'enable_avif'  => 0,
        'png_webp_mode' => 'smart',
        'smart_content_detection' => 1,
        'smart_photo_webp_quality' => 85,
        'smart_graphics_webp_quality' => 95,
        'smart_photo_avif_quality' => 70,
        'smart_graphics_avif_quality' => 80,
    );
    $saved = get_option( KASHIMOP_OPTION, false );
    if ( false === $saved ) {
        // Migrate settings saved by versions that used the shorter legacy option key.
        $legacy_settings = get_option( 'kio_settings', array() );
        if ( is_array( $legacy_settings ) && ! empty( $legacy_settings ) ) {
            add_option( KASHIMOP_OPTION, $legacy_settings );
        }
        $saved = $legacy_settings;
    }
    if ( ! is_array( $saved ) ) $saved = array();
    $settings = wp_parse_args( $saved, $defaults );
    return $settings;
}

function kashimop_sanitize_settings( $input ) {
    return array(
        'webp_quality' => isset( $input['webp_quality'] ) ? max( 1, min( 100, (int) $input['webp_quality'] ) ) : 85,
        'avif_quality' => isset( $input['avif_quality'] ) ? max( 1, min( 100, (int) $input['avif_quality'] ) ) : 70,
        'auto_convert' => isset( $input['auto_convert'] ) ? 1 : 0,
        'enable_avif'  => isset( $input['enable_avif'] ) ? 1 : 0,
        'png_webp_mode' => isset( $input['png_webp_mode'] ) && in_array( $input['png_webp_mode'], array( 'smart', 'quality', 'lossless' ), true ) ? $input['png_webp_mode'] : 'smart',
        'smart_content_detection' => isset( $input['smart_content_detection'] ) ? 1 : 0,
        'smart_photo_webp_quality' => isset( $input['smart_photo_webp_quality'] ) ? max( 1, min( 100, (int) $input['smart_photo_webp_quality'] ) ) : 85,
        'smart_graphics_webp_quality' => isset( $input['smart_graphics_webp_quality'] ) ? max( 1, min( 100, (int) $input['smart_graphics_webp_quality'] ) ) : 95,
        'smart_photo_avif_quality' => isset( $input['smart_photo_avif_quality'] ) ? max( 1, min( 100, (int) $input['smart_photo_avif_quality'] ) ) : 70,
        'smart_graphics_avif_quality' => isset( $input['smart_graphics_avif_quality'] ) ? max( 1, min( 100, (int) $input['smart_graphics_avif_quality'] ) ) : 80,
    );
}

function kashimop_avif_supported() {
    static $supported = null;
    if ( null !== $supported ) return $supported;

    if ( extension_loaded( 'imagick' ) ) {
        try {
            if ( in_array( 'AVIF', Imagick::queryFormats(), true ) ) return $supported = true;
        } catch ( Throwable $e ) {
            kashimop_log_error( 'Imagick AVIF query failed: ' . $e->getMessage() );
        }
    }
    return $supported = kashimop_gd_supports( 'avif' );
}

/**
 * Checks whether the GD extension supports encoding a given format.
 *
 * @param string $format "webp" or "avif".
 * @return bool
 */
function kashimop_gd_supports( $format ) {
    $function = 'image' . $format;
    return function_exists( $function );
}

function kashimop_log_error( $message ) {
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log( '[Kashida Image Optimizer] ' . $message ); // phpcs:ignore
    }
}

/* ============================================================
 * AVIF MIME STATUS / APACHE-LITESPEED HELPER
 * ============================================================ */

function kashimop_server_supports_htaccess_mime() {
    if ( ! function_exists( 'insert_with_markers' ) ) return false;
    $software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) ) : '';
    return ( false !== strpos( $software, 'apache' ) || false !== strpos( $software, 'litespeed' ) );
}

function kashimop_avif_mime_status() {
    $upload_dir = wp_upload_dir();
    $htaccess   = trailingslashit( $upload_dir['basedir'] ) . '.htaccess';
    $contents   = is_readable( $htaccess ) ? (string) file_get_contents( $htaccess ) : '';
    $has_rule   = false !== strpos( $contents, 'AddType image/avif .avif' );

    return array(
        'htaccess' => $htaccess,
        'has_rule' => $has_rule,
        'can_manage' => kashimop_server_supports_htaccess_mime() && ( file_exists( $htaccess ) ? wp_is_writable( $htaccess ) : wp_is_writable( $upload_dir['basedir'] ) ),
        'software' => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '',
    );
}

function kashimop_ensure_avif_mime_rule() {
    if ( ! kashimop_server_supports_htaccess_mime() || ! function_exists( 'insert_with_markers' ) ) return false;
    $status = kashimop_avif_mime_status();
    if ( ! empty( $status['has_rule'] ) ) return true;
    $upload_dir = wp_upload_dir();
    if ( ! empty( $upload_dir['error'] ) || empty( $upload_dir['basedir'] ) ) return false;
    $htaccess = trailingslashit( $upload_dir['basedir'] ) . '.htaccess';
    if ( file_exists( $htaccess ) && ! wp_is_writable( $htaccess ) ) return false;
    if ( ! file_exists( $htaccess ) && ! wp_is_writable( $upload_dir['basedir'] ) ) return false;

    $rules = array( 'AddType image/avif .avif' );
    return (bool) insert_with_markers( $htaccess, 'Kashida Image Optimizer', $rules );
}

add_action( 'admin_init', function () {
    // Add the MIME rule only when the uploads directory is on Apache/LiteSpeed
    // and the .htaccess is writable. Never attempt to modify Nginx/server config.
    if ( current_user_can( 'manage_options' ) ) {
        kashimop_ensure_avif_mime_rule();
    }
} );

/* ============================================================
 * ADMIN MENU & SETTINGS PAGE
 * ============================================================ */

add_action( 'admin_menu', function () {
    $icon = 'data:image/svg+xml;base64,' . base64_encode(
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">' .
        '<rect x="4" y="4" width="16" height="16" rx="2.2" fill="none" stroke="#fff" stroke-width="1.7"/>' .
        '<circle cx="9" cy="9" r="1.5" fill="#fff"/>' .
        '<path fill="#fff" d="M5.8 17.8 10.2 13.4l2.5 2.5 2.1-2.1 3.4 4H5.8Z"/>' .
        '<path fill="none" stroke="#fff" stroke-width="1.55" stroke-linecap="round" stroke-linejoin="round" d="M7.2 2.8H4.8c-1.1 0-2 .9-2 2v2.4M16.8 2.8h2.4c1.1 0 2 .9 2 2v2.4M7.2 21.2H4.8c-1.1 0-2-.9-2-2v-2.4M16.8 21.2h2.4c1.1 0 2-.9 2-2v-2.4"/>' .
        '<path fill="none" stroke="#fff" stroke-width="1.55" stroke-linecap="round" stroke-linejoin="round" d="M7.2 4.2 5.5 2.8 4.2 4.5M16.8 4.2l1.7-1.4 1.3 1.7M7.2 19.8l-1.7 1.4-1.3-1.7M16.8 19.8l1.7 1.4 1.3-1.7"/>' .
        '</svg>'
    );
    add_menu_page(
        __( 'Kashida Image Optimizer', 'kashida-image-optimizer' ),
        __( 'Kashida Image Optimizer', 'kashida-image-optimizer' ),
        'manage_options',
        'kashida-image-optimizer',
        'kashimop_render_page',
        $icon,
        58
    );
} );

add_action( 'admin_init', function () {
    register_setting( 'kashimop_settings_group', KASHIMOP_OPTION, array( 'sanitize_callback' => 'kashimop_sanitize_settings' ) );
} );

function kashimop_get_admin_allowed_html() {
    $allowed = wp_kses_allowed_html( 'post' );
    $custom  = array(
        'form'     => array( 'action' => true, 'method' => true, 'id' => true, 'class' => true ),
        'input'    => array( 'type' => true, 'name' => true, 'value' => true, 'id' => true, 'class' => true, 'checked' => true, 'disabled' => true, 'min' => true, 'max' => true, 'step' => true, 'required' => true ),
        'select'   => array( 'name' => true, 'id' => true, 'class' => true, 'disabled' => true ),
        'option'   => array( 'value' => true, 'selected' => true, 'disabled' => true ),
        'button'   => array( 'type' => true, 'id' => true, 'class' => true, 'style' => true, 'disabled' => true, 'data-*' => true, 'aria-*' => true ),
        'div'      => array( 'id' => true, 'class' => true, 'style' => true, 'data-*' => true, 'aria-*' => true ),
        'span'     => array( 'id' => true, 'class' => true, 'style' => true, 'data-*' => true, 'aria-*' => true ),
        'label'    => array( 'for' => true, 'class' => true ),
        'img'      => array( 'src' => true, 'alt' => true, 'class' => true, 'width' => true, 'height' => true ),
        'a'        => array( 'href' => true, 'target' => true, 'rel' => true, 'class' => true ),
        'table'    => array( 'id' => true, 'class' => true, 'style' => true ),
        'th'       => array( 'id' => true, 'class' => true, 'style' => true, 'scope' => true ),
        'td'       => array( 'id' => true, 'class' => true, 'style' => true ),
        'ul'       => array( 'id' => true, 'class' => true, 'style' => true ),
        'ol'       => array( 'id' => true, 'class' => true, 'style' => true ),
        'li'       => array( 'id' => true, 'class' => true, 'style' => true ),
        'section'  => array( 'id' => true, 'class' => true, 'style' => true ),
        'nav'      => array( 'id' => true, 'class' => true, 'aria-label' => true ),
        'h2'       => array( 'id' => true, 'class' => true ),
        'h3'       => array( 'id' => true, 'class' => true ),
        'p'        => array( 'id' => true, 'class' => true, 'style' => true ),
        'strong'   => array( 'class' => true ),
        'em'       => array( 'class' => true ),
    );

    foreach ( $custom as $tag => $attrs ) {
        $allowed[ $tag ] = isset( $allowed[ $tag ] ) ? array_merge( $allowed[ $tag ], $attrs ) : $attrs;
    }

    return $allowed;
}

function kashimop_render_page() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    $kashimop_translation_map = array(
        'Kashida Image Optimizer' => __( 'Kashida Image Optimizer', 'kashida-image-optimizer' ),
        'Lightweight image optimization with WebP & AVIF.' => __( 'Lightweight image optimization with WebP & AVIF.', 'kashida-image-optimizer' ),
        'Settings' => __( 'Settings', 'kashida-image-optimizer' ),
        'Help' => __( 'Help', 'kashida-image-optimizer' ),
        'WebP Quality' => __( 'WebP Quality', 'kashida-image-optimizer' ),
        'Higher quality preserves more detail but creates larger files. Recommended: 80-90. Default: 85.' => __( 'Higher quality preserves more detail but creates larger files. Recommended: 80-90. Default: 85.', 'kashida-image-optimizer' ),
        'Auto-convert new uploads' => __( 'Auto-convert new uploads', 'kashida-image-optimizer' ),
        'Automatically convert images on upload' => __( 'Automatically convert images on upload', 'kashida-image-optimizer' ),
        'Large Image Protection' => __( 'Large Image Protection', 'kashida-image-optimizer' ),
        'Large-image protection: automatic optimization is skipped for images over 10 MB or wider/taller than 6000 px. You can always optimize them manually from Media Library.' => __( 'Large-image protection: automatic optimization is skipped for images over 10 MB or wider/taller than 6000 px. You can always optimize them manually from Media Library.', 'kashida-image-optimizer' ),
        '10 MB or 6000 × 6000 px' => __( '10 MB or 6000 × 6000 px', 'kashida-image-optimizer' ),
        'Images exceeding either limit are not optimized automatically. The original remains untouched, and Optimize now is still available for manual processing.' => __( 'Images exceeding either limit are not optimized automatically. The original remains untouched, and Optimize now is still available for manual processing.', 'kashida-image-optimizer' ),
        'Enable AVIF' => __( 'Enable AVIF', 'kashida-image-optimizer' ),
        'Also generate AVIF versions' => __( 'Also generate AVIF versions', 'kashida-image-optimizer' ),
        'AVIF Quality' => __( 'AVIF Quality', 'kashida-image-optimizer' ),
        'AVIF usually reaches similar visual quality at a lower setting. Recommended: 60-75. Default: 70.' => __( 'AVIF usually reaches similar visual quality at a lower setting. Recommended: 60-75. Default: 70.', 'kashida-image-optimizer' ),
        'PNG → WebP Handling' => __( 'PNG → WebP Handling', 'kashida-image-optimizer' ),
        'Smart — lossless for transparent PNGs' => __( 'Smart — lossless for transparent PNGs', 'kashida-image-optimizer' ),
        'Quality — use WebP quality for all PNGs' => __( 'Quality — use WebP quality for all PNGs', 'kashida-image-optimizer' ),
        'Lossless — lossless WebP for all PNGs' => __( 'Lossless — lossless WebP for all PNGs', 'kashida-image-optimizer' ),
        'Recommended: Smart. Transparent PNGs such as logos, UI graphics and product artwork are encoded losslessly; opaque PNGs use the smart Photo/Graphics quality when Smart Content Detection is enabled.' => __( 'Recommended: Smart. Transparent PNGs such as logos, UI graphics and product artwork are encoded losslessly; opaque PNGs use the smart Photo/Graphics quality when Smart Content Detection is enabled.', 'kashida-image-optimizer' ),
        'Smart Content Detection' => __( 'Smart Content Detection', 'kashida-image-optimizer' ),
        'Automatically distinguish photographic images from graphics' => __( 'Automatically distinguish photographic images from graphics', 'kashida-image-optimizer' ),
        'Uses lightweight image analysis to detect transparent/graphic artwork and low-color illustrations. Photos use the normal quality settings; detected graphics use higher quality to protect text, edges and flat colors. The original file is never modified.' => __( 'Uses lightweight image analysis to detect transparent/graphic artwork and low-color illustrations. Photos use the normal quality settings; detected graphics use higher quality to protect text, edges and flat colors. The original file is never modified.', 'kashida-image-optimizer' ),
        'Smart Quality' => __( 'Smart Quality', 'kashida-image-optimizer' ),
        'Photo WebP' => __( 'Photo WebP', 'kashida-image-optimizer' ),
        'Graphics WebP' => __( 'Graphics WebP', 'kashida-image-optimizer' ),
        'Photo AVIF' => __( 'Photo AVIF', 'kashida-image-optimizer' ),
        'Graphics AVIF' => __( 'Graphics AVIF', 'kashida-image-optimizer' ),
        'Recommended: Photo WebP 85, Graphics WebP 95, Photo AVIF 70, Graphics AVIF 80.' => __( 'Recommended: Photo WebP 85, Graphics WebP 95, Photo AVIF 70, Graphics AVIF 80.', 'kashida-image-optimizer' ),
        'Quality Presets' => __( 'Quality Presets', 'kashida-image-optimizer' ),
        'Smaller Files' => __( 'Smaller Files', 'kashida-image-optimizer' ),
        'Recommended' => __( 'Recommended', 'kashida-image-optimizer' ),
        'High Quality' => __( 'High Quality', 'kashida-image-optimizer' ),
        'Presets only change the two quality values above. They do not modify existing optimized files until you use Re-optimize or Regenerate Optimized Images.' => __( 'Presets only change the two quality values above. They do not modify existing optimized files until you use Re-optimize or Regenerate Optimized Images.', 'kashida-image-optimizer' ),
        'Save Settings' => __( 'Save Settings', 'kashida-image-optimizer' ),
        'Image Tools' => __( 'Image Tools', 'kashida-image-optimizer' ),
        'Process existing images or restore the original files.' => __( 'Process existing images or restore the original files.', 'kashida-image-optimizer' ),
        'Batch Convert Existing Library' => __( 'Batch Convert Existing Library', 'kashida-image-optimizer' ),
        'Start Batch Conversion' => __( 'Start Batch Conversion', 'kashida-image-optimizer' ),
        'Regenerate Optimized Images' => __( 'Regenerate Optimized Images', 'kashida-image-optimizer' ),
        'Delete Optimized & Restore Originals' => __( 'Delete Optimized & Restore Originals', 'kashida-image-optimizer' ),
        'Regeneration deletes existing .webp/.avif files and recreates them using current quality settings. Restore deletes only .webp/.avif files; original JPG/PNG files are never touched.' => __( 'Regeneration deletes existing .webp/.avif files and recreates them using current quality settings. Restore deletes only .webp/.avif files; original JPG/PNG files are never touched.', 'kashida-image-optimizer' ),
        'Optimization Statistics' => __( 'Optimization Statistics', 'kashida-image-optimizer' ),
        'Review the space saved by optimized images.' => __( 'Review the space saved by optimized images.', 'kashida-image-optimizer' ),
        'Calculate Stats' => __( 'Calculate Stats', 'kashida-image-optimizer' ),
        'Total images' => __( 'Total images', 'kashida-image-optimizer' ),
        'Converted images' => __( 'Converted images', 'kashida-image-optimizer' ),
        'WebP files' => __( 'WebP files', 'kashida-image-optimizer' ),
        'AVIF files' => __( 'AVIF files', 'kashida-image-optimizer' ),
        'Original size' => __( 'Original size', 'kashida-image-optimizer' ),
        'Optimized size' => __( 'Optimized size', 'kashida-image-optimizer' ),
        'Space saved' => __( 'Space saved', 'kashida-image-optimizer' ),
        'Cleanup Orphan Files' => __( 'Cleanup Orphan Files', 'kashida-image-optimizer' ),
        'Find optimized files whose original image no longer exists.' => __( 'Find optimized files whose original image no longer exists.', 'kashida-image-optimizer' ),
        'Finds .webp/.avif files whose original JPG/PNG no longer exists.' => __( 'Finds .webp/.avif files whose original JPG/PNG no longer exists.', 'kashida-image-optimizer' ),
        'Scan for Orphans' => __( 'Scan for Orphans', 'kashida-image-optimizer' ),
        'Delete All Orphans' => __( 'Delete All Orphans', 'kashida-image-optimizer' ),
        'Help & Documentation' => __( 'Help & Documentation', 'kashida-image-optimizer' ),
        'Everything you need to use Kashida Image Optimizer.' => __( 'Everything you need to use Kashida Image Optimizer.', 'kashida-image-optimizer' ),
        'What does Kashida Image Optimizer do?' => __( 'What does Kashida Image Optimizer do?', 'kashida-image-optimizer' ),
        'How to use' => __( 'How to use', 'kashida-image-optimizer' ),
        'Quality settings' => __( 'Quality settings', 'kashida-image-optimizer' ),
        'Large images' => __( 'Large images', 'kashida-image-optimizer' ),
        'Original files' => __( 'Original files', 'kashida-image-optimizer' ),
        'Statistics & cleanup' => __( 'Statistics & cleanup', 'kashida-image-optimizer' ),
        'Kashida Image Optimizer creates optimized WebP and AVIF versions of your JPG/JPEG and PNG images while keeping the original image unchanged.' => __( 'Kashida Image Optimizer creates optimized WebP and AVIF versions of your JPG/JPEG and PNG images while keeping the original image unchanged.', 'kashida-image-optimizer' ),
        'Automatic optimization:' => __( 'Automatic optimization:', 'kashida-image-optimizer' ),
        'Enable Auto-convert new uploads to optimize new images automatically after WordPress finishes processing the upload.' => __( 'Enable Auto-convert new uploads to optimize new images automatically after WordPress finishes processing the upload.', 'kashida-image-optimizer' ),
        'Existing images:' => __( 'Existing images:', 'kashida-image-optimizer' ),
        'Use Start Batch Conversion to optimize images already in your Media Library.' => __( 'Use Start Batch Conversion to optimize images already in your Media Library.', 'kashida-image-optimizer' ),
        'New settings for existing files:' => __( 'New settings for existing files:', 'kashida-image-optimizer' ),
        'Use Regenerate Optimized Images to delete existing WebP/AVIF files and recreate them with the current settings.' => __( 'Use Regenerate Optimized Images to delete existing WebP/AVIF files and recreate them with the current settings.', 'kashida-image-optimizer' ),
        'Individual images:' => __( 'Individual images:', 'kashida-image-optimizer' ),
        'Use the Kashida Optimization column in Media Library to Optimize, Re-optimize, or Delete optimized files for a specific image.' => __( 'Use the Kashida Optimization column in Media Library to Optimize, Re-optimize, or Delete optimized files for a specific image.', 'kashida-image-optimizer' ),
        'WebP and AVIF have separate quality settings. Smart PNG can automatically preserve transparent PNG artwork using lossless WebP, while Smart Content Detection can use different quality levels for photos and graphics.' => __( 'WebP and AVIF have separate quality settings. Smart PNG can automatically preserve transparent PNG artwork using lossless WebP, while Smart Content Detection can use different quality levels for photos and graphics.', 'kashida-image-optimizer' ),
        'Images larger than 10 MB or wider/taller than 6000 px are not optimized automatically to avoid excessive server resource usage. They can still be optimized manually from Media Library.' => __( 'Images larger than 10 MB or wider/taller than 6000 px are not optimized automatically to avoid excessive server resource usage. They can still be optimized manually from Media Library.', 'kashida-image-optimizer' ),
        'The original JPG/JPEG/PNG file is preserved. Deleting an optimized file restores delivery to the original image.' => __( 'The original JPG/JPEG/PNG file is preserved. Deleting an optimized file restores delivery to the original image.', 'kashida-image-optimizer' ),
        'Calculate Stats shows optimization results, while Scan for Orphans helps find optimized files whose original image no longer exists.' => __( 'Calculate Stats shows optimization results, while Scan for Orphans helps find optimized files whose original image no longer exists.', 'kashida-image-optimizer' ),
        'Developed by' => __( 'Developed by', 'kashida-image-optimizer' ),
        'by' => __( 'by', 'kashida-image-optimizer' ),
        'image attachment(s) found in the library. Processing' => __( 'image attachment(s) found in the library. Processing', 'kashida-image-optimizer' ),
        'attachment per request for reliability.' => __( 'attachment per request for reliability.', 'kashida-image-optimizer' ),
        'Lightweight image optimization with WebP &amp; AVIF.' => __( 'Lightweight image optimization with WebP &amp; AVIF.', 'kashida-image-optimizer' ),
        'Delete Optimized &amp; Restore Originals' => __( 'Delete Optimized &amp; Restore Originals', 'kashida-image-optimizer' ),
        'Help &amp; Documentation' => __( 'Help &amp; Documentation', 'kashida-image-optimizer' ),
        'Statistics &amp; cleanup' => __( 'Statistics &amp; cleanup', 'kashida-image-optimizer' ),
        'Higher quality preserves more detail but creates larger files. Recommended: <strong>80-90</strong>. Default: 85.' => __( 'Higher quality preserves more detail but creates larger files. Recommended: <strong>80-90</strong>. Default: 85.', 'kashida-image-optimizer' ),
        'Images exceeding either limit are not optimized automatically. The original remains untouched, and <strong>Optimize now</strong> is still available for manual processing.' => __( 'Images exceeding either limit are not optimized automatically. The original remains untouched, and <strong>Optimize now</strong> is still available for manual processing.', 'kashida-image-optimizer' ),
        'Recommended: <strong>Smart</strong>. Transparent PNGs such as logos, UI graphics and product artwork are encoded losslessly; opaque PNGs use the smart Photo/Graphics quality when Smart Content Detection is enabled.' => __( 'Recommended: <strong>Smart</strong>. Transparent PNGs such as logos, UI graphics and product artwork are encoded losslessly; opaque PNGs use the smart Photo/Graphics quality when Smart Content Detection is enabled.', 'kashida-image-optimizer' ),
        'Presets only change the two quality values above. They do not modify existing optimized files until you use <strong>Re-optimize</strong> or <strong>Regenerate Optimized Images</strong>.' => __( 'Presets only change the two quality values above. They do not modify existing optimized files until you use <strong>Re-optimize</strong> or <strong>Regenerate Optimized Images</strong>.', 'kashida-image-optimizer' ),
        'Kashida Image Optimizer creates optimized <strong>WebP</strong> and <strong>AVIF</strong> versions of your JPG/JPEG and PNG images while keeping the original image unchanged.' => __( 'Kashida Image Optimizer creates optimized <strong>WebP</strong> and <strong>AVIF</strong> versions of your JPG/JPEG and PNG images while keeping the original image unchanged.', 'kashida-image-optimizer' ),
        'WebP and AVIF have separate quality settings. <strong>Smart PNG</strong> can automatically preserve transparent PNG artwork using lossless WebP, while <strong>Smart Content Detection</strong> can use different quality levels for photos and graphics.' => __( 'WebP and AVIF have separate quality settings. <strong>Smart PNG</strong> can automatically preserve transparent PNG artwork using lossless WebP, while <strong>Smart Content Detection</strong> can use different quality levels for photos and graphics.', 'kashida-image-optimizer' ),
        'Images larger than <strong>10 MB</strong> or wider/taller than <strong>6000 px</strong> are not optimized automatically to avoid excessive server resource usage. They can still be optimized manually from Media Library.' => __( 'Images larger than <strong>10 MB</strong> or wider/taller than <strong>6000 px</strong> are not optimized automatically to avoid excessive server resource usage. They can still be optimized manually from Media Library.', 'kashida-image-optimizer' ),
        'Original' => __( 'Original', 'kashida-image-optimizer' ),
        'WebP' => __( 'WebP', 'kashida-image-optimizer' ),
        'AVIF' => __( 'AVIF', 'kashida-image-optimizer' ),
        'Optimized' => __( 'Optimized', 'kashida-image-optimizer' ),
        'Original:' => __( 'Original:', 'kashida-image-optimizer' ),
        'Best:' => __( 'Best:', 'kashida-image-optimizer' ),
        'Saved' => __( 'Saved', 'kashida-image-optimizer' ),
        'Re-optimize' => __( 'Re-optimize', 'kashida-image-optimizer' ),
        'Delete optimized' => __( 'Delete optimized', 'kashida-image-optimizer' ),
        'Large image — Not optimized' => __( 'Large image — Not optimized', 'kashida-image-optimizer' ),
        'Automatic optimization was skipped. The original will not be modified.' => __( 'Automatic optimization was skipped. The original will not be modified.', 'kashida-image-optimizer' ),
        'Not optimized' => __( 'Not optimized', 'kashida-image-optimizer' ),
        'Optimize now' => __( 'Optimize now', 'kashida-image-optimizer' ),
    );
    ob_start();
    $settings   = kashimop_get_settings();
    $total      = kashimop_count_all_images();
    $avif_ready = kashimop_avif_supported();
    ?>
    <div class="wrap kashimop-admin-wrap">
        <div class="kashimop-admin-header">
            <div class="kashimop-brand">
                <a href="https://kashida.ly/" target="_blank" rel="noopener noreferrer">
                    <img src="<?php echo esc_url( plugin_dir_url( __FILE__ ) . 'assets/kashida-logo.svg' ); ?>" alt="Kashida Media Services" />
                </a>
                <div>
                    <h1>Kashida Image Optimizer</h1>
                    <p>Lightweight image optimization with WebP &amp; AVIF.</p>
                </div>
            </div>
            <span class="kashimop-version">v5.1.24</span>
        </div>

        <nav class="kashimop-tabs" aria-label="Kashida Image Optimizer">
            <button type="button" class="kashimop-tab is-active" data-tab="settings">Settings</button>
            <button type="button" class="kashimop-tab" data-tab="help">Help</button>
        </nav>

        <div id="kashimop-tab-settings" class="kashimop-tab-panel is-active">
        <form method="post" action="options.php">
            <?php settings_fields( 'kashimop_settings_group' ); ?>
            <table class="form-table">
                <tr>
                    <th><label for="kashimop_webp_quality">WebP Quality</label></th>
                    <td>
                        <div class="kashimop-quality-control">
                            <input type="range" min="1" max="100" step="1" id="kashimop_webp_quality_range" value="<?php echo esc_attr( $settings['webp_quality'] ); ?>" />
                            <input type="number" min="1" max="100" step="1" id="kashimop_webp_quality"
                                name="<?php echo esc_attr( KASHIMOP_OPTION ); ?>[webp_quality]"
                                value="<?php echo esc_attr( $settings['webp_quality'] ); ?>" />
                            <span class="kashimop-quality-label" id="kashimop_webp_quality_label"><?php echo esc_html( $settings['webp_quality'] ); ?>/100</span>
                        </div>
                        <p class="description">Higher quality preserves more detail but creates larger files. Recommended: <strong>80-90</strong>. Default: 85.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="kashimop_auto">Auto-convert new uploads</label></th>
                    <td>
                        <input type="checkbox" id="kashimop_auto"
                            name="<?php echo esc_attr( KASHIMOP_OPTION ); ?>[auto_convert]"
                            <?php checked( $settings['auto_convert'], 1 ); ?> value="1" />
                        <label for="kashimop_auto">Automatically convert images on upload</label>
                        <p class="description">Large-image protection: automatic optimization is skipped for images over 10 MB or wider/taller than 6000 px. You can always optimize them manually from Media Library.</p>
                    </td>
                </tr>
                <tr>
                    <th>Large Image Protection</th>
                    <td>
                        <strong>10 MB or 6000 × 6000 px</strong>
                        <p class="description">Images exceeding either limit are not optimized automatically. The original remains untouched, and <strong>Optimize now</strong> is still available for manual processing.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="kashimop_avif">Enable AVIF</label></th>
                    <td>
                        <input type="checkbox" id="kashimop_avif"
                            name="<?php echo esc_attr( KASHIMOP_OPTION ); ?>[enable_avif]"
                            <?php checked( $settings['enable_avif'], 1 ); ?>
                            <?php disabled( ! $avif_ready ); ?> value="1" />
                        <label for="kashimop_avif">
                            Also generate AVIF versions
                            <?php echo $avif_ready ? '' : '(not supported by this server)'; ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th><label for="kashimop_avif_quality">AVIF Quality</label></th>
                    <td>
                        <div class="kashimop-quality-control">
                            <input type="range" min="1" max="100" step="1" id="kashimop_avif_quality_range" value="<?php echo esc_attr( $settings['avif_quality'] ); ?>" />
                            <input type="number" min="1" max="100" step="1" id="kashimop_avif_quality"
                                name="<?php echo esc_attr( KASHIMOP_OPTION ); ?>[avif_quality]"
                                value="<?php echo esc_attr( $settings['avif_quality'] ); ?>" />
                            <span class="kashimop-quality-label" id="kashimop_avif_quality_label"><?php echo esc_html( $settings['avif_quality'] ); ?>/100</span>
                        </div>
                        <p class="description">AVIF usually reaches similar visual quality at a lower setting. Recommended: <strong>60-75</strong>. Default: 70.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="kashimop_png_webp_mode">PNG → WebP Handling</label></th>
                    <td>
                        <select id="kashimop_png_webp_mode" name="<?php echo esc_attr( KASHIMOP_OPTION ); ?>[png_webp_mode]">
                            <option value="smart" <?php selected( $settings['png_webp_mode'], 'smart' ); ?>>Smart — lossless for transparent PNGs</option>
                            <option value="quality" <?php selected( $settings['png_webp_mode'], 'quality' ); ?>>Quality — use WebP quality setting for all PNGs</option>
                            <option value="lossless" <?php selected( $settings['png_webp_mode'], 'lossless' ); ?>>Lossless — preserve PNG pixels in WebP</option>
                        </select>
                        <p class="description">Recommended: <strong>Smart</strong>. Transparent PNGs such as logos, UI graphics and product artwork are encoded losslessly; opaque PNGs use the smart Photo/Graphics quality when Smart Content Detection is enabled.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="kashimop_smart_detection">Smart Content Detection</label></th>
                    <td>
                        <label>
                            <input type="checkbox" id="kashimop_smart_detection" name="<?php echo esc_attr( KASHIMOP_OPTION ); ?>[smart_content_detection]" <?php checked( $settings['smart_content_detection'], 1 ); ?> value="1" />
                            Automatically distinguish photographic images from graphics
                        </label>
                        <p class="description">Uses lightweight image analysis to detect transparent/graphic artwork and low-color illustrations. Photos use the normal quality settings; detected graphics use higher quality to protect text, edges and flat colors. The original file is never modified.</p>
                    </td>
                </tr>
                <tr>
                    <th><label>Smart Quality</label></th>
                    <td>
                        <div style="display:grid;grid-template-columns:180px 90px 180px 90px;gap:8px 12px;align-items:center;max-width:500px">
                            <label for="kashimop_smart_photo_webp_quality">Photo WebP</label>
                            <input type="number" min="1" max="100" id="kashimop_smart_photo_webp_quality" name="<?php echo esc_attr( KASHIMOP_OPTION ); ?>[smart_photo_webp_quality]" value="<?php echo esc_attr( $settings['smart_photo_webp_quality'] ); ?>" />
                            <label for="kashimop_smart_graphics_webp_quality">Graphics WebP</label>
                            <input type="number" min="1" max="100" id="kashimop_smart_graphics_webp_quality" name="<?php echo esc_attr( KASHIMOP_OPTION ); ?>[smart_graphics_webp_quality]" value="<?php echo esc_attr( $settings['smart_graphics_webp_quality'] ); ?>" />
                            <label for="kashimop_smart_photo_avif_quality">Photo AVIF</label>
                            <input type="number" min="1" max="100" id="kashimop_smart_photo_avif_quality" name="<?php echo esc_attr( KASHIMOP_OPTION ); ?>[smart_photo_avif_quality]" value="<?php echo esc_attr( $settings['smart_photo_avif_quality'] ); ?>" />
                            <label for="kashimop_smart_graphics_avif_quality">Graphics AVIF</label>
                            <input type="number" min="1" max="100" id="kashimop_smart_graphics_avif_quality" name="<?php echo esc_attr( KASHIMOP_OPTION ); ?>[smart_graphics_avif_quality]" value="<?php echo esc_attr( $settings['smart_graphics_avif_quality'] ); ?>" />
                        </div>
                        <p class="description">Recommended: Photo WebP 85, Graphics WebP 95, Photo AVIF 70, Graphics AVIF 80.</p>
                    </td>
                </tr>
                <tr>
                    <th><label>Quality Presets</label></th>
                    <td>
                        <div class="kashimop-quality-presets">
                            <button type="button" class="button" data-webp="80" data-avif="60" data-photo-webp="80" data-graphics-webp="90" data-photo-avif="60" data-graphics-avif="70">Smaller Files</button>
                            <button type="button" class="button button-primary" data-webp="85" data-avif="70" data-photo-webp="85" data-graphics-webp="95" data-photo-avif="70" data-graphics-avif="80">Recommended</button>
                            <button type="button" class="button" data-webp="90" data-avif="80" data-photo-webp="90" data-graphics-webp="98" data-photo-avif="80" data-graphics-avif="85">High Quality</button>
                        </div>
                        <p class="description">Presets only change the two quality values above. They do not modify existing optimized files until you use <strong>Re-optimize</strong> or <strong>Regenerate Optimized Images</strong>.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button( 'Save Settings' ); ?>
        </form>

        <section class="kashimop-card">
            <div class="kashimop-card-heading"><div><h2>Image Tools</h2><p>Process existing images or restore the original files.</p></div></div>
        <h3>Batch Convert Existing Library</h3>
        <p><?php echo esc_html( $total ); ?> image attachment(s) found in the library. Processing <?php echo esc_html( KASHIMOP_BATCH_SIZE ); ?> attachment per request for reliability.</p>
        <button id="kashimop-start-batch" class="button button-primary">Start Batch Conversion</button>
        <button id="kashimop-start-regenerate" class="button button-secondary">Regenerate Optimized Images</button>
        <button id="kashimop-restore-originals" class="button" style="margin-left:6px;">Delete Optimized &amp; Restore Originals</button>
        <p class="description">Regeneration deletes existing .webp/.avif files and recreates them using current quality settings. Restore deletes only .webp/.avif files; original JPG/PNG files are never touched.</p>
        <div id="kashimop-progress-wrap" style="display:none;max-width:500px;margin-top:15px;background:#eee;border-radius:4px;overflow:hidden;">
            <div id="kashimop-progress-bar" style="height:24px;width:0%;background:#2271b1;color:#fff;text-align:center;line-height:24px;font-size:12px;transition:width .3s;">0%</div>
        </div>
        <p id="kashimop-progress-text"></p>
        <ul id="kashimop-error-list" style="color:#b32d2e;"></ul>

        </section>

        <section class="kashimop-card">
            <div class="kashimop-card-heading"><div><h2>Optimization Statistics</h2><p>Review the space saved by optimized images.</p></div></div>
        <button id="kashimop-load-stats" class="button">Calculate Stats</button>
        <table class="widefat striped" id="kashimop-stats-table" style="max-width:500px;margin-top:10px;display:none;">
            <tbody>
                <tr><th>Total images</th><td id="kashimop-stat-total">-</td></tr>
                <tr><th>Converted images</th><td id="kashimop-stat-converted">-</td></tr>
                <tr><th>WebP files</th><td id="kashimop-stat-webp-count">-</td></tr>
                <tr><th>AVIF files</th><td id="kashimop-stat-avif-count">-</td></tr>
                <tr><th>Original size</th><td id="kashimop-stat-original">-</td></tr>
                <tr><th>Optimized size</th><td id="kashimop-stat-optimized">-</td></tr>
                <tr><th>Space saved</th><td id="kashimop-stat-saved">-</td></tr>
            </tbody>
        </table>

        </section>

        <section class="kashimop-card">
            <div class="kashimop-card-heading"><div><h2>Cleanup Orphan Files</h2><p>Find optimized files whose original image no longer exists.</p></div></div>
        <p class="description">Finds .webp/.avif files whose original JPG/PNG no longer exists.</p>
        <button id="kashimop-scan-orphans" class="button">Scan for Orphans</button>
        <button id="kashimop-delete-orphans" class="button button-secondary" style="display:none;">Delete All Orphans</button>
        <ul id="kashimop-orphan-list" style="margin-top:10px;"></ul>

        </section>
        </div>

        <div id="kashimop-tab-help" class="kashimop-tab-panel">
        <section class="kashimop-card kashimop-help-card">
            <div class="kashimop-card-heading"><div><h2>Help &amp; Documentation</h2><p>Everything you need to use Kashida Image Optimizer.</p></div></div>
        <div class="kashimop-help">
            <h3>What does Kashida Image Optimizer do?</h3>
            <p>Kashida Image Optimizer creates optimized <strong>WebP</strong> and <strong>AVIF</strong> versions of your JPG/JPEG and PNG images while keeping the original image unchanged.</p>

            <h3>How to use</h3>
            <ol>
                <li><strong><?php echo esc_html__( 'Automatic optimization:', 'kashida-image-optimizer' ); ?></strong> <?php echo esc_html__( 'Enable Auto-convert new uploads to optimize new images automatically after WordPress finishes processing the upload.', 'kashida-image-optimizer' ); ?></li>
                <li><strong><?php echo esc_html__( 'Existing images:', 'kashida-image-optimizer' ); ?></strong> <?php echo esc_html__( 'Use Start Batch Conversion to optimize images already in your Media Library.', 'kashida-image-optimizer' ); ?></li>
                <li><strong><?php echo esc_html__( 'New settings for existing files:', 'kashida-image-optimizer' ); ?></strong> <?php echo esc_html__( 'Use Regenerate Optimized Images to delete existing WebP/AVIF files and recreate them with the current settings.', 'kashida-image-optimizer' ); ?></li>
                <li><strong><?php echo esc_html__( 'Individual images:', 'kashida-image-optimizer' ); ?></strong> <?php echo esc_html__( 'Use the Kashida Optimization column in Media Library to Optimize, Re-optimize, or Delete optimized files for a specific image.', 'kashida-image-optimizer' ); ?></li>
            </ol>

            <h3>Quality settings</h3>
            <p>WebP and AVIF have separate quality settings. <strong>Smart PNG</strong> can automatically preserve transparent PNG artwork using lossless WebP, while <strong>Smart Content Detection</strong> can use different quality levels for photos and graphics.</p>

            <h3>Large images</h3>
            <p>Images larger than <strong>10 MB</strong> or wider/taller than <strong>6000 px</strong> are not optimized automatically to avoid excessive server resource usage. They can still be optimized manually from Media Library.</p>

            <h3>Original files</h3>
            <p>The original JPG/JPEG/PNG file is preserved. Deleting an optimized file restores delivery to the original image.</p>

            <h3>Statistics &amp; cleanup</h3>
            <p><em><?php echo esc_html__( 'Calculate Stats', 'kashida-image-optimizer' ); ?></em> <?php echo esc_html__( 'shows optimization results, while', 'kashida-image-optimizer' ); ?> <em><?php echo esc_html__( 'Scan for Orphans', 'kashida-image-optimizer' ); ?></em> <?php echo esc_html__( 'helps find optimized files whose original image no longer exists.', 'kashida-image-optimizer' ); ?></p>
        </div>

        </section>
        </div>

        <div class="kashimop-admin-footer">
            <img src="<?php echo esc_url( plugin_dir_url( __FILE__ ) . 'assets/kashida-logo.svg' ); ?>" alt="Kashida Media Services" />
            <div>Developed by <a href="https://kashida.ly/" target="_blank" rel="noopener noreferrer">Kashida Media Services</a> by <a href="https://www.behance.net/Mrwan_elgobee" target="_blank" rel="noopener noreferrer">Mrwan elGobee</a></div>
        </div>
    </div>
    <?php
    $kashimop_rendered = ob_get_clean();
    echo wp_kses( strtr( $kashimop_rendered, $kashimop_translation_map ), kashimop_get_admin_allowed_html() );
}

/* ============================================================
 * MEDIA LIBRARY: OPTIMIZATION STATUS COLUMN & ACTIONS
 * ============================================================ */

add_filter( 'manage_media_columns', function ( $columns ) {
    if ( ! current_user_can( 'manage_options' ) ) return $columns;
    $new = array();
    foreach ( $columns as $key => $label ) {
        $new[ $key ] = $label;
        if ( 'date' === $key ) {
            $new['kashimop_optimization'] = 'Kashida Optimization';
        }
    }
    if ( ! isset( $new['kashimop_optimization'] ) ) {
        $new['kashimop_optimization'] = 'Kashida Optimization';
    }
    return $new;
} );

function kashimop_get_media_optimization_data( $attachment_id ) {
    static $cache = array();
    $attachment_id = absint( $attachment_id );

    if ( isset( $cache[ $attachment_id ] ) ) {
        return $cache[ $attachment_id ];
    }

    $result = array(
        'supported'      => false,
        'has_webp'       => false,
        'has_avif'       => false,
        'webp_size'      => 0,
        'avif_size'      => 0,
        'original_size'  => 0,
        'best_size'      => 0,
        'saved_bytes'    => 0,
        'saved_percent'  => 0,
        'status'         => 'not_supported',
    );

    $mime = get_post_mime_type( $attachment_id );
    if ( ! in_array( $mime, array( 'image/jpeg', 'image/png' ), true ) ) {
        return $cache[ $attachment_id ] = $result;
    }

    $file = get_attached_file( $attachment_id );
    if ( ! $file || ! is_file( $file ) ) {
        $result['supported'] = true;
        $result['status'] = 'missing';
        return $cache[ $attachment_id ] = $result;
    }

    $result['supported'] = true;
    $result['original_size'] = (int) filesize( $file );
    $result['large_info'] = kashimop_get_large_image_info( $attachment_id );

    $webp = kashimop_swap_ext( $file, 'webp' );
    $avif = kashimop_swap_ext( $file, 'avif' );

    if ( is_file( $webp ) ) {
        $result['has_webp']  = true;
        $result['webp_size'] = (int) filesize( $webp );
    }
    if ( is_file( $avif ) ) {
        $result['has_avif']  = true;
        $result['avif_size'] = (int) filesize( $avif );
    }

    $sizes = array();
    if ( $result['has_webp'] ) $sizes[] = $result['webp_size'];
    if ( $result['has_avif'] ) $sizes[] = $result['avif_size'];

    if ( ! empty( $sizes ) && $result['original_size'] > 0 ) {
        $result['best_size']     = min( $sizes );
        $result['saved_bytes']   = max( 0, $result['original_size'] - $result['best_size'] );
        $result['saved_percent'] = round( ( $result['saved_bytes'] / $result['original_size'] ) * 100, 1 );
    }

    if ( $result['has_webp'] || $result['has_avif'] ) {
        $result['status'] = 'optimized';
    } else {
        $result['status'] = 'not_optimized';
    }

    return $cache[ $attachment_id ] = $result;
}


function kashimop_file_to_url( $file ) {
    if ( ! $file || ! is_string( $file ) ) return '';

    $upload_dir = wp_upload_dir();
    $basedir    = wp_normalize_path( trailingslashit( $upload_dir['basedir'] ) );
    $file_path  = wp_normalize_path( $file );

    if ( 0 !== strpos( $file_path, $basedir ) ) {
        return '';
    }

    $relative = ltrim( substr( $file_path, strlen( $basedir ) ), '/' );
    if ( '' === $relative ) return '';

    return trailingslashit( $upload_dir['baseurl'] ) . str_replace( DIRECTORY_SEPARATOR, '/', $relative );
}

/**
 * Builds a safe preview payload for the Media Library review dialog.
 * The original is never modified; optimized files are shown alongside it.
 *
 * @param int $attachment_id Attachment ID.
 * @return array
 */
function kashimop_get_media_preview_data( $attachment_id ) {
    $data = kashimop_get_media_optimization_data( $attachment_id );
    if ( empty( $data['supported'] ) || 'missing' === $data['status'] ) {
        return array();
    }

    $file = get_attached_file( $attachment_id );
    if ( ! $file || ! is_file( $file ) ) return array();

    $items = array();
    $original_url = kashimop_file_to_url( $file );
    if ( $original_url ) {
        $items[] = array(
            'key'   => 'original',
            'label' => 'Original',
            'size'  => (int) $data['original_size'],
            'url'   => $original_url,
            'best'  => false,
        );
    }

    $best = '';
    if ( $data['has_webp'] && $data['has_avif'] ) {
        $best = ( $data['avif_size'] <= $data['webp_size'] ) ? 'avif' : 'webp';
    } elseif ( $data['has_avif'] ) {
        $best = 'avif';
    } elseif ( $data['has_webp'] ) {
        $best = 'webp';
    }

    foreach ( array( 'webp', 'avif' ) as $format ) {
        if ( empty( $data[ 'has_' . $format ] ) ) continue;
        $derivative = kashimop_swap_ext( $file, $format );
        $url = kashimop_file_to_url( $derivative );
        if ( ! $url ) continue;
        $items[] = array(
            'key'   => $format,
            'label' => strtoupper( $format ),
            'size'  => (int) $data[ $format . '_size' ],
            'url'   => $url,
            'best'  => $best === $format,
        );
    }

    return array(
        'title'         => get_the_title( $attachment_id ),
        'items'         => $items,
        'original_size' => (int) $data['original_size'],
        'best_size'     => (int) $data['best_size'],
        'best_format'   => $best,
        'saved_percent' => (float) $data['saved_percent'],
    );
}

function kashimop_render_media_optimization_cell( $attachment_id ) {
    $kashimop_media_strings = array( 'Original file missing', 'Optimized', 'WebP ✓ ', 'WebP —', 'AVIF ✓ ', 'AVIF —', 'Original: ', 'Best:', 'Saved', 'Re-optimize', 'Delete optimized', 'Large image — Not optimized', 'Automatic optimization was skipped. The original will not be modified.', 'Not optimized', 'Optimize now' );
    $kashimop_media_map = array(
        'Original file missing' => __( 'Original file missing', 'kashida-image-optimizer' ),
        'Optimized' => __( 'Optimized', 'kashida-image-optimizer' ),
        'WebP ✓ ' => __( 'WebP ✓ ', 'kashida-image-optimizer' ),
        'WebP —' => __( 'WebP —', 'kashida-image-optimizer' ),
        'AVIF ✓ ' => __( 'AVIF ✓ ', 'kashida-image-optimizer' ),
        'AVIF —' => __( 'AVIF —', 'kashida-image-optimizer' ),
        'Original: ' => __( 'Original: ', 'kashida-image-optimizer' ),
        'Best:' => __( 'Best:', 'kashida-image-optimizer' ),
        'Saved' => __( 'Saved', 'kashida-image-optimizer' ),
        'Re-optimize' => __( 'Re-optimize', 'kashida-image-optimizer' ),
        'Delete optimized' => __( 'Delete optimized', 'kashida-image-optimizer' ),
        'Large image — Not optimized' => __( 'Large image — Not optimized', 'kashida-image-optimizer' ),
        'Automatic optimization was skipped. The original will not be modified.' => __( 'Automatic optimization was skipped. The original will not be modified.', 'kashida-image-optimizer' ),
        'Not optimized' => __( 'Not optimized', 'kashida-image-optimizer' ),
        'Optimize now' => __( 'Optimize now', 'kashida-image-optimizer' ),
    );
    ob_start();
    $data = kashimop_get_media_optimization_data( $attachment_id );

    if ( ! $data['supported'] ) {
        echo '<span class="kashimop-media-na">—</span>';
        echo wp_kses( strtr( ob_get_clean(), $kashimop_media_map ), kashimop_get_admin_allowed_html() );
        return;
    }

    if ( 'missing' === $data['status'] ) {
        echo '<span class="kashimop-media-status kashimop-media-status-error">Original file missing</span>';
        echo wp_kses( strtr( ob_get_clean(), $kashimop_media_map ), kashimop_get_admin_allowed_html() );
        return;
    }

    if ( 'optimized' === $data['status'] ) {
        echo '<div class="kashimop-media-status kashimop-media-status-ok">';
        echo '<strong><span class="kashimop-status-dot" aria-hidden="true"></span>Optimized</strong>';
        echo '<span class="kashimop-media-formats">';
        if ( $data['has_webp'] ) {
            echo 'WebP ✓ ' . esc_html( size_format( $data['webp_size'] ) );
        } else {
            echo 'WebP —';
        }
        echo ' &nbsp; ';
        if ( $data['has_avif'] ) {
            echo 'AVIF ✓ ' . esc_html( size_format( $data['avif_size'] ) );
        } else {
            echo 'AVIF —';
        }
        echo '</span>';
        echo '<span class="kashimop-media-sizes">';
        echo 'Original: ' . esc_html( size_format( $data['original_size'] ) );
        echo '</span>';
        $best_label = '';
        if ( $data['has_webp'] && $data['has_avif'] ) {
            $best_label = ( $data['avif_size'] <= $data['webp_size'] ) ? 'AVIF' : 'WebP';
        } elseif ( $data['has_avif'] ) {
            $best_label = 'AVIF';
        } elseif ( $data['has_webp'] ) {
            $best_label = 'WebP';
        }
        if ( $best_label ) {
            echo '<span class="kashimop-media-best">Best: <strong>' . esc_html( $best_label ) . '</strong> · ' . esc_html( size_format( $data['best_size'] ) ) . '</span>';
        }
        if ( $data['saved_percent'] > 0 ) {
            echo '<div class="kashimop-saving-wrap">';
            echo '<div class="kashimop-saving-bar"><span style="width:' . esc_attr( min( 100, max( 0, $data['saved_percent'] ) ) ) . '%"></span></div>';
            echo '<span class="kashimop-media-saving">Saved ' . esc_html( $data['saved_percent'] ) . '%</span>';
            echo '</div>';
        }
        echo '</div>';
        echo '<div class="kashimop-media-actions">';
        echo '<button type="button" class="button-link kashimop-media-action" data-action="kashimop_media_reoptimize" data-id="' . esc_attr( $attachment_id ) . '">Re-optimize</button>';
        echo '<button type="button" class="button-link kashimop-media-action kashimop-media-delete" data-action="kashimop_media_delete" data-id="' . esc_attr( $attachment_id ) . '">Delete optimized</button>';
        echo '</div>';
        echo wp_kses( strtr( ob_get_clean(), $kashimop_media_map ), kashimop_get_admin_allowed_html() );
        return;
    }

    $large = ! empty( $data['large_info']['is_large'] );
    if ( $large ) {
        echo '<div class="kashimop-media-status kashimop-media-status-large">';
        echo '<strong><span class="kashimop-status-dot kashimop-status-dot-large" aria-hidden="true"></span>Large image — Not optimized</strong>';
        $details = array();
        if ( ! empty( $data['large_info']['bytes'] ) ) {
            $details[] = size_format( $data['large_info']['bytes'] );
        }
        if ( ! empty( $data['large_info']['width'] ) && ! empty( $data['large_info']['height'] ) ) {
            $details[] = $data['large_info']['width'] . ' × ' . $data['large_info']['height'] . ' px';
        }
        if ( ! empty( $details ) ) {
            echo '<span class="kashimop-media-sizes">' . esc_html( implode( ' · ', $details ) ) . '</span>';
        }
        echo '<span class="kashimop-media-large-note">Automatic optimization was skipped. The original will not be modified.</span>';
        echo '</div>';
    } else {
        echo '<div class="kashimop-media-status kashimop-media-status-pending">';
        echo '<strong><span class="kashimop-status-dot kashimop-status-dot-pending" aria-hidden="true"></span>Not optimized</strong>';
        echo '<span class="kashimop-media-sizes">Original: ' . esc_html( size_format( $data['original_size'] ) ) . '</span>';
        echo '</div>';
    }
    echo '<div class="kashimop-media-actions">';
    echo '<button type="button" class="button-link kashimop-media-action" data-action="kashimop_media_optimize" data-id="' . esc_attr( $attachment_id ) . '">Optimize now</button>';
    echo '</div>';
    echo wp_kses( strtr( ob_get_clean(), $kashimop_media_map ), kashimop_get_admin_allowed_html() );
}

add_action( 'manage_media_custom_column', function ( $column_name, $attachment_id ) {
    if ( 'kashimop_optimization' !== $column_name || ! current_user_can( 'manage_options' ) ) return;
    kashimop_render_media_optimization_cell( $attachment_id );
}, 10, 2 );

function kashimop_media_ajax_guard() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied', 'kashida-image-optimizer' ) ), 403 );
    }
}

function kashimop_media_action_response( $attachment_id, $message ) {
    wp_send_json_success( array(
        'message' => $message,
        'html'    => kashimop_get_media_optimization_html( $attachment_id ),
        'preview' => kashimop_get_media_preview_data( $attachment_id ),
    ) );
}

function kashimop_get_media_optimization_html( $attachment_id ) {
    ob_start();
    kashimop_render_media_optimization_cell( $attachment_id );
    return ob_get_clean();
}

add_action( 'wp_ajax_kashimop_media_optimize', function () {
    kashimop_media_ajax_guard();
    check_ajax_referer( 'kashimop_media_nonce', 'nonce' );
    $id = isset( $_POST['attachment_id'] ) ? absint( wp_unslash( $_POST['attachment_id'] ) ) : 0;
    if ( ! $id || ! wp_attachment_is_image( $id ) ) wp_send_json_error( array( 'message' => __( 'Invalid image.', 'kashida-image-optimizer' ) ) );

    try {
        kashimop_convert_attachment_all_sizes( $id );
        kashimop_media_action_response( $id, __( 'Image optimized successfully.', 'kashida-image-optimizer' ) );
    } catch ( Throwable $e ) {
        kashimop_log_error( "Media optimize failed for attachment {$id}: " . $e->getMessage() );
        wp_send_json_error( array( 'message' => __( 'The operation failed. Check the WordPress debug log for details.', 'kashida-image-optimizer' ) ) );
    }
} );

add_action( 'wp_ajax_kashimop_media_reoptimize', function () {
    kashimop_media_ajax_guard();
    check_ajax_referer( 'kashimop_media_nonce', 'nonce' );
    $id = isset( $_POST['attachment_id'] ) ? absint( wp_unslash( $_POST['attachment_id'] ) ) : 0;
    if ( ! $id || ! wp_attachment_is_image( $id ) ) wp_send_json_error( array( 'message' => __( 'Invalid image.', 'kashida-image-optimizer' ) ) );

    try {
        kashimop_regenerate_attachment_all_sizes( $id );
        kashimop_media_action_response( $id, __( 'Image re-optimized successfully.', 'kashida-image-optimizer' ) );
    } catch ( Throwable $e ) {
        kashimop_log_error( "Media re-optimize failed for attachment {$id}: " . $e->getMessage() );
        wp_send_json_error( array( 'message' => __( 'The operation failed. Check the WordPress debug log for details.', 'kashida-image-optimizer' ) ) );
    }
} );

add_action( 'wp_ajax_kashimop_media_delete', function () {
    kashimop_media_ajax_guard();
    check_ajax_referer( 'kashimop_media_nonce', 'nonce' );
    $id = isset( $_POST['attachment_id'] ) ? absint( wp_unslash( $_POST['attachment_id'] ) ) : 0;
    if ( ! $id || ! wp_attachment_is_image( $id ) ) wp_send_json_error( array( 'message' => __( 'Invalid image.', 'kashida-image-optimizer' ) ) );

    try {
        $deleted = kashimop_delete_attachment_derivatives( $id );
        kashimop_media_action_response( $id, sprintf(
            /* translators: %d: number of optimized files deleted. */
            __( '%d optimized file(s) deleted. Original image remains untouched.', 'kashida-image-optimizer' ),
            $deleted
        ) );
    } catch ( Throwable $e ) {
        kashimop_log_error( "Media delete optimized failed for attachment {$id}: " . $e->getMessage() );
        wp_send_json_error( array( 'message' => __( 'The operation failed. Check the WordPress debug log for details.', 'kashida-image-optimizer' ) ) );
    }
} );

/* ============================================================
 * SETTINGS PAGE UI
 * ============================================================ */

add_action( 'admin_enqueue_scripts', function ( $hook ) {
    if ( 'toplevel_page_kashida-image-optimizer' !== $hook ) return;

    wp_enqueue_script( 'jquery' );
    $css = '
    .kashimop-admin-wrap{max-width:1120px;margin-top:24px}.kashimop-admin-header{display:flex;align-items:center;justify-content:space-between;gap:20px;background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:18px 22px;margin:0 0 16px;box-shadow:0 1px 2px rgba(0,0,0,.04)}
    .kashimop-brand{display:flex;align-items:center;gap:18px}.kashimop-brand>a{display:flex;align-items:center}.kashimop-brand img{display:block;width:155px;height:auto;max-height:42px}.kashimop-brand h1{font-size:23px;line-height:1.2;margin:0 0 4px;font-weight:600}.kashimop-brand p{margin:0;color:#646970;font-size:13px}.kashimop-version{font-size:12px;font-weight:600;color:#50575e;background:#f0f0f1;border-radius:20px;padding:5px 10px;white-space:nowrap}
    .kashimop-tabs{display:flex;gap:4px;border-bottom:1px solid #dcdcde;margin-bottom:18px}.kashimop-tab{border:0;background:transparent;color:#50575e;font-size:14px;font-weight:600;padding:10px 15px;cursor:pointer;border-bottom:3px solid transparent;margin-bottom:-1px}.kashimop-tab:hover{color:#1d2327}.kashimop-tab.is-active{color:#2271b1;border-bottom-color:#2271b1}.kashimop-tab-panel{display:none}.kashimop-tab-panel.is-active{display:block}
    .kashimop-tab-panel>form{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:4px 22px 20px;box-shadow:0 1px 2px rgba(0,0,0,.03);overflow:hidden}.kashimop-tab-panel .form-table{margin-top:0}.kashimop-tab-panel .form-table th{width:220px;padding:22px 20px 22px 0;font-weight:600;color:#1d2327}.kashimop-tab-panel .form-table td{padding:22px 0}.kashimop-tab-panel .form-table tr+tr th,.kashimop-tab-panel .form-table tr+tr td{border-top:1px solid #f0f0f1}.kashimop-tab-panel .form-table .description{max-width:780px;margin-top:7px;line-height:1.55}.kashimop-tab-panel .form-table input[type=number],.kashimop-tab-panel .form-table select{min-height:34px}.kashimop-quality-control{display:flex;align-items:center;gap:10px;max-width:600px}.kashimop-quality-control input[type=range]{width:360px}.kashimop-quality-control input[type=number]{width:75px}.kashimop-quality-label{min-width:55px;font-weight:600;color:#1d2327}.kashimop-quality-presets{display:flex;gap:8px;flex-wrap:wrap}.kashimop-quality-presets .button-primary{box-shadow:none}.kashimop-quality-note{margin-top:8px}
    .kashimop-card{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:20px 22px;margin-top:16px;box-shadow:0 1px 2px rgba(0,0,0,.03)}.kashimop-card-heading{display:flex;align-items:flex-start;justify-content:space-between;gap:15px;margin-bottom:14px}.kashimop-card h2{font-size:17px;margin:0 0 4px}.kashimop-card h3{font-size:14px;margin:0 0 8px}.kashimop-card-heading p{margin:0;color:#646970;font-size:13px}.kashimop-card .description{line-height:1.5}.kashimop-help{max-width:780px}.kashimop-help h3{margin:24px 0 8px;font-size:14px;font-weight:600;color:#1d2327}.kashimop-help h3:first-child{font-size:15px}.kashimop-help h3:first-child{margin-top:0}.kashimop-help p,.kashimop-help li{line-height:1.65;color:#50575e}.kashimop-help strong{color:#1d2327}.kashimop-help ol{padding-left:22px}.kashimop-help li+li{margin-top:7px}
    .kashimop-admin-footer{display:flex;align-items:center;justify-content:center;gap:10px;margin:22px 0 10px;padding:18px 0 4px;border-top:1px solid #dcdcde;color:#646970;font-size:12px}.kashimop-admin-footer div{line-height:1.6;text-align:center}.kashimop-admin-footer img{width:115px;height:auto;display:block}.kashimop-admin-footer a{color:#2271b1;text-decoration:none}.kashimop-admin-footer a:hover{text-decoration:underline}
    @media(max-width:782px){.kashimop-admin-header{align-items:flex-start}.kashimop-brand{gap:12px}.kashimop-brand img{width:120px}.kashimop-brand h1{font-size:20px}.kashimop-tab-panel .form-table th,.kashimop-tab-panel .form-table td{display:block;width:auto;padding:14px 0}.kashimop-quality-control{max-width:100%;flex-wrap:wrap}.kashimop-quality-control input[type=range]{width:min(100%,360px)}.kashimop-card{padding:16px}.kashimop-admin-footer{flex-wrap:wrap}}
    ';
    wp_add_inline_style( 'common', $css );

    $kashimop_js_i18n = array(
        'Optimization Result' => __( 'Optimization Result', 'kashida-image-optimizer' ),
        'Saved' => __( 'Saved', 'kashida-image-optimizer' ),
        'Best' => __( 'Best', 'kashida-image-optimizer' ),
        'Original' => __( 'Original', 'kashida-image-optimizer' ),
        'Close' => __( 'Close', 'kashida-image-optimizer' ),
        'Keep optimized' => __( 'Keep optimized', 'kashida-image-optimizer' ),
        'Delete optimized' => __( 'Delete optimized', 'kashida-image-optimizer' ),
        'Deleting...' => __( 'Deleting...', 'kashida-image-optimizer' ),
        'Delete failed.' => __( 'Delete failed.', 'kashida-image-optimizer' ),
        'Request failed. Please try again.' => __( 'Request failed. Please try again.', 'kashida-image-optimizer' ),
        'Processing...' => __( 'Processing...', 'kashida-image-optimizer' ),
        'Operation failed.' => __( 'Operation failed.', 'kashida-image-optimizer' ),
        'Delete the optimized WebP/AVIF files for this image? The original will not be deleted.' => __( 'Delete the optimized WebP/AVIF files for this image? The original will not be deleted.', 'kashida-image-optimizer' ),
        'Delete the optimized WebP/AVIF files for this image? The original JPG/PNG will not be deleted.' => __( 'Delete the optimized WebP/AVIF files for this image? The original JPG/PNG will not be deleted.', 'kashida-image-optimizer' ),
        'Re-optimize this image using the current quality settings? Existing WebP/AVIF files will be replaced.' => __( 'Re-optimize this image using the current quality settings? Existing WebP/AVIF files will be replaced.', 'kashida-image-optimizer' ),
        'The original image has not been modified. Review the result above before deciding whether to keep or delete the optimized versions.' => __( 'The original image has not been modified. Review the result above before deciding whether to keep or delete the optimized versions.', 'kashida-image-optimizer' )
    );
    $kashimop_js_i18n_json = wp_json_encode( $kashimop_js_i18n );
    $inline = "
    jQuery(function($){
        var kashimopI18n = {$kashimop_js_i18n_json};
        function kashimopSyncQuality(range, number, label){
            $(range).on('input change', function(){
                var value = Math.max(1, Math.min(100, parseInt($(this).val(), 10) || 1));
                $(number).val(value);
                $(label).text(value + '/100');
            });
            $(number).on('input change', function(){
                var value = Math.max(1, Math.min(100, parseInt($(this).val(), 10) || 1));
                $(this).val(value);
                $(range).val(value);
                $(label).text(value + '/100');
            });
        }
        kashimopSyncQuality('#kashimop_webp_quality_range','#kashimop_webp_quality','#kashimop_webp_quality_label');
        kashimopSyncQuality('#kashimop_avif_quality_range','#kashimop_avif_quality','#kashimop_avif_quality_label');

        $('.kashimop-quality-presets button').on('click', function(){
            var btn = $(this);
            var webp = btn.data('webp'), avif = btn.data('avif');
            $('#kashimop_webp_quality_range').val(webp).trigger('change');
            $('#kashimop_avif_quality_range').val(avif).trigger('change');
            $('#kashimop_smart_photo_webp_quality').val(btn.data('photo-webp'));
            $('#kashimop_smart_graphics_webp_quality').val(btn.data('graphics-webp'));
            $('#kashimop_smart_photo_avif_quality').val(btn.data('photo-avif'));
            $('#kashimop_smart_graphics_avif_quality').val(btn.data('graphics-avif'));
            $('.kashimop-quality-presets button').removeClass('button-primary');
            btn.addClass('button-primary');
        });

        $('.kashimop-tab').on('click', function(){
            var tab = $(this).data('tab');
            $('.kashimop-tab').removeClass('is-active');
            $(this).addClass('is-active');
            $('.kashimop-tab-panel').removeClass('is-active');
            $('#kashimop-tab-' + tab).addClass('is-active');
        });
    });
    ";
    wp_add_inline_script( 'jquery', $inline );
} );

/* ============================================================
 * ADMIN ASSETS (loaded only on our own page)
 * ============================================================ */

add_action( 'admin_enqueue_scripts', function ( $hook ) {
    if ( 'upload.php' !== $hook ) return;

    wp_enqueue_script( 'jquery' );
    $nonce = wp_create_nonce( 'kashimop_media_nonce' );

    $css = '
    .column-kashimop_optimization{width:270px}.kashimop-media-status{line-height:1.4}.kashimop-media-status strong{display:block}.kashimop-media-status-ok strong{color:#008a20}.kashimop-media-status-pending strong{color:#996800}.kashimop-media-status-error{color:#b32d2e}.kashimop-media-status-large{color:#8a4b00}.kashimop-status-dot-large{background:#dba617}.kashimop-media-large-note{display:block;color:#646970;font-size:12px;margin-top:3px}.kashimop-status-dot{display:inline-block;width:7px;height:7px;margin:0 5px 1px 0;border-radius:50%;background:#00a32a}.kashimop-status-dot-pending{background:#dba617}.kashimop-media-formats,.kashimop-media-sizes,.kashimop-media-saving{display:block;color:#646970;font-size:12px;margin-top:3px}.kashimop-media-sizes{font-variant-numeric:tabular-nums;color:#50575e}.kashimop-saving-wrap{margin-top:5px;display:flex;align-items:center;gap:7px}.kashimop-saving-bar{width:85px;height:6px;background:#dcdcde;border-radius:99px;overflow:hidden}.kashimop-saving-bar span{display:block;height:100%;background:#00a32a;border-radius:99px}.kashimop-media-saving{color:#008a20;font-weight:600;margin-top:0}.kashimop-media-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:6px}.kashimop-media-action{cursor:pointer;text-decoration:none}.kashimop-media-delete{color:#b32d2e!important}.kashimop-media-action[disabled]{opacity:.6;cursor:wait}.kashimop-preview-overlay{position:fixed;inset:0;background:rgba(0,0,0,.72);z-index:100000;display:flex;align-items:center;justify-content:center;padding:30px;box-sizing:border-box}.kashimop-preview-modal{background:#fff;width:min(1100px,96vw);max-height:92vh;overflow:auto;border-radius:4px;box-shadow:0 10px 40px rgba(0,0,0,.35);padding:22px;box-sizing:border-box}.kashimop-preview-header{display:flex;justify-content:space-between;align-items:flex-start;gap:20px;margin-bottom:16px}.kashimop-preview-header h2{margin:0;font-size:20px}.kashimop-preview-summary{margin:4px 0 0;color:#646970}.kashimop-preview-close{border:0;background:transparent;font-size:26px;line-height:1;cursor:pointer;color:#50575e}.kashimop-preview-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:14px}.kashimop-preview-card{border:1px solid #dcdcde;border-radius:4px;overflow:hidden;background:#f6f7f7}.kashimop-preview-card.kashimop-preview-best{border-color:#00a32a;box-shadow:0 0 0 1px #00a32a inset}.kashimop-preview-card-title{padding:10px 12px;font-weight:600;background:#fff;border-bottom:1px solid #dcdcde}.kashimop-preview-card-title .kashimop-preview-badge{float:right;color:#008a20;font-size:12px}.kashimop-preview-image-wrap{height:310px;display:flex;align-items:center;justify-content:center;padding:12px;box-sizing:border-box;background:repeating-conic-gradient(#f0f0f1 0 25%,#fff 0 50%) 50%/18px 18px}.kashimop-preview-image{max-width:100%;max-height:286px;width:auto;height:auto;display:block;object-fit:contain}.kashimop-preview-meta{padding:10px 12px;color:#50575e;font-size:13px}.kashimop-preview-footer{display:flex;justify-content:flex-end;gap:10px;margin-top:18px;padding-top:15px;border-top:1px solid #dcdcde}.kashimop-preview-footer .kashimop-preview-delete{color:#b32d2e}.kashimop-preview-note{margin:12px 0 0;color:#646970;font-size:12px}@media(max-width:700px){.kashimop-preview-overlay{padding:10px}.kashimop-preview-modal{padding:14px}.kashimop-preview-image-wrap{height:230px}.kashimop-preview-image{max-height:206px}}
    ';
    wp_add_inline_style( 'list-tables', $css );

    $kashimop_media_js_i18n = array(
        'Optimization Result' => __( 'Optimization Result', 'kashida-image-optimizer' ),
        'Saved' => __( 'Saved', 'kashida-image-optimizer' ),
        'Best' => __( 'Best', 'kashida-image-optimizer' ),
        'Original' => __( 'Original', 'kashida-image-optimizer' ),
        'Close' => __( 'Close', 'kashida-image-optimizer' ),
        'Keep optimized' => __( 'Keep optimized', 'kashida-image-optimizer' ),
        'Delete optimized' => __( 'Delete optimized', 'kashida-image-optimizer' ),
        'Deleting...' => __( 'Deleting...', 'kashida-image-optimizer' ),
        'Delete failed.' => __( 'Delete failed.', 'kashida-image-optimizer' ),
        'Request failed. Please try again.' => __( 'Request failed. Please try again.', 'kashida-image-optimizer' ),
        'Processing...' => __( 'Processing...', 'kashida-image-optimizer' ),
        'Operation failed.' => __( 'Operation failed.', 'kashida-image-optimizer' ),
        'Delete the optimized WebP/AVIF files for this image? The original will not be deleted.' => __( 'Delete the optimized WebP/AVIF files for this image? The original will not be deleted.', 'kashida-image-optimizer' ),
        'Delete the optimized WebP/AVIF files for this image? The original JPG/PNG will not be deleted.' => __( 'Delete the optimized WebP/AVIF files for this image? The original JPG/PNG will not be deleted.', 'kashida-image-optimizer' ),
        'Re-optimize this image using the current quality settings? Existing WebP/AVIF files will be replaced.' => __( 'Re-optimize this image using the current quality settings? Existing WebP/AVIF files will be replaced.', 'kashida-image-optimizer' ),
        'The original image has not been modified. Review the result above before deciding whether to keep or delete the optimized versions.' => __( 'The original image has not been modified. Review the result above before deciding whether to keep or delete the optimized versions.', 'kashida-image-optimizer' ),
    );
    $kashimop_media_js_i18n_json = wp_json_encode( $kashimop_media_js_i18n );
    $nonce_js = wp_json_encode( $nonce );
    $inline = "    jQuery(function($){
        var kashimopI18n = " . $kashimop_media_js_i18n_json . ";
        var nonce = " . $nonce_js . ";
        function kashimopFormatBytes(bytes){
            bytes = parseInt(bytes,10) || 0;
            if(!bytes) return '0 B';
            var units=['B','KB','MB','GB','TB'], i=Math.floor(Math.log(bytes)/Math.log(1024));
            i=Math.max(0,Math.min(i,units.length-1));
            var n=bytes/Math.pow(1024,i);
            return (i===0 ? Math.round(n) : (n>=10 ? n.toFixed(0) : n.toFixed(1)))+' '+units[i];
        }
        function kashimopClosePreview(){ $('.kashimop-preview-overlay').remove(); }
        function kashimopShowPreview(preview, attachmentId, cell){
            if(!preview || !preview.items || !preview.items.length) return;
            kashimopClosePreview();
            var overlay=$('<div class=\"kashimop-preview-overlay\" role=\"dialog\" aria-modal=\"true\"></div>');
            var modal=$('<div class=\"kashimop-preview-modal\"></div>');
            var header=$('<div class=\"kashimop-preview-header\"></div>');
            var heading=$('<div></div>');
            heading.append($('<h2></h2>').text(kashimopI18n['Optimization Result']));
            var summary=kashimopI18n['Original']+': '+kashimopFormatBytes(preview.original_size);
            if(preview.best_size){ summary+=' · '+kashimopI18n['Best']+': '+kashimopFormatBytes(preview.best_size); }
            if(preview.best_format){ summary+=' · '+String(preview.best_format).toUpperCase(); }
            if(preview.saved_percent>0){ summary+=' · '+kashimopI18n['Saved']+' '+preview.saved_percent+'%'; }
            heading.append($('<p class=\"kashimop-preview-summary\"></p>').text(summary));
            header.append(heading);
            var close=$('<button type=\"button\" class=\"kashimop-preview-close\" aria-label=\"' + kashimopI18n['Close'] + '\">×</button>');
            close.on('click',kashimopClosePreview);
            header.append(close);
            modal.append(header);

            var grid=$('<div class=\"kashimop-preview-grid\"></div>');
            $.each(preview.items,function(_,item){
                var card=$('<div class=\"kashimop-preview-card\"></div>');
                if(item.best) card.addClass('kashimop-preview-best');
                var title=$('<div class=\"kashimop-preview-card-title\"></div>').text(item.label);
                if(item.best) title.append($('<span class=\"kashimop-preview-badge\">' + kashimopI18n['Best'] + '</span>'));
                card.append(title);
                var wrap=$('<div class=\"kashimop-preview-image-wrap\"></div>');
                var img=$('<img class=\"kashimop-preview-image\" loading=\"eager\" alt=\"\">').attr('src',item.url).attr('alt',item.label);
                wrap.append(img);
                card.append(wrap);
                card.append($('<div class=\"kashimop-preview-meta\"></div>').text(kashimopFormatBytes(item.size)));
                grid.append(card);
            });
            modal.append(grid);
            modal.append($('<p class=\"kashimop-preview-note\"></p>').text(kashimopI18n['The original image has not been modified. Review the result above before deciding whether to keep or delete the optimized versions.']));

            var footer=$('<div class=\"kashimop-preview-footer\"></div>');
            var keep=$('<button type=\"button\" class=\"button button-primary\">'+kashimopI18n['Keep optimized']+'</button>');
            keep.on('click',kashimopClosePreview);
            var del=$('<button type=\"button\" class=\"button-link kashimop-preview-delete\">'+kashimopI18n['Delete optimized']+'</button>');
            del.on('click',function(){
                if(!window.confirm(kashimopI18n['Delete the optimized WebP/AVIF files for this image? The original will not be deleted.'])) return;
                del.prop('disabled',true).text(kashimopI18n['Deleting...']);
                $.post(ajaxurl,{action:'kashimop_media_delete',nonce:nonce,attachment_id:attachmentId},function(res){
                    if(!res || !res.success){
                        window.alert(res && res.data && res.data.message ? res.data.message : kashimopI18n['Delete failed.']);
                        del.prop('disabled',false).text(kashimopI18n['Delete optimized']);
                        return;
                    }
                    kashimopClosePreview();
                    if(cell && res.data && res.data.html) cell.html(res.data.html);
                }).fail(function(){
                    window.alert(kashimopI18n['Request failed. Please try again.']);
                    del.prop('disabled',false).text(kashimopI18n['Delete optimized']);
                });
            });
            footer.append(del).append(keep);
            modal.append(footer);
            overlay.append(modal).on('click',function(e){ if(e.target===overlay[0]) kashimopClosePreview(); });
            $('body').append(overlay);
        }
        $(document).on('keydown',function(e){ if(e.key==='Escape') kashimopClosePreview(); });
        $(document).on('click', '.kashimop-media-action', function(e){
            e.preventDefault();
            var btn=$(this), action=btn.data('action'), id=parseInt(btn.data('id'),10), cell=btn.closest('.column-kashimop_optimization');
            if(!id || !action) return;
            if(action==='kashimop_media_delete' && !window.confirm(kashimopI18n['Delete the optimized WebP/AVIF files for this image? The original JPG/PNG will not be deleted.'])) return;
            if(action==='kashimop_media_reoptimize' && !window.confirm(kashimopI18n['Re-optimize this image using the current quality settings? Existing WebP/AVIF files will be replaced.'])) return;
            btn.prop('disabled',true).text(kashimopI18n['Processing...']);
            $.post(ajaxurl,{action:action,nonce:nonce,attachment_id:id},function(res){
                if(!res || !res.success){
                    window.alert(res && res.data && res.data.message ? res.data.message : kashimopI18n['Operation failed.']);
                    btn.prop('disabled',false);
                    return;
                }
                if(cell && res.data && res.data.html) cell.html(res.data.html);
                if((action==='kashimop_media_optimize' || action==='kashimop_media_reoptimize') && res.data && res.data.preview){
                    kashimopShowPreview(res.data.preview,id,cell);
                }
            }).fail(function(){
                window.alert(kashimopI18n['Request failed. Please try again.']);
                btn.prop('disabled',false);
            });
        });
    });
";
    wp_add_inline_script( 'jquery', $inline );
} );

add_action( 'admin_enqueue_scripts', function ( $hook ) {
    if ( 'toplevel_page_kashida-image-optimizer' !== $hook ) return;

    wp_enqueue_script( 'jquery' );
    $nonce = wp_create_nonce( 'kashimop_ajax_nonce' );

    $kashimop_batch_i18n = array(
        'Error:' => __( 'Error:', 'kashida-image-optimizer' ),
        'unknown' => __( 'unknown', 'kashida-image-optimizer' ),
        'images processed' => __( 'images processed', 'kashida-image-optimizer' ),
        'Processed' => __( 'Processed', 'kashida-image-optimizer' ),
        'processing next image...' => __( 'processing next image...', 'kashida-image-optimizer' ),
        'Done!' => __( 'Done!', 'kashida-image-optimizer' ),
        'Request failed. Please retry.' => __( 'Request failed. Please retry.', 'kashida-image-optimizer' ),
        'This will delete and recreate all WebP/AVIF files using current quality settings. Continue?' => __( 'This will delete and recreate all WebP/AVIF files using current quality settings. Continue?', 'kashida-image-optimizer' ),
        'This will delete all generated WebP/AVIF files and make WordPress use the original JPG/PNG files again. Original files will NOT be deleted. Continue?' => __( 'This will delete all generated WebP/AVIF files and make WordPress use the original JPG/PNG files again. Original files will NOT be deleted. Continue?', 'kashida-image-optimizer' ),
        'Calculating...' => __( 'Calculating...', 'kashida-image-optimizer' ),
        'Calculate Stats' => __( 'Calculate Stats', 'kashida-image-optimizer' ),
        'Scanning...' => __( 'Scanning...', 'kashida-image-optimizer' ),
        'Scan for Orphans' => __( 'Scan for Orphans', 'kashida-image-optimizer' ),
        'No orphan files found.' => __( 'No orphan files found.', 'kashida-image-optimizer' ),
        'Delete all orphan WebP/AVIF files?' => __( 'Delete all orphan WebP/AVIF files?', 'kashida-image-optimizer' ),
        'Deleting...' => __( 'Deleting...', 'kashida-image-optimizer' ),
        'Delete All Orphans' => __( 'Delete All Orphans', 'kashida-image-optimizer' ),
        'file(s) deleted.' => __( 'file(s) deleted.', 'kashida-image-optimizer' )
    );
    $kashimop_batch_i18n_json = wp_json_encode( $kashimop_batch_i18n );
    $inline = "
    jQuery(function($){
        var kashimopI18n = {$kashimop_batch_i18n_json};
        var nonce = '" . esc_js( $nonce ) . "';

        function kashimopRunBatch(action, btn){
            btn.prop('disabled', true);
            $('#kashimop-progress-wrap').show();
            $('#kashimop-error-list').empty();

            function kashimopProcessBatch(offset){
                $.post(ajaxurl, { action: action, nonce: nonce, offset: offset }, function(res){
                    if(!res.success){
                        $('#kashimop-progress-text').text('Error: ' + (res.data ? res.data.message : kashimopI18n['unknown']));
                        btn.prop('disabled', false);
                        return;
                    }
                    var data = res.data;
                    var percent = data.total > 0 ? Math.round((data.processed / data.total) * 100) : 100;
                    $('#kashimop-progress-bar').css('width', percent + '%').text(percent + '%');
                    $('#kashimop-progress-text').text(data.done ? (data.total + ' / ' + data.total + ' images processed') : ('Processed ' + data.processed + ' / ' + data.total + ' — processing next image...'));
                    if(data.errors && data.errors.length){
                        data.errors.forEach(function(err){ $('<li>').text(err).appendTo('#kashimop-error-list'); });
                    }
                    if(!data.done){ kashimopProcessBatch(data.processed); }
                    else { $('#kashimop-progress-text').append(' \u2014 Done!'); btn.prop('disabled', false); }
                }).fail(function(){
                    $('#kashimop-progress-text').text(kashimopI18n['Request failed. Please retry.']);
                    btn.prop('disabled', false);
                });
            }
            kashimopProcessBatch(0);
        }

        $('#kashimop-start-batch').on('click', function(e){
            e.preventDefault();
            kashimopRunBatch('kashimop_batch_convert', $(this));
        });

        $('#kashimop-start-regenerate').on('click', function(e){
            e.preventDefault();
            if(!confirm(kashimopI18n['This will delete and recreate all WebP/AVIF files using current quality settings. Continue?'])) return;
            kashimopRunBatch('kashimop_regenerate_batch', $(this));
        });

        $('#kashimop-restore-originals').on('click', function(e){
            e.preventDefault();
            if(!confirm(kashimopI18n['This will delete all generated WebP/AVIF files and make WordPress use the original JPG/PNG files again. Original files will NOT be deleted. Continue?'])) return;
            kashimopRunBatch('kashimop_restore_originals_batch', $(this));
        });

        $('#kashimop-load-stats').on('click', function(e){
            e.preventDefault();
            var btn = $(this);
            btn.prop('disabled', true).text(kashimopI18n['Calculating...']);
            $.post(ajaxurl, { action: 'kashimop_get_stats', nonce: nonce }, function(res){
                btn.prop('disabled', false).text(kashimopI18n['Calculate Stats']);
                if(!res.success) return;
                var d = res.data;
                $('#kashimop-stat-total').text(d.total);
                $('#kashimop-stat-converted').text(d.converted);
                $('#kashimop-stat-webp-count').text(d.webp_count);
                $('#kashimop-stat-avif-count').text(d.avif_count);
                $('#kashimop-stat-original').text(d.original_size);
                $('#kashimop-stat-optimized').text(d.optimized_size);
                $('#kashimop-stat-saved').text(d.saved);
                $('#kashimop-stats-table').show();
            });
        });

        $('#kashimop-scan-orphans').on('click', function(e){
            e.preventDefault();
            var btn = $(this);
            btn.prop('disabled', true).text(kashimopI18n['Scanning...']);
            $.post(ajaxurl, { action: 'kashimop_scan_orphans', nonce: nonce }, function(res){
                btn.prop('disabled', false).text(kashimopI18n['Scan for Orphans']);
                if(!res.success) return;
                var list = $('#kashimop-orphan-list').empty();
                if(res.data.files.length === 0){
                    list.append('<li>No orphan files found.</li>');
                    $('#kashimop-delete-orphans').hide();
                    return;
                }
                res.data.files.forEach(function(f){ $('<li>').text(f).appendTo(list); });
                $('#kashimop-delete-orphans').show();
            });
        });

        $('#kashimop-delete-orphans').on('click', function(e){
            e.preventDefault();
            if(!confirm(kashimopI18n['Delete all orphan WebP/AVIF files?'])) return;
            var btn = $(this);
            btn.prop('disabled', true).text(kashimopI18n['Deleting...']);
            $.post(ajaxurl, { action: 'kashimop_delete_orphans', nonce: nonce }, function(res){
                btn.prop('disabled', false).text(kashimopI18n['Delete All Orphans']);
                if(res.success){
                    $('#kashimop-orphan-list').empty(); $('<li>').text(res.data.deleted + ' file(s) deleted.').appendTo('#kashimop-orphan-list');
                    $('#kashimop-delete-orphans').hide();
                }
            });
        });
    });
    ";
    wp_add_inline_script( 'jquery', $inline );
} );

/* ============================================================
 * BATCH ID CACHING (transient-based, avoids repeated queries)
 * ============================================================ */

/**
 * Returns the list of convertible image IDs, using a transient cache
 * during an in-progress batch run to avoid re-querying on every request.
 *
 * @param bool $force_refresh Force a fresh query and reset the cache.
 * @return int[]
 */
function kashimop_get_cached_image_ids( $force_refresh = false ) {
    if ( ! $force_refresh ) {
        $cached = get_transient( KASHIMOP_BATCH_CACHE_KEY );
        if ( false !== $cached && is_array( $cached ) ) {
            return $cached;
        }
    }

    $ids = kashimop_get_all_image_ids();
    set_transient( KASHIMOP_BATCH_CACHE_KEY, $ids, KASHIMOP_BATCH_CACHE_TTL );
    return $ids;
}

function kashimop_clear_batch_cache() {
    delete_transient( KASHIMOP_BATCH_CACHE_KEY );
}

/* ============================================================
 * AJAX: BATCH CONVERT
 * ============================================================ */

add_action( 'wp_ajax_kashimop_batch_convert', function () {
    check_ajax_referer( 'kashimop_ajax_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'message' => __( 'Permission denied', 'kashida-image-optimizer' ) ) );

    $offset = isset( $_POST['offset'] ) ? absint( wp_unslash( $_POST['offset'] ) ) : 0;

    // Refresh the cached ID list only at the start of a run; reuse it for subsequent offsets.
    $ids   = kashimop_get_cached_image_ids( 0 === $offset );
    $total = count( $ids );
    $chunk = array_slice( $ids, $offset, KASHIMOP_BATCH_SIZE );
    $errors = array();

    foreach ( $chunk as $id ) {
        try {
            kashimop_convert_attachment_all_sizes( $id );
        } catch ( Throwable $e ) {
            $errors[] = sprintf( 'Attachment #%d failed: %s', $id, $e->getMessage() );
            kashimop_log_error( "Batch conversion failed for attachment {$id}: " . $e->getMessage() );
        }
    }

    $processed = min( $offset + count( $chunk ), $total );
    $done = $processed >= $total;

    if ( $done ) {
        kashimop_clear_batch_cache();
    }

    wp_send_json_success( array(
        'processed' => $processed,
        'total'     => $total,
        'done'      => $done,
        'errors'    => $errors,
    ) );
} );

/* ============================================================
 * AJAX: REGENERATE OPTIMIZED IMAGES
 * ============================================================ */

add_action( 'wp_ajax_kashimop_regenerate_batch', function () {
    check_ajax_referer( 'kashimop_ajax_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'message' => __( 'Permission denied', 'kashida-image-optimizer' ) ) );

    $offset = isset( $_POST['offset'] ) ? absint( wp_unslash( $_POST['offset'] ) ) : 0;

    $ids   = kashimop_get_cached_image_ids( 0 === $offset );
    $total = count( $ids );
    $chunk = array_slice( $ids, $offset, KASHIMOP_BATCH_SIZE );
    $errors = array();

    foreach ( $chunk as $id ) {
        try {
            kashimop_regenerate_attachment_all_sizes( $id );
        } catch ( Throwable $e ) {
            $errors[] = sprintf( 'Attachment #%d failed: %s', $id, $e->getMessage() );
            kashimop_log_error( "Regeneration failed for attachment {$id}: " . $e->getMessage() );
        }
    }

    $processed = min( $offset + count( $chunk ), $total );
    $done = $processed >= $total;

    if ( $done ) {
        kashimop_clear_batch_cache();
    }

    wp_send_json_success( array(
        'processed' => $processed,
        'total'     => $total,
        'done'      => $done,
        'errors'    => $errors,
    ) );
} );

/* ============================================================
 * AJAX: RESTORE ORIGINALS
 * ============================================================ */

add_action( 'wp_ajax_kashimop_restore_originals_batch', function () {
    check_ajax_referer( 'kashimop_ajax_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'message' => __( 'Permission denied', 'kashida-image-optimizer' ) ) );

    $offset = isset( $_POST['offset'] ) ? absint( wp_unslash( $_POST['offset'] ) ) : 0;

    $ids   = kashimop_get_cached_image_ids( 0 === $offset );
    $total = count( $ids );
    $chunk = array_slice( $ids, $offset, KASHIMOP_BATCH_SIZE );
    $errors = array();
    $deleted = 0;

    foreach ( $chunk as $id ) {
        try {
            $deleted += kashimop_delete_attachment_derivatives( $id );
        } catch ( Throwable $e ) {
            $errors[] = sprintf( 'Attachment #%d failed: %s', $id, $e->getMessage() );
            kashimop_log_error( "Restore originals failed for attachment {$id}: " . $e->getMessage() );
        }
    }

    $processed = min( $offset + count( $chunk ), $total );
    $done = $processed >= $total;

    if ( $done ) {
        kashimop_clear_batch_cache();
    }

    wp_send_json_success( array(
        'processed' => $processed,
        'total'     => $total,
        'done'      => $done,
        'deleted'   => $deleted,
        'errors'    => $errors,
    ) );
} );

/* ============================================================
 * AJAX: STATS
 * ============================================================ */

add_action( 'wp_ajax_kashimop_get_stats', function () {
    check_ajax_referer( 'kashimop_ajax_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'message' => __( 'Permission denied', 'kashida-image-optimizer' ) ) );

    $ids = kashimop_get_all_image_ids();
    $total = count( $ids );
    $converted = 0;
    $webp_count = 0;
    $avif_count = 0;
    $original_size = 0;
    $optimized_size = 0;

    foreach ( $ids as $id ) {
        $file = get_attached_file( $id );
        if ( ! $file || ! file_exists( $file ) ) continue;
        $original_size += filesize( $file );

        $webp = kashimop_swap_ext( $file, 'webp' );
        $avif = kashimop_swap_ext( $file, 'avif' );

        $has_webp = file_exists( $webp );
        $has_avif = file_exists( $avif );

        if ( $has_webp ) $webp_count++;
        if ( $has_avif ) $avif_count++;

        if ( $has_webp || $has_avif ) {
            $converted++;
            // Use the smaller of the two optimized formats when both exist.
            if ( $has_webp && $has_avif ) {
                $optimized_size += min( filesize( $webp ), filesize( $avif ) );
            } elseif ( $has_avif ) {
                $optimized_size += filesize( $avif );
            } else {
                $optimized_size += filesize( $webp );
            }
        } else {
            $optimized_size += filesize( $file );
        }
    }

    $saved_bytes = max( 0, $original_size - $optimized_size );

    wp_send_json_success( array(
        'total'          => $total,
        'converted'      => $converted,
        'webp_count'     => $webp_count,
        'avif_count'     => $avif_count,
        'original_size'  => size_format( $original_size, 2 ),
        'optimized_size' => size_format( $optimized_size, 2 ),
        'saved'          => size_format( $saved_bytes, 2 ) . ' (' . ( $original_size > 0 ? round( $saved_bytes / $original_size * 100, 1 ) : 0 ) . '%)',
    ) );
} );

/* ============================================================
 * AJAX: ORPHAN SCAN / DELETE
 * ============================================================ */

function kashimop_find_orphans() {
    $upload_dir = wp_upload_dir();
    $base = $upload_dir['basedir'];
    $orphans = array();
    if ( ! is_dir( $base ) ) return $orphans;

    try {
        $iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ) );
        foreach ( $iterator as $file ) {
            if ( ! $file->isFile() ) continue;
            $ext = strtolower( $file->getExtension() );
            if ( ! in_array( $ext, array( 'webp', 'avif' ), true ) ) continue;

            $path = $file->getPathname();
            $name_no_ext = substr( $path, 0, -( strlen( $ext ) + 1 ) );
            $has_original = false;
            foreach ( array( 'jpg', 'jpeg', 'png' ) as $orig_ext ) {
                if ( file_exists( $name_no_ext . '.' . $orig_ext ) ) { $has_original = true; break; }
            }
            if ( ! $has_original ) $orphans[] = $path;
        }
    } catch ( Throwable $e ) {
        kashimop_log_error( 'Orphan scan failed: ' . $e->getMessage() );
    }
    return $orphans;
}

add_action( 'wp_ajax_kashimop_scan_orphans', function () {
    check_ajax_referer( 'kashimop_ajax_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'message' => __( 'Permission denied', 'kashida-image-optimizer' ) ) );

    $orphans = kashimop_find_orphans();
    $upload_dir = wp_upload_dir();
    $relative = array_map( function ( $p ) use ( $upload_dir ) {
        return str_replace( $upload_dir['basedir'], '', $p );
    }, $orphans );

    wp_send_json_success( array( 'files' => $relative ) );
} );

add_action( 'wp_ajax_kashimop_delete_orphans', function () {
    check_ajax_referer( 'kashimop_ajax_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'message' => __( 'Permission denied', 'kashida-image-optimizer' ) ) );

    $orphans = kashimop_find_orphans();
    $deleted = 0;
    foreach ( $orphans as $path ) {
        if ( @unlink( $path ) ) $deleted++; // phpcs:ignore
    }

    wp_send_json_success( array( 'deleted' => $deleted ) );
} );

/* ============================================================
 * HELPERS
 * ============================================================ */

function kashimop_get_all_image_ids() {
    return get_posts( array(
        'post_type'      => 'attachment',
        'post_mime_type' => array( 'image/jpeg', 'image/png' ),
        'post_status'    => 'inherit',
        'fields'         => 'ids',
        'posts_per_page' => -1,
        'orderby'        => 'ID',
        'order'          => 'ASC',
    ) );
}

function kashimop_count_all_images() {
    return count( kashimop_get_all_image_ids() );
}

function kashimop_swap_ext( $file, $new_ext ) {
    $info = pathinfo( $file );
    return $info['dirname'] . '/' . $info['filename'] . '.' . $new_ext;
}

/* ============================================================
 * ORIGINAL RESTORE HELPERS
 * ============================================================ */

/**
 * Deletes only generated WebP/AVIF derivatives for one attachment.
 * The original JPG/PNG and WordPress thumbnail source files are never removed.
 *
 * @param int $attachment_id Attachment ID.
 * @return int Number of derivative files deleted.
 */
function kashimop_delete_attachment_derivatives( $attachment_id ) {
    $file = get_attached_file( $attachment_id );
    if ( ! $file || ! file_exists( $file ) ) {
        kashimop_log_error( "Attachment {$attachment_id} file missing." );
        return 0;
    }

    $deleted = kashimop_delete_derivatives( $file );

    $metadata = wp_get_attachment_metadata( $attachment_id );
    if ( empty( $metadata['sizes'] ) || ! is_array( $metadata['sizes'] ) ) {
        return $deleted;
    }

    $base_dir = dirname( $file );
    foreach ( $metadata['sizes'] as $size_data ) {
        if ( empty( $size_data['file'] ) ) continue;
        $size_file = $base_dir . '/' . $size_data['file'];
        if ( file_exists( $size_file ) ) {
            $deleted += kashimop_delete_derivatives( $size_file );
        }
    }

    return $deleted;
}

/* ============================================================
 * CONVERSION LOGIC
 * ============================================================ */

function kashimop_convert_attachment_all_sizes( $attachment_id ) {
    $file = get_attached_file( $attachment_id );
    if ( ! $file || ! file_exists( $file ) ) {
        kashimop_log_error( "Attachment {$attachment_id} file missing." );
        return false;
    }

    kashimop_convert_single( $file );

    $metadata = wp_get_attachment_metadata( $attachment_id );
    if ( empty( $metadata['sizes'] ) ) return true;

    $base_dir = dirname( $file );
    foreach ( $metadata['sizes'] as $size_data ) {
        if ( empty( $size_data['file'] ) ) continue;
        $size_file = $base_dir . '/' . $size_data['file'];
        if ( file_exists( $size_file ) ) {
            kashimop_convert_single( $size_file );
        }
    }
    return true;
}

/**
 * Deletes existing WebP/AVIF derivatives for an attachment (all sizes)
 * and regenerates them from the untouched original files.
 *
 * @param int $attachment_id Attachment ID.
 * @return bool
 */
function kashimop_regenerate_attachment_all_sizes( $attachment_id ) {
    $file = get_attached_file( $attachment_id );
    if ( ! $file || ! file_exists( $file ) ) {
        kashimop_log_error( "Attachment {$attachment_id} file missing." );
        return false;
    }

    kashimop_delete_derivatives( $file );
    kashimop_convert_single( $file );

    $metadata = wp_get_attachment_metadata( $attachment_id );
    if ( empty( $metadata['sizes'] ) ) return true;

    $base_dir = dirname( $file );
    foreach ( $metadata['sizes'] as $size_data ) {
        if ( empty( $size_data['file'] ) ) continue;
        $size_file = $base_dir . '/' . $size_data['file'];
        if ( file_exists( $size_file ) ) {
            kashimop_delete_derivatives( $size_file );
            kashimop_convert_single( $size_file );
        }
    }
    return true;
}

/**
 * Deletes the .webp and .avif derivatives of a given original file, if present.
 * Never touches the original JPG/PNG itself.
 *
 * @param string $file Absolute path to the original image.
 */
function kashimop_delete_derivatives( $file ) {
    $deleted = 0;
    foreach ( array( 'webp', 'avif' ) as $ext ) {
        $derivative = kashimop_swap_ext( $file, $ext );
        if ( file_exists( $derivative ) && @unlink( $derivative ) ) { // phpcs:ignore
            $deleted++;
        }
    }
    return $deleted;
}

function kashimop_classify_image( $file ) {
    static $cache = array();
    if ( isset( $cache[ $file ] ) ) return $cache[ $file ];

    $result = 'photo';
    $type   = wp_check_filetype( $file );
    if ( empty( $type['type'] ) ) return $cache[ $file ] = $result;

    if ( 'image/png' === $type['type'] && kashimop_png_has_alpha( $file ) ) {
        return $cache[ $file ] = 'graphic';
    }

    if ( extension_loaded( 'imagick' ) ) {
        try {
            $image = new Imagick( $file );
            $image->thumbnailImage( 64, 64, true );
            $colors = (int) $image->getImageColors();
            $alpha  = false;
            try { $alpha = (bool) $image->getImageAlphaChannel(); } catch ( Throwable $e ) {}
            $image->clear();
            $image->destroy();

            // A small color palette is a strong signal for logos, UI artwork,
            // illustrations and other graphic assets. Transparent images are
            // already treated as graphics above.
            if ( $alpha || ( $colors > 0 && $colors <= 256 ) ) {
                $result = 'graphic';
            }
        } catch ( Throwable $e ) {
            kashimop_log_error( "Smart image classification failed for {$file}: " . $e->getMessage() );
        }
    }

    return $cache[ $file ] = $result;
}

function kashimop_convert_single( $file ) {
    if ( ! file_exists( $file ) ) return false;

    $info = pathinfo( $file );
    if ( ! isset( $info['extension'] ) || ! in_array( strtolower( $info['extension'] ), array( 'jpg', 'jpeg', 'png' ), true ) ) {
        return false;
    }

    $settings = kashimop_get_settings();
    $webp = $info['dirname'] . '/' . $info['filename'] . '.webp';
    $avif = $info['dirname'] . '/' . $info['filename'] . '.avif';
    $extension = strtolower( $info['extension'] );
    $classification = 'photo';

    if ( ! empty( $settings['smart_content_detection'] ) ) {
        $classification = kashimop_classify_image( $file );
    }

    $webp_quality = ! empty( $settings['smart_content_detection'] )
        ? ( 'graphic' === $classification ? (int) $settings['smart_graphics_webp_quality'] : (int) $settings['smart_photo_webp_quality'] )
        : (int) $settings['webp_quality'];

    $avif_quality = ! empty( $settings['smart_content_detection'] )
        ? ( 'graphic' === $classification ? (int) $settings['smart_graphics_avif_quality'] : (int) $settings['smart_photo_avif_quality'] )
        : (int) $settings['avif_quality'];

    $webp_mode = 'quality';
    if ( 'png' === $extension ) {
        $webp_mode = $settings['png_webp_mode'];
        if ( 'smart' === $webp_mode && ! kashimop_png_has_alpha( $file ) ) {
            $webp_mode = 'quality';
        }
        if ( 'smart' === $webp_mode && 'graphic' === $classification && kashimop_png_has_alpha( $file ) ) {
            $webp_mode = 'lossless';
        }
    }

    $webp_ok = file_exists( $webp ) ? true : kashimop_encode_image( $file, $webp, 'webp', $webp_quality, $webp_mode );

    // AVIF generation is independent: if unsupported or disabled, WebP conversion is unaffected.
    $avif_ok = true;
    if ( ! empty( $settings['enable_avif'] ) && ! file_exists( $avif ) ) {
        if ( kashimop_avif_supported() ) {
            $avif_ok = kashimop_encode_image( $file, $avif, 'avif', $avif_quality );
        } else {
            kashimop_log_error( "AVIF enabled but unsupported on this server; skipped for {$file}." );
        }
    }

    return $webp_ok && $avif_ok;
}

/**
 * Applies JPEG EXIF orientation before metadata is stripped.
 *
 * @param Imagick $image Loaded Imagick image.
 * @return void
 */
function kashimop_imagick_auto_orient( $image ) {
    if ( ! $image instanceof Imagick ) return;

    $format = strtolower( (string) $image->getImageFormat() );
    if ( ! in_array( $format, array( 'jpg', 'jpeg' ), true ) ) return;

    try {
        if ( method_exists( $image, 'autoOrientImage' ) ) {
            $image->autoOrientImage();
        } elseif ( method_exists( $image, 'autoOrient' ) ) {
            $image->autoOrient();
        }
    } catch ( Throwable $e ) {
        kashimop_log_error( "Imagick EXIF orientation failed: " . $e->getMessage() );
    }
}

/**
 * Applies JPEG EXIF orientation when GD is used as the fallback encoder.
 *
 * @param resource|GdImage $img GD image resource/object.
 * @param string $file Absolute source image path.
 * @return resource|GdImage
 */
function kashimop_gd_auto_orient( $img, $file ) {
    if ( ! $img || ! function_exists( 'exif_read_data' ) ) return $img;

    $type = wp_check_filetype( $file );
    if ( empty( $type['type'] ) || 'image/jpeg' !== $type['type'] ) return $img;

    try {
        $exif = @exif_read_data( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        $orientation = isset( $exif['Orientation'] ) ? (int) $exif['Orientation'] : 1;

        switch ( $orientation ) {
            case 2:
                if ( function_exists( 'imageflip' ) ) imageflip( $img, IMG_FLIP_HORIZONTAL );
                break;
            case 3:
                $img = imagerotate( $img, 180, 0 );
                break;
            case 4:
                if ( function_exists( 'imageflip' ) ) imageflip( $img, IMG_FLIP_VERTICAL );
                break;
            case 5:
                if ( function_exists( 'imageflip' ) ) imageflip( $img, IMG_FLIP_HORIZONTAL );
                $img = imagerotate( $img, 270, 0 );
                break;
            case 6:
                $img = imagerotate( $img, 270, 0 );
                break;
            case 7:
                if ( function_exists( 'imageflip' ) ) imageflip( $img, IMG_FLIP_HORIZONTAL );
                $img = imagerotate( $img, 90, 0 );
                break;
            case 8:
                $img = imagerotate( $img, 90, 0 );
                break;
        }
    } catch ( Throwable $e ) {
        kashimop_log_error( "GD EXIF orientation failed for {$file}: " . $e->getMessage() );
    }

    return $img;
}

/**
 * Detects whether a PNG has an alpha channel.
 *
 * Used by the Smart PNG → WebP mode to protect transparent artwork from lossy compression.
 *
 * @param string $file Absolute source image path.
 * @return bool
 */
function kashimop_png_has_alpha( $file ) {
    static $cache = array();
    $cache_key = (string) $file;
    if ( array_key_exists( $cache_key, $cache ) ) return $cache[ $cache_key ];

    if ( ! file_exists( $file ) || 'image/png' !== wp_check_filetype( $file )['type'] ) {
        return $cache[ $cache_key ] = false;
    }

    if ( extension_loaded( 'imagick' ) ) {
        try {
            $image = new Imagick( $file );
            $alpha = $image->getImageAlphaChannel();
            $image->clear();
            $image->destroy();
            return $cache[ $cache_key ] = (bool) $alpha;
        } catch ( Throwable $e ) {
            kashimop_log_error( "PNG alpha detection failed for {$file}: " . $e->getMessage() );
        }
    }

    // GD fallback: detect an alpha-capable PNG without modifying the source.
    if ( function_exists( 'imagecreatefrompng' ) ) {
        $img = @imagecreatefrompng( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        if ( $img ) {
            $alpha = function_exists( 'imageistruecolor' ) && imageistruecolor( $img ) ? true : false;
            if ( function_exists( 'imagecolortransparent' ) && imagecolortransparent( $img ) >= 0 ) {
                $alpha = true;
            }
            imagedestroy( $img );
            return $cache[ $cache_key ] = $alpha;
        }
    }

    return $cache[ $cache_key ] = false;
}

function kashimop_encode_image( $file, $dest, $format, $quality, $mode = 'quality' ) {
    $success = false;

    // --- Try Imagick first ---
    if ( extension_loaded( 'imagick' ) ) {
        try {
            $image = new Imagick( $file );

            // Apply EXIF orientation BEFORE stripping metadata.
            kashimop_imagick_auto_orient( $image );

            // Preserve the source ICC color profile before stripping metadata.
            // This prevents color shifts in images prepared in Photoshop/Illustrator
            // and other color-managed workflows.
            $icc_profile = null;
            try {
                if ( $image->getImageProfiles( '*', false ) && $image->getImageProfile( 'icc' ) ) {
                    $icc_profile = $image->getImageProfile( 'icc' );
                }
            } catch ( Throwable $e ) {
                kashimop_log_error( "ICC profile read failed for {$file}: " . $e->getMessage() );
            }

            $image->setImageFormat( $format );

            // For PNG → WebP, lossless mode protects transparent logos, UI graphics,
            // illustrations and product artwork from ringing/edge artifacts.
            if ( 'webp' === $format && 'lossless' === $mode ) {
                try {
                    $image->setOption( 'webp:lossless', 'true' );
                } catch ( Throwable $e ) {
                    kashimop_log_error( "WebP lossless option failed for {$file}: " . $e->getMessage() );
                }
                $image->setImageCompressionQuality( 100 );
            } else {
                $image->setImageCompressionQuality( (int) $quality );
            }
            $image->stripImage(); // Removes EXIF/metadata after orientation is applied.

            // Restore only the ICC color profile after metadata stripping.
            if ( ! empty( $icc_profile ) ) {
                try {
                    $image->setImageProfile( 'icc', $icc_profile );
                } catch ( Throwable $e ) {
                    kashimop_log_error( "ICC profile restore failed for {$file}: " . $e->getMessage() );
                }
            }

            // Preserve PNG transparency.
            if ( $image->getImageAlphaChannel() ) {
                $image->setImageAlphaChannel( Imagick::ALPHACHANNEL_ACTIVATE );
            }

            $image->writeImage( $dest );
            $image->clear();
            $success = true;
        } catch ( Throwable $e ) {
            kashimop_log_error( "Imagick encode failed for {$file}: " . $e->getMessage() );
            $success = false;
        }
    }

    // --- Fallback to GD, with explicit function-exists checks ---
    if ( ! $success ) {
        if ( ! kashimop_gd_supports( $format ) ) {
            kashimop_log_error( "GD does not support '{$format}' encoding on this server; skipped {$file}." );
            return false;
        }

        $gd_function = 'image' . $format;
        $type = wp_check_filetype( $file );
        $img = false;

        if ( $type['type'] === 'image/jpeg' ) {
            $img = imagecreatefromjpeg( $file );
            if ( $img ) {
                $img = kashimop_gd_auto_orient( $img, $file );
            }
        } elseif ( $type['type'] === 'image/png' ) {
            $img = imagecreatefrompng( $file );
            if ( $img ) {
                imagepalettetotruecolor( $img );
                imagealphablending( $img, true );
                imagesavealpha( $img, true ); // Preserve PNG transparency.
            }
        }

        if ( $img ) {
            $gd_quality = ( 'webp' === $format && 'lossless' === $mode ) ? 100 : (int) $quality;
            $success = $gd_function( $img, $dest, $gd_quality );
            imagedestroy( $img );
        } else {
            kashimop_log_error( "GD could not read source image: {$file}" );
        }
    }

    return $success && file_exists( $dest );
}

/**
 * Determines whether an image is considered large for automatic optimization.
 *
 * Automatic optimization is skipped when the source exceeds either:
 * - 10 MiB file size, or
 * - 6000 px on width or height.
 *
 * Manual optimization is never blocked by this check.
 *
 * @param int $attachment_id Attachment ID.
 * @return array {
 *     Large-image information.
 *     @type bool   $is_large
 *     @type int    $bytes
 *     @type int    $width
 *     @type int    $height
 *     @type string $reason
 * }
 */
function kashimop_get_original_source_file( $attachment_id, $metadata = null ) {
    $attachment_id = absint( $attachment_id );
    $attached_file = get_attached_file( $attachment_id );

    if ( ! $attached_file ) {
        return '';
    }

    // During wp_generate_attachment_metadata, WordPress may have already created
    // a -scaled file, while the generated metadata has not yet been saved.
    // Use original_image from the metadata passed to this filter when available.
    if ( is_array( $metadata ) && ! empty( $metadata['original_image'] ) ) {
        $original_name = wp_basename( $metadata['original_image'] );
        $candidate     = trailingslashit( dirname( $attached_file ) ) . $original_name;

        if ( is_file( $candidate ) ) {
            return $candidate;
        }
    }

    // On later requests, WordPress exposes the true original image path.
    if ( function_exists( 'wp_get_original_image_path' ) ) {
        $original_file = wp_get_original_image_path( $attachment_id );
        if ( $original_file && is_file( $original_file ) ) {
            return $original_file;
        }
    }

    // Fallback for installations without original-image metadata.
    return is_file( $attached_file ) ? $attached_file : '';
}

function kashimop_get_large_image_info( $attachment_id, $metadata = null ) {
    static $cache = array();

    $attachment_id = absint( $attachment_id );
    $cache_key     = $attachment_id . ':' . ( is_array( $metadata ) && ! empty( $metadata['original_image'] ) ? wp_basename( $metadata['original_image'] ) : '' );

    if ( isset( $cache[ $cache_key ] ) ) {
        return $cache[ $cache_key ];
    }

    $info = array(
        'is_large' => false,
        'bytes'    => 0,
        'width'    => 0,
        'height'   => 0,
        'reason'   => '',
        'file'     => '',
    );

    $file = kashimop_get_original_source_file( $attachment_id, $metadata );
    if ( ! $file || ! is_file( $file ) ) {
        return $cache[ $cache_key ] = $info;
    }

    $info['file']  = $file;
    $info['bytes'] = (int) filesize( $file );

    if ( function_exists( 'getimagesize' ) ) {
        $dimensions = @getimagesize( $file );
        if ( is_array( $dimensions ) ) {
            $info['width']  = isset( $dimensions[0] ) ? (int) $dimensions[0] : 0;
            $info['height'] = isset( $dimensions[1] ) ? (int) $dimensions[1] : 0;
        }
    }

    if ( $info['bytes'] > KASHIMOP_LARGE_IMAGE_MAX_BYTES ) {
        $info['is_large'] = true;
        $info['reason']   = 'file_size';
    } elseif (
        $info['width'] > KASHIMOP_LARGE_IMAGE_MAX_DIMENSION ||
        $info['height'] > KASHIMOP_LARGE_IMAGE_MAX_DIMENSION
    ) {
        $info['is_large'] = true;
        $info['reason']   = 'dimensions';
    }

    return $cache[ $cache_key ] = $info;
}

/* ============================================================
 * AUTO-CONVERT ON UPLOAD (after WP finishes generating thumbnails)
 * ============================================================ */

add_filter( 'wp_generate_attachment_metadata', function ( $metadata, $attachment_id ) {
    $settings = kashimop_get_settings();
    if ( empty( $settings['auto_convert'] ) ) return $metadata;

    $mime = get_post_mime_type( $attachment_id );
    if ( ! in_array( $mime, array( 'image/jpeg', 'image/png' ), true ) ) return $metadata;

    // Do not automatically optimize unusually large source images.
    // The original remains untouched and the user can optimize manually from Media Library.
    $large_info = kashimop_get_large_image_info( $attachment_id, $metadata );
    if ( ! empty( $large_info['is_large'] ) ) {
        kashimop_log_error(
            sprintf(
                'Automatic optimization skipped for large image %d (%s, %s). Manual optimization remains available.',
                $attachment_id,
                size_format( $large_info['bytes'] ),
                ( $large_info['width'] && $large_info['height'] )
                    ? $large_info['width'] . 'x' . $large_info['height']
                    : 'unknown dimensions'
            )
        );
        return $metadata;
    }

    try {
        kashimop_convert_attachment_all_sizes( $attachment_id );
    } catch ( Throwable $e ) {
        kashimop_log_error( "Auto-convert failed for attachment {$attachment_id}: " . $e->getMessage() );
    }

    return $metadata;
}, 20, 2 );

/* ============================================================
 * FRONT-END URL REPLACEMENT (no database changes)
 * ============================================================ */

function kashimop_browser_accepts_avif() {
    return isset( $_SERVER['HTTP_ACCEPT'] ) && strpos( sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT'] ) ), 'image/avif' ) !== false;
}

/**
 * Resolves the smallest compatible optimized URL: AVIF/WebP -> original.
 *
 * AVIF is preferred only when it is actually smaller than WebP. If WebP is
 * smaller, it is served instead. Browsers that do not advertise AVIF support
 * always receive WebP when available. The original JPG/PNG remains the final
 * fallback.
 */
function kashimop_resolve_optimized_url( $url ) {
    // Cache the result for the lifetime of the current PHP request.
    // The same image URL can pass through several WordPress filters during
    // one page render; without this cache each filter repeats file_exists().
    static $cache = array();
    static $upload_dir = null;

    if ( ! is_string( $url ) || '' === $url ) return false;

    // Query strings such as ?ver=123 and fragments are not part of the
    // physical filename. Resolve the optimized file from the URL path, then
    // preserve the original query string/fragment on the returned URL.
    $parts = wp_parse_url( $url );
    if ( false === $parts || empty( $parts['path'] ) ) return false;

    $path = $parts['path'];
    if ( ! preg_match( '/\.(jpg|jpeg|png)$/i', $path ) ) return false;

    $accepts_avif = kashimop_browser_accepts_avif();
    $cache_key    = (string) $accepts_avif . '|' . $url;

    if ( array_key_exists( $cache_key, $cache ) ) {
        return $cache[ $cache_key ];
    }

    if ( null === $upload_dir ) {
        $upload_dir = wp_upload_dir();
    }

    // Build the optimized path from the URL path only, so ?ver=... never
    // becomes part of the filesystem filename.
    $relative_path = null;
    $baseurl_path  = wp_parse_url( $upload_dir['baseurl'], PHP_URL_PATH );
    if ( $baseurl_path && 0 === strpos( $path, $baseurl_path ) ) {
        $relative_path = substr( $path, strlen( $baseurl_path ) );
    }

    // Only map uploads URLs to local filesystem paths. Do not attempt to
    // replace external/CDN image URLs that are outside the uploads base URL.
    if ( null === $relative_path ) {
        $cache[ $cache_key ] = false;
        return false;
    }

    $relative_dir  = ltrim( dirname( $relative_path ), '/' );
    $relative_file = basename( $relative_path );
    $extension     = pathinfo( $relative_file, PATHINFO_EXTENSION );
    $filename      = pathinfo( $relative_file, PATHINFO_FILENAME );

    if ( '' === $filename || '' === $extension ) {
        $cache[ $cache_key ] = false;
        return false;
    }

    $filesystem_dir = trailingslashit( $upload_dir['basedir'] );
    if ( '' !== $relative_dir && '.' !== $relative_dir ) {
        $filesystem_dir .= trailingslashit( $relative_dir );
    }

    $url_dir = trailingslashit( dirname( $path ) );
    if ( '/' === $url_dir ) {
        $url_dir = '';
    }

    $build_url = static function ( $new_ext ) use ( $parts, $url_dir, $filename ) {
        $optimized_path = $url_dir . $filename . '.' . $new_ext;
        $scheme = isset( $parts['scheme'] ) ? $parts['scheme'] . '://' : '';
        $authority = '';
        if ( isset( $parts['user'] ) ) {
            $authority .= $parts['user'];
            if ( isset( $parts['pass'] ) ) $authority .= ':' . $parts['pass'];
            $authority .= '@';
        }
        if ( isset( $parts['host'] ) ) {
            $authority .= $parts['host'];
        }
        if ( isset( $parts['port'] ) ) {
            $authority .= ':' . $parts['port'];
        }
        $optimized_url = $scheme . $authority . $optimized_path;
        if ( isset( $parts['query'] ) ) $optimized_url .= '?' . $parts['query'];
        if ( isset( $parts['fragment'] ) ) $optimized_url .= '#' . $parts['fragment'];
        return $optimized_url;
    };

    $avif_path = $filesystem_dir . $filename . '.avif';
    $webp_path = $filesystem_dir . $filename . '.webp';

    // Check both generated formats once and choose the smallest format that
    // the current browser can display. This avoids assuming AVIF is always
    // smaller; at some quality settings WebP can be the better delivery file.
    $avif_size = false;
    $webp_size = false;

    if ( $accepts_avif && file_exists( $avif_path ) ) {
        $size = @filesize( $avif_path );
        if ( false !== $size ) {
            $avif_size = (int) $size;
        }
    }

    if ( file_exists( $webp_path ) ) {
        $size = @filesize( $webp_path );
        if ( false !== $size ) {
            $webp_size = (int) $size;
        }
    }

    if ( false !== $avif_size && false !== $webp_size ) {
        return $cache[ $cache_key ] = ( $avif_size <= $webp_size )
            ? $build_url( 'avif' )
            : $build_url( 'webp' );
    }

    if ( false !== $avif_size ) {
        return $cache[ $cache_key ] = $build_url( 'avif' );
    }

    if ( false !== $webp_size ) {
        return $cache[ $cache_key ] = $build_url( 'webp' );
    }

    $cache[ $cache_key ] = false;
    return false; // Falls back to original JPG/PNG automatically (URL unchanged).
}

function kashimop_replace_html_images( $html ) {
    if ( empty( $html ) ) return $html;

    $html = preg_replace_callback( '/<img[^>]+src="([^"]+)"[^>]*>/i', function ( $matches ) {
        $url = $matches[1];
        $optimized = kashimop_resolve_optimized_url( $url );
        if ( $optimized ) return str_replace( $url, $optimized, $matches[0] );
        return $matches[0];
    }, $html );

    $html = kashimop_replace_background_images( $html );

    return $html;
}

/**
 * Replaces JPG/PNG URLs used in CSS background-image:url(...) declarations
 * (inline style attributes or <style> blocks), covering Elementor, WPBakery
 * and Impreza-generated markup that embeds images via CSS rather than <img>.
 *
 * @param string $html HTML fragment.
 * @return string
 */
function kashimop_replace_background_images( $html ) {
    if ( empty( $html ) || strpos( $html, 'background-image' ) === false ) {
        return $html;
    }

    return preg_replace_callback(
        '/background-image\s*:\s*url\((["\']?)([^)"\']+\.(?:jpg|jpeg|png)(?:\?[^)"\']*)?)\1\)/i',
        function ( $matches ) {
            $quote = $matches[1];
            $url   = $matches[2];
            $optimized = kashimop_resolve_optimized_url( $url );
            if ( $optimized ) {
                return 'background-image:url(' . $quote . $optimized . $quote . ')';
            }
            return $matches[0];
        },
        $html
    );
}

// Post content (classic + Gutenberg output, including inline background-image styles).
add_filter( 'the_content', 'kashimop_replace_html_images' );

// Core filter covering images rendered via wp_filter_content_tags() (Gutenberg-aware, WP 5.5+).
add_filter( 'wp_content_img_tag', function ( $filtered_image, $context, $attachment_id ) {
    return kashimop_replace_html_images( $filtered_image );
}, 10, 3 );

// Featured images.
add_filter( 'post_thumbnail_html', 'kashimop_replace_html_images' );

// Text/block widgets (covers many page builder widgets and Elementor/WPBakery output rendered as widgets).
add_filter( 'widget_text_content', 'kashimop_replace_html_images' );
add_filter( 'widget_block_content', 'kashimop_replace_html_images' );

// Generic attachment image src (covers most page builders and Gutenberg image blocks).
add_filter( 'wp_get_attachment_image_src', function ( $image ) {
    if ( ! $image ) return $image;
    $optimized = kashimop_resolve_optimized_url( $image[0] );
    if ( $optimized ) $image[0] = $optimized;
    return $image;
} );

// wp_get_attachment_image() attribute rewriting (src + srcset), for Gutenberg/WooCommerce/Impreza usage.
add_filter( 'wp_get_attachment_image_attributes', function ( $attr, $attachment, $size ) {
    if ( ! empty( $attr['src'] ) ) {
        $optimized = kashimop_resolve_optimized_url( $attr['src'] );
        if ( $optimized ) $attr['src'] = $optimized;
    }

    if ( ! empty( $attr['srcset'] ) ) {
        $parts = explode( ',', $attr['srcset'] );
        foreach ( $parts as $i => $part ) {
            $part = trim( $part );
            $pieces = preg_split( '/\s+/', $part );
            if ( ! empty( $pieces[0] ) ) {
                $optimized = kashimop_resolve_optimized_url( $pieces[0] );
                if ( $optimized ) {
                    $pieces[0] = $optimized;
                    $parts[ $i ] = implode( ' ', $pieces );
                }
            }
        }
        $attr['srcset'] = implode( ', ', $parts );
    }

    return $attr;
}, 10, 3 );

// Responsive srcset (core-generated, covers theme/page-builder responsive markup).
add_filter( 'wp_calculate_image_srcset', function ( $sources ) {
    foreach ( $sources as $width => $source ) {
        $optimized = kashimop_resolve_optimized_url( $source['url'] );
        if ( $optimized ) $sources[ $width ]['url'] = $optimized;
    }
    return $sources;
} );

// WooCommerce product images (no-op if WooCommerce is not active).
add_filter( 'woocommerce_single_product_image_thumbnail_html', 'kashimop_replace_html_images' );
add_filter( 'woocommerce_product_get_image', 'kashimop_replace_html_images' );
