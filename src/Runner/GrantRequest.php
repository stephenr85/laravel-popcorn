<?php

namespace Rushing\Popcorn\Runner;

/**
 * The **requested** grant a transform declares in its {@see Manifest} `requests` block — an
 * honest *upper bound* on blast radius, never a floor (popcorn-runner ticket 05). It is a
 * {@see Grant} plus per-axis `required`/`optional` intent:
 *
 * - a denied **required** axis ⇒ fail-closed (the run is refused before it starts);
 * - a denied **optional** axis ⇒ degrade (the run proceeds without it).
 *
 * The kernel never *resolves* this — resolution (`effective = requested ∩ policy`) is host-side.
 * This VO only carries the declaration so the host resolver can read it.
 */
class GrantRequest
{
    /**
     * @param  list<GrantAxis>  $required  axes whose denial fails the run closed; any axis not listed degrades
     */
    public function __construct(
        public Grant $grant,
        public array $required = [],
    ) {}

    public function isRequired(GrantAxis $axis): bool
    {
        return in_array($axis, $this->required, strict: true);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $required = [];
        foreach ((array) ($data['required'] ?? []) as $axis) {
            $case = GrantAxis::tryFrom((string) $axis);
            if ($case !== null) {
                $required[] = $case;
            }
        }

        return new self(
            grant: Grant::fromArray($data),
            required: $required,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->grant->toArray() + [
            'required' => array_map(static fn (GrantAxis $a): string => $a->value, $this->required),
        ];
    }
}
