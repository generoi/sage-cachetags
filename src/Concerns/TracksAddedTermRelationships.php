<?php

namespace Genero\Sage\CacheTags\Concerns;

/**
 * Which terms a wp_set_object_terms() call actually added.
 *
 * set_object_terms can't tell: with $append, core passes no old terms, so every
 * term given looks new. added_term_relationship fires only for the relationships
 * core inserts, so record those and read them back in the set_object_terms
 * handler. Bind onTermRelationshipAdded() to added_term_relationship (3 args).
 */
trait TracksAddedTermRelationships
{
    /**
     * Term relationships inserted by the wp_set_object_terms() call under way.
     *
     * @var array<int, array<string, int[]>>
     */
    protected array $addedTermRelationships = [];

    public function onTermRelationshipAdded(int $objectId, int $taxonomyId, string $taxonomy): void
    {
        $this->addedTermRelationships[$objectId][$taxonomy][] = $taxonomyId;
    }

    /**
     * The term_taxonomy_ids added to an object in a taxonomy since the last call,
     * forgetting them (and the object, once empty) so a long import doesn't
     * accumulate one entry per object.
     *
     * @return int[]
     */
    protected function takeAddedTermRelationships(int $objectId, string $taxonomy): array
    {
        $added = $this->addedTermRelationships[$objectId][$taxonomy] ?? [];
        unset($this->addedTermRelationships[$objectId][$taxonomy]);
        if (empty($this->addedTermRelationships[$objectId])) {
            unset($this->addedTermRelationships[$objectId]);
        }

        return $added;
    }
}
