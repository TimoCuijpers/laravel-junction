<?php

namespace Weap\Junction\Http\Controllers\Filters;

use App\Events\DebugNotification;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;
use Weap\Junction\Http\Controllers\Controller;
use Weap\Junction\Http\Controllers\Helpers\Table;

class Select extends Filter
{
    /**
     * @param Controller $controller
     * @param Builder|Relation $query
     *
     * @throws Exception
     */
    public static function apply(Controller $controller, Builder|Relation $query): void
    {
        $selects = request()?->input('select');

        if (! $selects) {
            return;
        }

        $selects = (array) $selects;

        // Always include the primary key so hasMany and other relations can be loaded correctly.
        $query->addSelect($query->getModel()->getTable() . '.id');

        foreach ($selects as $select) {
            self::traverse($query, $select, $selects);
        }
    }

    protected static function traverse(Builder $query, string $column, array $columns): void
    {
        // When a dotted column like "product.name" is selected, automatically add
        // the FK (e.g. products_id) for that BelongsTo relation so it can be loaded.
        if (Str::contains($column, '.')) {
            $relationName = Str::before($column, '.');

            try {
                $relation = Table::getRelation($query->getModel()::class, [$relationName]);

                if ($relation instanceof BelongsTo) {
                    $query->addSelect($query->getModel()->getTable() . '.' . $relation->getForeignKeyName());
                }
            } catch (\Throwable) {
                // Relation does not exist on this model; skip silently.
            }

            return;
        }

        $relationParts = explode('.', $column);

        $potentialRelations = request()?->getRelations();

        if ($potentialRelations && count($potentialRelations) > 0) {
            // Only process top-level (non-nested) relations. Nested relations like
            // "project_lines.product" are not direct relations on the main model,
            // so we take only the root segment and deduplicate.
            $rootRelations = array_unique(array_map(
                fn ($relation) => Str::before($relation, '.'),
                $potentialRelations
            ));

            foreach ($rootRelations as $rootRelation) {
                try {
                    $relation = Table::getRelation($query->getModel()::class, [$rootRelation]);

                    // Only BelongsTo has its FK on the main model's table.
                    if (! ($relation instanceof BelongsTo)) {
                        continue;
                    }

                    $query->addSelect($query->getModel()->getTable() . '.' . $relation->getForeignKeyName());
                } catch (\Throwable) {
                    // Relation does not exist on this model; skip silently.
                }
            }
        }

        // Directly on the main model (no relation)
        if (count($relationParts) === 1) {
            $query->addSelect($query->getModel()->getTable() . '.' . $column);

            return;
        }

        // Treatment for columns in a relationship
//        $actualColumn = array_pop($relationParts);
//        $relationPath = implode('.', $relationParts);
//        $relation = Table::getRelationTableName($query->getModel()::class, $relationParts);
//
//        $query->addSelect($relation . '.' . $actualColumn);
    }
}
