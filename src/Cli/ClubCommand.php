<?php

namespace ConstructiveMedia\IntranetPhonelist\Cli;

use ConstructiveMedia\IntranetPhonelist\PostTypes;
use WP_CLI;

/**
 * wp phonelist club-import <file> [--dry-run]
 *
 * Sets JWH Club levels from a list laid out like the club page: a level heading ('Lifetime Club', '40 Year Club' …),
 * an optional description line, then each member's name followed by their company. Names are matched to staff
 * records; anyone not found (leavers, retired members) is reported and skipped.
 */
final class ClubCommand
{
    public function register(): void
    {
        WP_CLI::add_command('phonelist club-import', $this, [
            'shortdesc' => 'Set JWH Club levels from a club page list (name and company lines under each level heading).',
            'synopsis' => [
                ['type' => 'positional', 'name' => 'file', 'description' => 'Text file with the list.'],
                ['type' => 'flag', 'name' => 'dry-run', 'optional' => true, 'description' => 'Report what would change without saving.'],
            ],
        ]);
    }

    public function __invoke(array $args, array $options): void
    {
        $text = @file_get_contents($args[0]);
        if ($text === false) {
            WP_CLI::error("Can't read {$args[0]}.");
        }
        $dryRun = !empty($options['dry-run']);
        PostTypes::seedClub();

        $index = self::staffIndex();
        $levels = array_keys(PostTypes::CLUB);
        $chosen = []; // post ID => [level, name]
        $unmatched = [];
        $ambiguous = [];
        $repeats = [];
        $spelling = [];

        foreach (self::parse($text) as $level => $members) {
            if (!term_exists($level, PostTypes::CLUB_TAXONOMY)) {
                WP_CLI::warning("No '$level' level under Phonelist → JWH Club; its " . count($members) . ' members were skipped.');
                continue;
            }
            foreach ($members as [$name, $company]) {
                // Preferred names first: 'Julian Walter' is Julian (preferred), not Jay (legal first name Julian).
                // Then the email (first.last@…), for surnames holding a business name.
                $key = self::key($name);
                $candidates = $index['preferred'][$key] ?? $index['legal'][$key] ?? $index['email'][$key] ?? [];
                if (!$candidates) {
                    // Last resort: an email one letter away (Lilly / lilley), only if exactly one person fits. Reported for checking.
                    $close = array_filter(array_keys($index['email']), fn (string $email): bool => levenshtein($key, $email) === 1);
                    if (count($close) === 1 && count($index['email'][$email = reset($close)]) === 1) {
                        $candidates = $index['email'][$email];
                        $spelling[] = sprintf('%s (%s) — %s → %s', $name, $company, $level, get_post_field('post_title', array_key_first($candidates), 'raw'));
                    }
                }
                if (count($candidates) > 1) {
                    // Same name twice: let the company on the list decide.
                    $candidates = array_filter($candidates, fn (array $person): bool => self::key($person['company']) !== '' && str_contains(self::key($company), self::key($person['company'])))
                        ?: $candidates;
                }
                if (!$candidates) {
                    $unmatched[] = "$name ($company) — $level";
                    continue;
                }
                if (count($candidates) > 1) {
                    $ambiguous[] = "$name ($company) — $level";
                    continue;
                }

                $postId = array_key_first($candidates);
                if (isset($chosen[$postId])) {
                    // Listed twice: keep the higher level (earlier in PostTypes::CLUB).
                    $repeats[] = "$name — {$chosen[$postId][0]} and $level";
                    if (array_search($level, $levels, true) > array_search($chosen[$postId][0], $levels, true)) {
                        continue;
                    }
                }
                $chosen[$postId] = [$level, $name];
            }
        }

        $changed = 0;
        foreach ($chosen as $postId => [$level]) {
            if (wp_get_object_terms($postId, PostTypes::CLUB_TAXONOMY, ['fields' => 'slugs']) !== [$level]) {
                $changed++;
                if (!$dryRun) {
                    wp_set_object_terms($postId, $level, PostTypes::CLUB_TAXONOMY);
                }
            }
        }

        $reports = [
            'Matched by an email one letter different (please check)' => $spelling,
            'No staff record (leavers, retired, spelling)' => $unmatched,
            'More than one staff record' => $ambiguous,
            'Listed more than once (higher level kept)' => $repeats,
        ];
        foreach ($reports as $title => $lines) {
            if ($lines) {
                WP_CLI::log("\n$title:");
                array_map(fn (string $line) => WP_CLI::log("  $line"), $lines);
            }
        }
        WP_CLI::success(sprintf('%s %d of %d matched staff (%d unmatched, %d ambiguous).', $dryRun ? 'Would update' : 'Updated', $changed, count($chosen), count($unmatched), count($ambiguous)));
    }

    /**
     * The club page text → level slug => [[name, company], …]. Headings: 'Lifetime Club', 'N Year Club'.
     * Description lines ('Our …', 'Celebrating …') are skipped; the rest alternate name, company.
     *
     * @return array<string, array<array{0: string, 1: string}>>
     */
    public static function parse(string $text): array
    {
        $list = [];
        $level = null;
        $pending = null;
        foreach (preg_split('/\R/', $text) as $line) {
            $line = trim($line);
            if ($line === '' || preg_match('/^(Our |Celebrating )/i', $line)) {
                continue;
            }
            if (preg_match('/^(Lifetime|\d+ Year) Club$/i', $line)) {
                $level = strtolower(str_replace(' ', '-', $line)); // The level's slug: '40 Year Club' → '40-year-club'.
                $pending = null;
                continue;
            }
            if ($level === null) {
                continue;
            }
            if ($pending === null) {
                $pending = $line;
            } else {
                $list[$level][] = [$pending, $line];
                $pending = null;
            }
        }

        return $list;
    }

    /**
     * Letters and digits only, lowercased: "Dianne O’Malley" and "dianne o'malley" match.
     */
    public static function key(string $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower(html_entity_decode($value)));
    }

    /**
     * ['preferred' | 'legal' | 'email'][name key] => [post ID => [company]] for every staff member:
     * preferred (or first, if none) name + surname; legal first name (full, and first word) + surname;
     * and the email's local part ('derrick.dsouza@…' → 'derrickdsouza'), for surnames holding a business name.
     */
    private static function staffIndex(): array
    {
        $index = ['preferred' => [], 'legal' => [], 'email' => []];
        foreach (get_posts(['post_type' => PostTypes::STAFF, 'numberposts' => -1]) as $post) {
            $meta = fn (string $key): string => trim((string) get_post_meta($post->ID, $key, true));
            $last = $meta('last_name');
            $person = ['company' => (string) get_post_field('post_title', (int) get_post_meta($post->ID, 'company', true), 'raw')];

            $index['preferred'][self::key(PostTypes::displayName($meta('preferred_name'), $meta('first_name'), $last))][$post->ID] = $person;
            if (str_contains($meta('email'), '@')) {
                $index['email'][self::key(strstr($meta('email'), '@', true))][$post->ID] = $person;
            }
            foreach (array_unique([$meta('first_name'), strtok($meta('first_name'), ' ') ?: '']) as $first) {
                if ($first !== '') {
                    $index['legal'][self::key("$first $last")][$post->ID] = $person;
                }
            }
        }

        return $index;
    }
}
