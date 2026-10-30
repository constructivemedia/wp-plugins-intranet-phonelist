<?php

namespace ConstructiveMedia\IntranetPhonelist;

/**
 * A division as theme templates see it, from Phonelist::divisions() or a company's $divisions. Read-only.
 */
final class Division
{
    public function __construct(
        /** The Data Hub ID, e.g. 'ABN'; null if it has none. */
        public readonly ?string $id,
        public readonly int $postId,
        public readonly string $name,
        /** E.164, e.g. '+61897256355'; Phonelist::phone() formats it for showing. */
        public readonly string $phone,
        public readonly string $addressLine1,
        public readonly string $addressLine2,
        public readonly string $postcode,
        public readonly string $postalAddress,
        /** Its company, without $divisions and $staff (null); null if it has none. */
        public readonly ?Company $company,
        /** @var Staff[] By name. */
        public readonly array $staff,
    ) {
    }
}
