<?php

namespace ConstructiveMedia\IntranetPhonelist\Ingest;

/**
 * Staff photos, set as the featured image:
 * - when a staff member is created: from their photo_url, else from a 'first_last_divisionid.jpg' file in
 *   INTRANET_PHONELIST_PHOTOS_DIR (checked once);
 * - after that the photo is managed by hand, unless Data Hub sends a photo_url different from the last one used.
 */
final class Photos
{
    /** Post meta: the photo_url last downloaded, so the same URL is never fetched twice. */
    public const URL = '_iphl_photo_url';

    private const DOWNLOAD_TIMEOUT = 15;

    /** @var array<string, string>|null Match key => file path, built once per request. */
    private ?array $index = null;

    public function __construct(private readonly string $dir, private readonly Log $log)
    {
    }

    public static function fromConstants(Log $log): self
    {
        return new self(defined('INTRANET_PHONELIST_PHOTOS_DIR') ? (string) INTRANET_PHONELIST_PHOTOS_DIR : '', $log);
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
     * Give a staff post its photo after a write. Returns 'added', 'replaced', 'failed', or null (nothing to do).
     *
     * @param array<string, ?string> $data The staff member's stored values.
     */
    public function afterWrite(int $postId, array $data, bool $created): ?string
    {
        $url = $data['photo_url'] ?? null;
        if ($url !== null && $url !== '' && ($created || $url !== get_post_meta($postId, self::URL, true))) {
            return $this->fromUrl($postId, $url);
        }
        if ($created) {
            $file = $this->find($data);
            return $file === null ? null : $this->attach($postId, $file, basename($file), false);
        }

        return null;
    }

    /**
     * File names to look for, best first: preferred name, first word of the first name, full first name.
     *
     * @return string[] Match keys, e.g. 'jane_citizen_jwh'.
     */
    public static function candidates(?string $preferred, ?string $first, ?string $last, ?string $division): array
    {
        $part = fn (?string $value): string => preg_replace('/[^a-z0-9-]/', '', strtolower((string) $value));
        if ($part($last) === '' || $part($division) === '') {
            return [];
        }
        $firsts = [$preferred, strtok(trim((string) $first), ' ') ?: '', $first];

        return array_values(array_unique(array_filter(array_map(
            fn (?string $name): string => $part($name) === '' ? '' : $part($name) . '_' . $part($last) . '_' . $part($division),
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

    private function fromUrl(int $postId, string $url): ?string
    {
        $this->loadMediaFunctions();
        $file = download_url($url, self::DOWNLOAD_TIMEOUT);
        if (is_wp_error($file)) {
            $this->log->write('', 'system', 'warning', 'photo_failed', 'iphl_staff', (string) get_post_meta($postId, '_iphl_source_id', true), get_post_field('post_title', $postId, 'raw'), "Couldn't download $url: " . $file->get_error_message());
            return 'failed';
        }

        $name = basename((string) parse_url($url, PHP_URL_PATH)) ?: 'photo.jpg';
        $result = $this->attach($postId, $file, $name, true);
        if ($result !== 'failed') {
            update_post_meta($postId, self::URL, wp_slash($url));
        }

        return $result;
    }

    /**
     * Upload a file to the Media Library as the post's featured image, replacing any previous one.
     * $move: the file is a temporary download that may be moved; otherwise a copy is uploaded and the original kept.
     */
    private function attach(int $postId, string $file, string $name, bool $move): string
    {
        $this->loadMediaFunctions();
        $upload = $file;
        if (!$move) {
            $upload = wp_tempnam($name);
            copy($file, $upload);
        }

        $attachmentId = media_handle_sideload(['name' => $name, 'tmp_name' => $upload], $postId, get_post_field('post_title', $postId, 'raw'));
        if (is_wp_error($attachmentId)) {
            @unlink($upload);
            $this->log->write('', 'system', 'warning', 'photo_failed', 'iphl_staff', (string) get_post_meta($postId, '_iphl_source_id', true), get_post_field('post_title', $postId, 'raw'), "Couldn't add $name: " . $attachmentId->get_error_message());
            return 'failed';
        }

        $previous = (int) get_post_thumbnail_id($postId);
        set_post_thumbnail($postId, $attachmentId);
        if ($previous) {
            wp_delete_attachment($previous, true);
        }

        return $previous ? 'replaced' : 'added';
    }

    private function find(array $data): ?string
    {
        if (!$this->enabled()) {
            return null;
        }
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

        foreach (self::candidates($data['preferred_name'] ?? null, $data['first_name'] ?? null, $data['last_name'] ?? null, $data['division_id'] ?? null) as $key) {
            if (isset($this->index[$key])) {
                return $this->index[$key];
            }
        }

        return null;
    }

    private function loadMediaFunctions(): void
    {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
    }
}
