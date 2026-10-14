<?php

namespace ConstructiveMedia\IntranetPhonelist\Sync;

use RuntimeException;

/**
 * Staff photos from a folder of 'first_last_divcode.jpg' files, uploaded to the Media Library as each
 * staff member's featured image. Skipped entirely when INTRANET_PHONELIST_PHOTOS_DIR isn't set.
 */
final class Photos
{
    /** Post meta: the file a staff member's current photo came from (name|size|mtime), so unchanged photos are skipped. */
    public const SOURCE = '_iphl_photo_source';

    /** @var array<string, string>|null Match key => file path, built once per sync. */
    private ?array $index = null;

    public function __construct(private readonly string $dir)
    {
    }

    public static function fromConstants(): self
    {
        return new self(defined('INTRANET_PHONELIST_PHOTOS_DIR') ? (string) INTRANET_PHONELIST_PHOTOS_DIR : '');
    }

    public function enabled(): bool
    {
        return $this->dir !== '' && is_dir($this->dir);
    }

    public function directory(): string
    {
        return $this->dir;
    }

    /**
     * Give a staff post its photo. Returns 'added', 'replaced', 'missing' (no photo at all), or null (nothing to do).
     *
     * @param array<string, string> $row The staff member's synced Oracle row.
     */
    public function attach(int $postId, array $row): ?string
    {
        // Oracle has no photos yet. If it ever supplies one (e.g. a PHOTO_URL column), use it here and skip the folder.
        $file = $this->find($row);
        if ($file === null) {
            return has_post_thumbnail($postId) ? null : 'missing';
        }

        $fingerprint = basename($file) . '|' . filesize($file) . '|' . filemtime($file);
        if (has_post_thumbnail($postId) && get_post_meta($postId, self::SOURCE, true) === $fingerprint) {
            return null;
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        // media_handle_sideload() moves its input, so hand it a copy and leave the source folder untouched.
        $copy = wp_tempnam(basename($file));
        copy($file, $copy);
        $attachmentId = media_handle_sideload(['name' => basename($file), 'tmp_name' => $copy], $postId, get_the_title($postId));
        if (is_wp_error($attachmentId)) {
            @unlink($copy);
            throw new RuntimeException('Photo upload failed for ' . basename($file) . ': ' . $attachmentId->get_error_message());
        }

        $previous = (int) get_post_thumbnail_id($postId);
        set_post_thumbnail($postId, $attachmentId);
        update_post_meta($postId, self::SOURCE, $fingerprint);
        if ($previous) {
            wp_delete_attachment($previous, true);
        }

        return $previous ? 'replaced' : 'added';
    }

    /**
     * Delete a staff member's photo from the Media Library (used when they leave).
     */
    public static function detach(int $postId): void
    {
        $attachmentId = (int) get_post_thumbnail_id($postId);
        if ($attachmentId) {
            wp_delete_attachment($attachmentId, true);
        }
    }

    /**
     * File names to look for, best first: preferred name, first word of the first name, full first name.
     *
     * @return string[] Match keys, e.g. 'jane_citizen_jwh'.
     */
    public static function candidates(array $row): array
    {
        $part = fn (string $value): string => preg_replace('/[^a-z0-9-]/', '', strtolower($value));
        $firsts = [$row['PREF_NAME'], strtok(trim($row['NAME_FIRST']), ' ') ?: '', $row['NAME_FIRST']];

        return array_values(array_unique(array_filter(array_map(
            fn (string $first): string => $part($first) === '' ? '' : $part($first) . '_' . $part($row['NAME_LAST_CO']) . '_' . $part($row['DIV_CODE']),
            $firsts,
        ))));
    }

    /**
     * Match key for a file name: 'Emma Collins_WACB.jpg' → 'emma_collins_wacb'. Null for anything that isn't a .jpg/.jpeg.
     */
    public static function fileKey(string $filename): ?string
    {
        if (!preg_match('/^(.+?)(\.jpe?g)+$/i', $filename, $match)) {
            return null;
        }

        return preg_replace('/[^a-z0-9_-]/', '', str_replace(' ', '_', strtolower($match[1])));
    }

    private function find(array $row): ?string
    {
        if ($this->index === null) {
            // Top level only: subfolders (e.g. old photo sets) are ignored.
            $this->index = [];
            foreach (scandir($this->dir) ?: [] as $file) {
                $key = self::fileKey($file);
                if ($key !== null && is_file("$this->dir/$file")) {
                    $this->index[$key] = "$this->dir/$file";
                }
            }
        }

        foreach (self::candidates($row) as $key) {
            if (isset($this->index[$key])) {
                return $this->index[$key];
            }
        }

        return null;
    }
}
