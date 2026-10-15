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
        return self::nameMatches($person->name, $name)
            && (($division ?? '') === '' || $person->divisionId === $division)
            && (($department ?? '') === '' || $person->departmentCode === $department);
    }

    /**
     * Any word of $name starts with $search (case-insensitive): 'jan', 'cit', 'jane cit', 'lima' (Santos Lima),
     * 'jones' (Smith-Jones). An empty search matches everyone.
     */
    public static function nameMatches(string $name, ?string $search): bool
    {
        $search = trim($search ?? '');

        return $search === '' || preg_match('/(^|[\s\-])' . preg_quote($search, '/') . '/iu', $name) === 1;
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
            $next = get_post_meta($post->ID, 'hide_birthday', true) === '1' ? null : self::nextOccurrence((string) get_post_meta($post->ID, 'date_of_birth', true), $today);
            if ($next && $today->diff($next)->days <= $days) {
                $birthdays[] = self::toStaff($post, birthday: $next, daysUntil: $today->diff($next)->days);
            }
        }

        return self::soonestFirst($birthdays);
    }

    /**
     * Staff whose next work anniversary (a whole year or more since start_date) is within $days days, soonest first.
     *
     * @return Staff[] With anniversary, years and daysUntil set.
     */
    public static function anniversaries(int $days = 7): array
    {
        $today = new DateTimeImmutable('today', wp_timezone());
        $anniversaries = [];

        foreach (self::staffPosts() as $post) {
            $start = (string) get_post_meta($post->ID, 'start_date', true);
            $next = self::nextOccurrence($start, $today);
            $years = $next ? (int) $next->format('Y') - (int) substr($start, 0, 4) : 0;
            if ($years >= 1 && $today->diff($next)->days <= $days) {
                $anniversaries[] = self::toStaff($post, daysUntil: $today->diff($next)->days, anniversary: $next, years: $years);
            }
        }

        return self::soonestFirst($anniversaries);
    }

    /**
     * Staff on leave (their return date is still ahead), soonest back first.
     *
     * @return Staff[]
     */
    public static function onLeave(): array
    {
        $away = array_values(array_filter(self::staff(), fn (Staff $person): bool => $person->onLeaveUntil !== null));
        usort($away, fn (Staff $a, Staff $b): int => [$a->onLeaveUntil, $a->name] <=> [$b->onLeaveUntil, $b->name]);

        return $away;
    }

    /**
     * The people who report to a staff member (by Data Hub id), sorted by name.
     *
     * @return Staff[]
     */
    public static function team(string $id): array
    {
        return array_values(array_filter(self::staff(), fn (Staff $person): bool => $person->reportsTo === $id));
    }

    /**
     * A staff member's manager (by Data Hub id), or null.
     */
    public static function manager(string $id): ?Staff
    {
        $byId = array_column(self::staff(), null, 'id');

        return $byId[$byId[$id]->reportsTo ?? ''] ?? null;
    }

    /**
     * Every division, sorted by name: ['id' (Data Hub id, or null), 'name', 'company', 'staff' => Staff[]].
     */
    public static function divisions(): array
    {
        $staff = [];
        foreach (self::staff() as $person) {
            $staff[$person->divisionId][] = $person;
        }

        return array_map(function (WP_Post $post) use ($staff): array {
            $id = (string) get_post_meta($post->ID, PostTypes::SOURCE_ID, true);
            $company = (int) get_post_meta($post->ID, 'company', true);

            return ['id' => $id ?: null, 'name' => $post->post_title, 'company' => $company ? get_post_field('post_title', $company, 'raw') : '', 'staff' => $staff[$id] ?? []];
        }, get_posts(['post_type' => PostTypes::DIVISION, 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC']));
    }

    /**
     * Every company, sorted by name: ['name', 'divisions' => (as divisions()), 'staff' => Staff[] across them, by name].
     */
    public static function companies(): array
    {
        $divisions = self::divisions();

        return array_map(function (WP_Post $post) use ($divisions): array {
            $theirs = array_values(array_filter($divisions, fn (array $division): bool => $division['company'] === $post->post_title));
            $staff = array_merge(...array_column($theirs, 'staff'));
            usort($staff, fn (Staff $a, Staff $b): int => strcasecmp($a->name, $b->name));

            return ['name' => $post->post_title, 'divisions' => $theirs, 'staff' => $staff];
        }, get_posts(['post_type' => PostTypes::COMPANY, 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC']));
    }

    /**
     * @param Staff[] $staff
     * @return Staff[] Sorted by daysUntil, then name.
     */
    private static function soonestFirst(array $staff): array
    {
        usort($staff, fn (Staff $a, Staff $b): int => [$a->daysUntil, $a->name] <=> [$b->daysUntil, $b->name]);

        return $staff;
    }

    /**
     * Next yearly occurrence (today or later) of a stored date (ACF Ymd, e.g. '19850905'), or null if empty.
     * The year is ignored; 29 Feb falls on 28 Feb in non-leap years. For birthdays and work anniversaries.
     *
     * @internal Public for tests/plan-check.php.
     */
    public static function nextOccurrence(string $ymd, DateTimeImmutable $today): ?DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Ymd', $ymd);
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

    private static function toStaff(WP_Post $post, ?DateTimeImmutable $birthday = null, ?int $daysUntil = null, ?DateTimeImmutable $anniversary = null, ?int $years = null): Staff
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
            reportsTo: $meta('reports_to'),
            onLeaveUntil: $leave && $leave >= new DateTimeImmutable('today', wp_timezone()) ? $leave : null,
            birthday: $birthday,
            daysUntil: $daysUntil,
            anniversary: $anniversary,
            years: $years,
            photoId: $photoId ?: null,
            photoUrl: $photoId ? (wp_get_attachment_image_url($photoId, 'full') ?: null) : null,
            club: is_array($club) ? $club[0]->slug : null,
        );
    }
}
