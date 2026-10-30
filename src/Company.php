<?php

namespace ConstructiveMedia\IntranetPhonelist;

/**
 * A company as theme templates see it, from Phonelist::companies() or a division's $company. Read-only.
 */
final class Company
{
    public function __construct(
        /** The Data Hub ID; null for a company created from a division's name. */
        public readonly ?string $id,
        public readonly int $postId,
        public readonly string $name,
        /** Its Featured image (e.g. the logo), set by hand, e.g. wp_get_attachment_image($company->photoId, 'medium'). Null without one. */
        public readonly ?int $photoId = null,
        /** Full-size image URL. Null without one. */
        public readonly ?string $photoUrl = null,
        /** @var Division[]|null By name. Null on a division's $company, which would otherwise loop back to that division. */
        public readonly ?array $divisions = null,
        /** @var Staff[]|null Everyone across its divisions, by name. Null on a division's $company. */
        public readonly ?array $staff = null,
    ) {
    }
}
