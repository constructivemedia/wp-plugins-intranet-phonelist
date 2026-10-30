<?php

namespace ConstructiveMedia\IntranetPhonelist;

use ConstructiveMedia\IntranetPhonelist\Ingest\PhoneNumbers;
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
            $club[$term->slug] = [
                'title' => $term->name,
                'description' => $term->description,
                'logo' => self::imageUrl((int) get_term_meta($term->term_id, 'logo', true)),
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
            $next = self::nextBirthday($post->ID, $today);
            if ($next && $today->diff($next)->days <= $days) {
                $birthdays[] = self::toStaff($post, daysUntil: $today->diff($next)->days);
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
            [$next] = self::nextAnniversary($post->ID, $today);
            if ($next && $today->diff($next)->days <= $days) {
                $anniversaries[] = self::toStaff($post, daysUntil: $today->diff($next)->days);
            }
        }

        return self::soonestFirst($anniversaries);
    }

    /**
     * Staff on leave (on_leave_until, their last day of leave, is today or later), soonest back first.
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
        return $id === '' ? [] : array_values(array_filter(self::staff(), fn (Staff $person): bool => $person->reportsTo === $id));
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
     * Every division with its staff, sorted by name.
     *
     * @return Division[]
     */
    public static function divisions(): array
    {
        $staff = [];
        foreach (self::staff() as $person) {
            $staff[$person->divisionId][] = $person;
        }

        return array_map(function (WP_Post $post) use ($staff): Division {
            $meta = fn (string $key): string => trim((string) get_post_meta($post->ID, $key, true));
            $id = $meta(PostTypes::SOURCE_ID);
            $company = (int) $meta('company') ? get_post((int) $meta('company')) : null; // get_post(0) is the global post.

            return new Division(
                id: $id ?: null,
                postId: $post->ID,
                name: $post->post_title,
                phone: $meta('phone'),
                addressLine1: $meta('address_line1'),
                addressLine2: $meta('address_line2'),
                postcode: $meta('postcode'),
                postalAddress: $meta('postal_address'),
                company: $company ? self::toCompany($company) : null,
                staff: $id === '' ? [] : ($staff[$id] ?? []), // No id, no staff: '' would be everyone without a division.
            );
        }, get_posts(['post_type' => PostTypes::DIVISION, 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC']));
    }

    /**
     * Every company with its divisions and everyone across them, sorted by name.
     *
     * @return Company[]
     */
    public static function companies(): array
    {
        $divisions = self::divisions();

        return array_map(function (WP_Post $post) use ($divisions): Company {
            $theirs = array_values(array_filter($divisions, fn (Division $division): bool => $division->company?->postId === $post->ID));
            $staff = array_merge(...array_column($theirs, 'staff'));
            usort($staff, fn (Staff $a, Staff $b): int => strcasecmp($a->name, $b->name));

            return self::toCompany($post, $theirs, $staff);
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

    /**
     * Next birthday from date_of_birth, or null if unknown or hidden in HR (hide_birthday).
     */
    private static function nextBirthday(int $postId, DateTimeImmutable $today): ?DateTimeImmutable
    {
        return get_post_meta($postId, 'hide_birthday', true) === '1' ? null : self::nextOccurrence((string) get_post_meta($postId, 'date_of_birth', true), $today);
    }

    /**
     * Next work anniversary (a whole year or more since start_date) and the years it marks, or [null, null].
     *
     * @return array{0: ?DateTimeImmutable, 1: ?int}
     */
    private static function nextAnniversary(int $postId, DateTimeImmutable $today): array
    {
        $start = (string) get_post_meta($postId, 'start_date', true);
        $next = self::nextOccurrence($start, $today);
        $years = $next ? (int) $next->format('Y') - (int) substr($start, 0, 4) : 0;

        return $years >= 1 ? [$next, $years] : [null, null];
    }

    /**
     * @param Division[]|null $divisions
     * @param Staff[]|null $staff
     */
    private static function toCompany(WP_Post $post, ?array $divisions = null, ?array $staff = null): Company
    {
        $photoId = (int) get_post_thumbnail_id($post);

        return new Company(
            id: (string) get_post_meta($post->ID, PostTypes::SOURCE_ID, true) ?: null,
            postId: $post->ID,
            name: $post->post_title,
            photoId: $photoId ?: null,
            photoUrl: self::imageUrl($photoId),
            divisions: $divisions,
            staff: $staff,
        );
    }

    /**
     * A phone number for showing: '+61437793226' → '0437 793 226', '+61894647800' → '(08) 9464 7800'.
     * Numbers are stored as E.164, which is what a tel: link wants.
     */
    public static function phone(string $number): string
    {
        return PhoneNumbers::display($number);
    }

    /**
     * Position without its leading division code: 'PHM Sales Manager' → 'Sales Manager'. The code is often not
     * their own division's, so any three capitals go.
     *
     * @internal Public for tests/plan-check.php.
     */
    public static function jobTitle(string $position): string
    {
        return preg_replace('/^[A-Z]{3}\s+/', '', $position);
    }

    /**
     * Full-size URL of a Media Library image, or null.
     */
    private static function imageUrl(int $attachmentId): ?string
    {
        return $attachmentId ? (wp_get_attachment_image_url($attachmentId, 'full') ?: null) : null;
    }

    private static function toStaff(WP_Post $post, ?int $daysUntil = null): Staff
    {
        $meta = fn (string $key): string => trim((string) get_post_meta($post->ID, $key, true));
        $title = fn (string $link): string => (int) $meta($link) ? get_post_field('post_title', (int) $meta($link), 'raw') : '';
        $photoId = (int) get_post_thumbnail_id($post->ID);
        $club = get_the_terms($post, PostTypes::CLUB_TAXONOMY); // Cached by the staff query.
        $date = fn (string $key): ?DateTimeImmutable => DateTimeImmutable::createFromFormat('!Ymd', $meta($key), wp_timezone()) ?: null;
        $today = new DateTimeImmutable('today', wp_timezone());
        $leave = $date('on_leave_until');
        [$anniversary, $years] = self::nextAnniversary($post->ID, $today);

        return new Staff(
            id: $meta(PostTypes::SOURCE_ID),
            postId: $post->ID,
            name: PostTypes::displayName($meta('preferred_name'), $meta('first_name'), $meta('last_name')),
            firstName: $meta('preferred_name') !== '' ? $meta('preferred_name') : $meta('first_name'),
            lastName: $meta('last_name'),
            position: $meta('position'),
            jobTitle: (string) apply_filters('intranet_phonelist_job_title', self::jobTitle($meta('position')), $meta('position'), $post->ID),
            email: $meta('email'),
            mobile: $meta('mobile'),
            phone: $meta('phone'),
            extension: $meta('extension'),
            divisionId: $meta('division_id'),
            division: $title('division'),
            company: $title('company'),
            departmentCode: $meta('department_code'),
            department: $meta('department'),
            reportsTo: $meta('reports_to') === $meta(PostTypes::SOURCE_ID) ? '' : $meta('reports_to'), // Never their own manager.
            onLeaveUntil: $leave && $leave >= $today ? $leave : null,
            birthday: self::nextBirthday($post->ID, $today),
            daysUntil: $daysUntil,
            anniversary: $anniversary,
            years: $years,
            startDate: $date('start_date'),
            photoId: $photoId ?: null,
            photoUrl: self::imageUrl($photoId),
            club: is_array($club) ? $club[0]->slug : null,
        );
    }
}
