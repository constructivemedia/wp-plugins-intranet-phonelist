<?php

namespace ConstructiveMedia\IntranetPhonelist;

use DateTimeImmutable;
use WP_Post;
use WP_Query;
use WP_Term;

/**
 * Public API for theme templates.
 *
 *     use ConstructiveMedia\IntranetPhonelist\Phonelist;
 *
 *     foreach (Phonelist::birthdays(days: 3) as $person) {
 *         echo $person->name . ' — ' . $person->birthday->format('l j F');
 *     }
 *
 *     $construction = Phonelist::staff(division: 'JWH', department: '20');
 */
final class Phonelist
{
    /**
     * Staff matching every given filter, sorted by name.
     *
     * @param string|null $name       Any word of the full name starts with this (case-insensitive), e.g. 'jan', 'cit', 'jane cit'.
     * @param string|null $division   Exact division ID, e.g. 'JWH'.
     * @param string|null $department Exact department code, e.g. '20'.
     * @return Staff[]
     */
    public static function staff(?string $name = null, ?string $division = null, ?string $department = null): array
    {
        $staff = array_values(array_filter(
            array_map(fn (WP_Post $post): Staff => self::toStaff($post), self::staffPosts()),
            fn (Staff $person): bool => self::matches($person, $name, $division, $department),
        ));
        usort($staff, fn (Staff $a, Staff $b): int => strcasecmp($a->name, $b->name));

        return $staff;
    }

    /**
     * @internal Public for tests/plan-check.php.
     */
    public static function matches(Staff $person, ?string $name = null, ?string $division = null, ?string $department = null): bool
    {
        $name = trim($name ?? '');

        // Any word of the full name starts with $name: 'jan', 'cit', 'jane cit', 'lima' (Santos Lima), 'jones' (Smith-Jones).
        return ($name === '' || preg_match('/(^|[\s\-])' . preg_quote($name, '/') . '/iu', $person->name))
            && (($division ?? '') === '' || $person->divisionId === $division)
            && (($department ?? '') === '' || $person->departmentCode === $department);
    }

    /**
     * The JWH Club page: levels in order (Lifetime, 40 … 10, then any others by name), each with its members sorted by name.
     * Empty levels are left out. Add people with no staff record (e.g. retired Lifetime members) with the 'intranet_phonelist_club' filter:
     *
     *     add_filter('intranet_phonelist_club', function (array $club): array {
     *         $club['lifetime-club']['members'][] = Staff::manual('John Monkhouse', company: 'JWH Group');
     *         return $club;
     *     });
     *
     * @return array<string, array{title: string, description: string, logo: ?string, members: Staff[]}> Keyed by level slug ('lifetime-club', '40-year-club' …).
     */
    public static function club(): array
    {
        $order = array_flip(array_keys(PostTypes::CLUB));
        $terms = get_terms(['taxonomy' => PostTypes::CLUB_TAXONOMY, 'hide_empty' => false]);
        usort($terms, fn (WP_Term $a, WP_Term $b): int => [$order[$a->slug] ?? PHP_INT_MAX, $a->name] <=> [$order[$b->slug] ?? PHP_INT_MAX, $b->name]);

        $club = [];
        foreach ($terms as $term) {
            $logo = (int) get_term_meta($term->term_id, 'logo', true);
            $club[$term->slug] = [
                'title' => $term->name,
                'description' => $term->description,
                'logo' => $logo ? (wp_get_attachment_image_url($logo, 'full') ?: null) : null,
                'members' => [],
            ];
        }
        foreach (self::staffPosts() as $post) {
            $person = self::toStaff($post);
            if (isset($club[$person->club])) {
                $club[$person->club]['members'][] = $person;
            }
        }

        $club = apply_filters('intranet_phonelist_club', $club);
        foreach ($club as &$group) {
            usort($group['members'], fn (Staff $a, Staff $b): int => strcasecmp($a->name, $b->name));
        }
        unset($group);

        return array_filter($club, fn (array $group): bool => $group['members'] !== []);
    }

    /**
     * Staff whose next birthday is within $days days (0 = today only), soonest first.
     * Excludes anyone who opted out in HR (hide_birthday) or has no date of birth.
     *
     * @return Staff[]
     */
    public static function birthdays(int $days = 7): array
    {
        $today = new DateTimeImmutable('today', wp_timezone());
        $birthdays = [];

        foreach (self::staffPosts() as $post) {
            if (get_post_meta($post->ID, 'hide_birthday', true) === '1') {
                continue;
            }
            $next = self::nextBirthday((string) get_post_meta($post->ID, 'date_of_birth', true), $today);
            if (!$next) {
                continue;
            }
            $daysUntil = $today->diff($next)->days;
            if ($daysUntil <= $days) {
                $birthdays[] = self::toStaff($post, $next, $daysUntil);
            }
        }

        usort($birthdays, fn (Staff $a, Staff $b): int => [$a->daysUntil, $a->name] <=> [$b->daysUntil, $b->name]);

        return $birthdays;
    }

    /**
     * Next occurrence (today or later) of a stored DOB (ACF Ymd, e.g. '19850905'), or null if empty.
     * Year of birth is ignored; 29 Feb falls on 28 Feb in non-leap years.
     *
     * @internal Public for tests/plan-check.php.
     */
    public static function nextBirthday(string $dob, DateTimeImmutable $today): ?DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Ymd', $dob);
        if (!$date) {
            return null;
        }
        [$month, $day] = [(int) $date->format('n'), (int) $date->format('j')];
        $on = fn (int $year): DateTimeImmutable => $today->setDate($year, $month, ($month === 2 && $day === 29 && !checkdate(2, 29, $year)) ? 28 : $day);

        $thisYear = $on((int) $today->format('Y'));

        return $thisYear >= $today ? $thisYear : $on((int) $today->format('Y') + 1);
    }

    /**
     * @return WP_Post[]
     */
    private static function staffPosts(): array
    {
        // Loads every staff post (~430) per call; add a transient or a DOB index if it shows up in profiling.
        $query = new WP_Query(['post_type' => PostTypes::STAFF, 'posts_per_page' => -1, 'post_status' => 'publish', 'no_found_rows' => true]);
        update_post_thumbnail_cache($query); // Load every photo in one go rather than one query per person.

        return $query->posts;
    }

    private static function toStaff(WP_Post $post, ?DateTimeImmutable $birthday = null, ?int $daysUntil = null): Staff
    {
        $meta = fn (string $key): string => trim((string) get_post_meta($post->ID, $key, true));
        $title = fn (string $link): string => (int) $meta($link) ? get_post_field('post_title', (int) $meta($link), 'raw') : '';
        $photoId = (int) get_post_thumbnail_id($post->ID);
        $club = get_the_terms($post, PostTypes::CLUB_TAXONOMY); // Cached by the staff query.
        $leave = DateTimeImmutable::createFromFormat('!Ymd', $meta('on_leave_until'), wp_timezone()) ?: null;

        return new Staff(
            id: $meta(PostTypes::SOURCE_ID),
            postId: $post->ID,
            name: PostTypes::displayName($meta('preferred_name'), $meta('first_name'), $meta('last_name')),
            firstName: $meta('preferred_name') !== '' ? $meta('preferred_name') : $meta('first_name'),
            lastName: $meta('last_name'),
            position: $meta('position'),
            email: $meta('email'),
            mobile: $meta('mobile'),
            phone: $meta('phone'),
            extension: $meta('extension'),
            divisionId: $meta('division_id'),
            division: $title('division'),
            company: $title('company'),
            departmentCode: $meta('department_code'),
            department: $meta('department'),
            onLeaveUntil: $leave && $leave >= new DateTimeImmutable('today', wp_timezone()) ? $leave : null,
            birthday: $birthday,
            daysUntil: $daysUntil,
            photoId: $photoId ?: null,
            photoUrl: $photoId ? (wp_get_attachment_image_url($photoId, 'full') ?: null) : null,
            club: is_array($club) ? $club[0]->slug : null,
        );
    }
}
