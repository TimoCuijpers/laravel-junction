<?php

namespace Weap\Junction\Http\Controllers\Filters;

use App\Events\DebugNotification;
use Exception;
use Illuminate\Database\Eloquent\Builder;
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


        foreach ($selects as $select) {
            self::traverse($query, $select, $selects);
        }
    }

    protected static function traverse(Builder $query, string $column, array $columns): void
    {
        if (Str::contains($column, '.')) {
            return;
        }

        $relationParts = explode('.', $column);

        $potentialRelations = request()?->getRelations();

        if ($potentialRelations && count($potentialRelations) > 0) {
            $potentialRelations = array_map(function ($relation) {
                return Str::after($relation, '.');
            }, $potentialRelations);

            $tableName = Str::after(Table::getRelationTableName($query->getModel()::class, $potentialRelations), '.');

            $query->addSelect($query->getModel()->getTable() . '.' . $tableName.'_id');
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
