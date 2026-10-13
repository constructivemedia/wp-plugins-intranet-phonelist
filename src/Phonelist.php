<?php

namespace ConstructiveMedia\IntranetPhonelist;

use DateTimeImmutable;
use WP_Post;

/**
 * Public API for theme templates.
 *
 *     use ConstructiveMedia\IntranetPhonelist\Phonelist;
 *
 *     foreach (Phonelist::birthdays(days: 3) as $person) {
 *         echo $person->name . ' — ' . $person->birthday->format('l j F');
 *     }
 *
 *     $construction = Phonelist::staff(division: 'JWH', tag: '20');
 */
final class Phonelist
{
    /**
     * Staff matching every given filter, sorted by name. Also served at GET /wp-json/phonelist/v1/staff.
     *
     * @param string|null $name     Any word of the full name starts with this (case-insensitive), e.g. 'jan', 'cit', 'jane cit'.
     * @param string|null $division Exact DIV_CODE, e.g. 'JWH'.
     * @param string|null $tag      Exact department code, e.g. '20'.
     * @return Staff[]
     */
    public static function staff(?string $name = null, ?string $division = null, ?string $tag = null): array
    {
        $staff = array_values(array_filter(
            array_map(fn (WP_Post $post): Staff => self::toStaff($post), self::staffPosts()),
            fn (Staff $person): bool => self::matches($person, $name, $division, $tag),
        ));
        usort($staff, fn (Staff $a, Staff $b): int => strcasecmp($a->name, $b->name));

        return $staff;
    }

    /**
     * @internal Public for tests/plan-check.php.
     */
    public static function matches(Staff $person, ?string $name = null, ?string $division = null, ?string $tag = null): bool
    {
        $name = trim($name ?? '');

        // Any word of the full name starts with $name: 'jan', 'cit', 'jane cit', 'lima' (Santos Lima), 'jones' (Smith-Jones).
        return ($name === '' || preg_match('/(^|[\s\-])' . preg_quote($name, '/') . '/iu', $person->name))
            && (($division ?? '') === '' || $person->divisionCode === $division)
            && (($tag ?? '') === '' || $person->tag === $tag);
    }

    /**
     * Staff whose next birthday is within $days days (0 = today only), soonest first.
     * Excludes anyone who opted out in HR (HIDE_BIRTHDAY) or has no usable DOB.
     *
     * @return Staff[]
     */
    public static function birthdays(int $days = 7): array
    {
        $today = new DateTimeImmutable('today', wp_timezone());
        $birthdays = [];

        foreach (self::staffPosts() as $post) {
            $meta = fn (string $key): string => (string) get_post_meta($post->ID, $key, true);

            if (strtolower(trim($meta('hide_birthday'))) === 'y') {
                continue;
            }
            $next = self::nextBirthday($meta('dob'), $today);
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
        return get_posts(['post_type' => PostTypes::STAFF, 'numberposts' => -1, 'post_status' => 'publish']);
    }

    private static function toStaff(WP_Post $post, ?DateTimeImmutable $birthday = null, ?int $daysUntil = null): Staff
    {
        $meta = fn (string $key): string => (string) get_post_meta($post->ID, $key, true);
        $division = self::divisions()[$meta('div_code')] ?? ['name' => '', 'company' => ''];

        return new Staff(
            id: $meta('id'),
            postId: $post->ID,
            name: PostTypes::displayName($meta('pref_name'), $meta('name_first'), $meta('name_last_co')),
            firstName: trim($meta('pref_name')) !== '' ? $meta('pref_name') : $meta('name_first'),
            lastName: $meta('name_last_co'),
            position: $meta('position'),
            email: $meta('email'),
            mobile: $meta('mobile_no'),
            extension: $meta('ph_ext'),
            divisionCode: $meta('div_code'),
            division: $division['name'],
            company: $division['company'],
            tag: trim($meta('tag')),
            tagDescription: trim($meta('tag_description')),
            birthday: $birthday,
            daysUntil: $daysUntil,
        );
    }

    /**
     * @return array<string, array{name: string, company: string}> Keyed by DIV_CODE, cached per request.
     */
    private static function divisions(): array
    {
        static $divisions = null;

        if ($divisions === null) {
            $divisions = [];
            foreach (get_posts(['post_type' => PostTypes::DIVISION, 'numberposts' => -1]) as $post) {
                $companyId = (int) get_post_meta($post->ID, 'company', true);
                $divisions[(string) get_post_meta($post->ID, '_source_id', true)] = [
                    'name' => $post->post_title,
                    'company' => $companyId ? get_the_title($companyId) : '',
                ];
            }
        }

        return $divisions;
    }
}
