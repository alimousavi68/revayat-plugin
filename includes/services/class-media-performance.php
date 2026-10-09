<?php
/** Web image derivatives; originals remain available for editorial/download use. */
if (!defined('ABSPATH')) { exit; }

class Revayat_Companion_Media_Performance {
    /** Keep the public callback compatible with the existing loader. */
    public static function prefer_webp_for_jpeg($formats) {
        if (wp_image_editor_supports(['mime_type' => 'image/webp'])) {
            $formats['image/jpeg'] = 'image/webp';
            $formats['image/png'] = 'image/webp';
        }
        return $formats;
    }

    /** Explicit, moderate quality for generated WebP; preserve other formats. */
    public static function quality($quality, $mime) {
        return 'image/webp' === $mime ? 82 : $quality;
    }

    /** GD needs truecolor PNG for WebP, including small palette-based uploads. */
    public static function image_editor(string $file) {
        $temporary = null;
        if (function_exists('imagecreatefrompng') && 'image/png' === wp_get_image_mime($file)) {
            $image = imagecreatefrompng($file);
            if ($image && !imageistruecolor($image)) {
                imagepalettetotruecolor($image);
                imagesavealpha($image, true);
                $temporary = wp_tempnam('revayat-palette.png');
                if ($temporary && !imagepng($image, $temporary)) { unlink($temporary); $temporary = null; }
            }
            if ($image) { imagedestroy($image); }
        }
        $editor = wp_get_image_editor($temporary ?: $file);
        if ($temporary) { unlink($temporary); }
        return $editor;
    }

    /** Small uploads need a WebP candidate even when no registered resize fits. */
    public static function ensure_web_full($metadata, $attachment_id) {
        $file = get_attached_file($attachment_id);
        if (!$file || !is_file($file) || !is_array($metadata) || empty($metadata['file']) || isset($metadata['sizes']['revayat-web-full'])) { return $metadata; }
        $info = wp_getimagesize($file);
        if (!$info || !in_array($info['mime'], ['image/jpeg', 'image/png'], true) || !wp_image_editor_supports(['mime_type' => 'image/webp'])) { return $metadata; }
        $editor = self::image_editor($file);
        if (is_wp_error($editor)) { return $metadata; }
        $editor->set_quality(82);
        $resized = $info[0] > 1920 ? $editor->resize(1920, 0, false) : true;
        if (is_wp_error($resized)) { return $metadata; }
        $target = dirname($file) . '/' . pathinfo($file, PATHINFO_FILENAME) . '-rvweb-' . substr(hash_file('sha256', $file), 0, 12) . '-full.webp';
        $saved = $editor->save($target, 'image/webp');
        if (!is_wp_error($saved) && wp_getimagesize($target)) {
            unset($saved['path']);
            $metadata['sizes']['revayat-web-full'] = $saved;
        }
        return $metadata;
    }

    /** Keep original downloads, but never put a heavy original back into srcset. */
    public static function web_srcset($sources, $size_array, $image_src, $image_meta, $attachment_id) {
        if (!is_array($sources) || empty($image_meta['sizes']['revayat-web-full'])) { return $sources; }
        $original = wp_get_attachment_url($attachment_id);
        $web = $image_meta['sizes']['revayat-web-full'];
        if (!$original || empty($web['file']) || empty($web['width'])) { return $sources; }
        foreach ($sources as $key => $source) {
            if ($source['url'] !== $original) { continue; }
            // A resized WebP has the same aspect ratio, but a different width descriptor.
            unset($sources[$key]);
            $width = (int) $web['width'];
            $sources[$width] = ['url' => trailingslashit(dirname($original)) . $web['file'], 'descriptor' => 'w', 'value' => $width];
        }
        return $sources;
    }

    /** Preserve full/download URL; use WebP when a requested named resize is absent. */
    public static function small_image_fallback($downsize, $attachment_id, $size) {
        if ($downsize || 'full' === $size || !is_string($size)) { return $downsize; }
        $meta = wp_get_attachment_metadata($attachment_id);
        if (!$meta || isset($meta['sizes'][$size]) || empty($meta['sizes']['revayat-web-full'])) { return $downsize; }
        $web = $meta['sizes']['revayat-web-full'];
        $url = wp_get_attachment_url($attachment_id);
        if (!$url) { return $downsize; }
        return [trailingslashit(dirname($url)) . $web['file'], (int) $web['width'], (int) $web['height'], true];
    }
}
