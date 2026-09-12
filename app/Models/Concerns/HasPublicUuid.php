<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/**
 * A non-enumerable identifier for URLs, without touching the primary key.
 *
 * `/customers/23/edit` tells anyone who sees it — a screenshot, a support
 * ticket, a shared link, a referrer header — roughly how many customers the
 * business has. Every model-bound route but /blog/{article} sits behind auth
 * and ownership is structural, so walking the range yields 404s rather than
 * data; what leaks is the count, not the rows.
 *
 * The primary key stays `bigint`. Making it a UUID would rewrite every foreign
 * key in the schema, fragment the B-tree indexes on the insert-heavy
 * performance tables, and break in-flight queued jobs, which serialise ids —
 * all to buy something a route key already gives.
 *
 * Binding deliberately accepts both forms. `getRouteKeyName()` means every URL
 * we generate carries the uuid, while a link built elsewhere from a numeric id
 * still resolves rather than 404ing. There are 77 places in the frontend that
 * pass `.id` to `route()`; without this, missing one during the sweep would
 * take a working page down. Accepting an id costs nothing, because the id was
 * never the thing protecting the row.
 *
 * UUIDv7 rather than v4: it is time-ordered, so the unique index stays dense
 * as rows are inserted.
 *
 * @property string $uuid
 */
trait HasPublicUuid
{
    /**
     * Populated by Model::performInsert(), not by a `creating` listener.
     *
     * The listener version worked everywhere except under a test that had
     * called `unsetEventDispatcher()` — which is static on Model, so one test
     * doing it for Customer silently disabled model events for every model for
     * the rest of the process, and campaigns came out with a null uuid and an
     * unroutable URL. Unique ids are set inside the insert itself and do not
     * care whether a dispatcher exists.
     *
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /**
     * Laravel gates setUniqueIds() on this property, not on uniqueIds() being
     * non-empty — declaring the columns alone does nothing.
     */
    public function initializeHasPublicUuid(): void
    {
        $this->usesUniqueIds = true;
    }

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * Resolve by uuid, or by id for links minted before the sweep.
     *
     * The numeric check is not cosmetic: Postgres will not compare a uuid
     * string against a bigint column, so a single query matching both would
     * throw rather than miss.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        if ($field !== null) {
            return parent::resolveRouteBinding($value, $field);
        }

        $column = is_numeric($value) ? $this->getKeyName() : 'uuid';

        return $this->where($column, $value)->first();
    }

    /**
     * Same, for a nested binding like /campaigns/{campaign}/strategies/{strategy}
     * where the child is scoped to the parent.
     */
    public function resolveChildRouteBinding($childType, $value, $field)
    {
        $relationship = $this->{Str::plural(Str::camel($childType))}();
        $child = $relationship->getRelated();

        if ($field !== null) {
            return $relationship->where($field, $value)->first();
        }

        $column = is_numeric($value) ? $child->getKeyName() : 'uuid';

        return $relationship->where($child->qualifyColumn($column), $value)->first();
    }
}
