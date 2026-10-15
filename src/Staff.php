<?php

namespace ConstructiveMedia\IntranetPhonelist;

use DateTimeImmutable;
use JsonSerializable;

/**
 * A staff member as theme templates and the club endpoint see them. Read-only.
 * Deliberately excludes date of birth, home phone and other HR fields.
 */
final class Staff implements JsonSerializable
{
    public function __construct(
        /** The Data Hub ID. */
        public readonly string $id,
        public readonly int $postId,
        public readonly string $name,
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly string $position,
        public readonly string $email,
        public readonly string $mobile,
        /** Direct phone number. */
        public readonly string $phone,
        /** VoIP extension. */
        public readonly string $extension,
        public readonly string $divisionId,
        public readonly string $division,
        public readonly string $company,
        /** Department code, e.g. '20'. */
        public readonly string $departmentCode,
        /** Department, e.g. 'Construction'. */
        public readonly string $department,
        /** Last day of their current leave; null when not on leave. */
        public readonly ?DateTimeImmutable $onLeaveUntil = null,
        /** Next birthday (no year of birth or age is exposed). Set by Phonelist::birthdays(). */
        public readonly ?DateTimeImmutable $birthday = null,
        /** Days until $birthday, 0 = today. Set by Phonelist::birthdays(). */
        public readonly ?int $daysUntil = null,
        /** Media Library attachment, e.g. wp_get_attachment_image($person->photoId, 'thumbnail'). Null without a photo. */
        public readonly ?int $photoId = null,
        /** Full-size photo URL. Null without a photo. */
        public readonly ?string $photoUrl = null,
        /** JWH Club level slug: 'lifetime-club', '40-year-club' … '10-year-club', or null. */
        public readonly ?string $club = null,
    ) {
    }

    /**
     * Someone with no staff record, e.g. a retired Lifetime Club member added with the 'intranet_phonelist_club' filter.
     */
    public static function manual(string $name, string $company = '', string $division = '', ?string $club = null): self
    {
        $parts = explode(' ', $name, 2);

        return new self(
            id: '',
            postId: 0,
            name: $name,
            firstName: $parts[0],
            lastName: $parts[1] ?? '',
            position: '',
            email: '',
            mobile: '',
            phone: '',
            extension: '',
            divisionId: '',
            division: $division,
            company: $company,
            departmentCode: '',
            department: '',
            club: $club,
        );
    }

    public function jsonSerialize(): array
    {
        $data = get_object_vars($this);
        $data['birthday'] = $this->birthday?->format('Y-m-d');
        $data['onLeaveUntil'] = $this->onLeaveUntil?->format('Y-m-d');

        return array_filter($data, fn ($value): bool => $value !== null);
    }
}
