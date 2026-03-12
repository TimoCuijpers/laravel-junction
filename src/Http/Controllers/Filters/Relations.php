<?php

namespace Weap\Junction\Http\Controllers\Filters;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo as BelongsToRelation;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use ReflectionMethod;
use Weap\Junction\AttributeRelationCache;
use Weap\Junction\Extensions\RelationExtension;
use Weap\Junction\Http\Controllers\Controller;
use Weap\Junction\Http\Controllers\Validators\Relations as RelationsValidator;
use Weap\Junction\Http\Controllers\Validators\Scopes as ScopesValidator;

class Relations extends Filter
{
    /**
     * @param Controller $controller
     * @param Builder|Relation $query
     */
    public static function apply(Controller $controller, Builder|Relation $query): void
    {
        $relations = request()?->getRelations();

        RelationsValidator::validate($controller, $relations ?: []);

        $relations = collect($relations)->flip()->undot();

        $accessorRelations = static::getAccessorRelations(
            $query->getModel()::class,
            collect(request()?->getAccessors())->flip()->undot()->all()
        );

        $relationFilters = collect(app(RelationExtension::class)->call($controller->relations() ?? [], $controller))
            ->mapWithKeys(fn ($closure, $relation) => [$relation => is_callable($closure) ? $closure : null])
            ->filter()
            ->all();

        foreach (static::buildSelectRelationFilters() as $relation => $closure) {
            if (isset($relationFilters[$relation])) {
                $existing = $relationFilters[$relation];
                $relationFilters[$relation] = function ($query) use ($existing, $closure) {
                    $existing($query);
                    $closure($query);
                };
            } else {
                $relationFilters[$relation] = $closure;
            }
        }

        foreach (static::buildScopeRelationFilters($controller) as $relation => $closure) {
            if (isset($relationFilters[$relation])) {
                $existing = $relationFilters[$relation];
                $relationFilters[$relation] = function ($query) use ($existing, $closure) {
                    $existing($query);
                    $closure($query);
                };
            } else {
                $relationFilters[$relation] = $closure;
            }
        }

        $relations
            ->mergeRecursive($accessorRelations)
            ->each(function ($nestedRelations, $relation) use ($query, $relationFilters) {
                static::addWith($query, $relation, $nestedRelations, $relationFilters);
            });
    }

    /**
     * @param Builder|Relation $query
     * @param string $relation
     * @param array|Closure|int $nestedRelations
     * @param array $relationFilters
     * @return void
     */
    protected static function addWith(Builder|Relation $query, string $relation, array|Closure|int $nestedRelations, array $relationFilters): void
    {
        $relationFilters = array_filter(Arr::mapWithKeys($relationFilters, fn ($closure, $filterRelation) => Str::startsWith($filterRelation, $relation) ? [Str::after($filterRelation, $relation) => $closure] : [$filterRelation => null]));

        $query->with($relation, function (Builder|Relation $query) use ($nestedRelations, $relation, $relationFilters) {
            $nestedRelations = is_array($nestedRelations) ? $nestedRelations : [$nestedRelations];

            $currentRelationFilters = Arr::where($relationFilters, fn ($closure, $filterRelation) => ! Str::startsWith($filterRelation, '.'));

            foreach ($currentRelationFilters as $currentRelationFilter) {
                $currentRelationFilter($query);
            }

            $remainingRelationFilters = Arr::mapWithKeys($relationFilters, fn ($closure, $filterRelation) => Str::startsWith($filterRelation, '.') ? [Str::after($filterRelation, '.') => $closure] : [$filterRelation => null]);

            foreach (is_array($nestedRelations) ? $nestedRelations : [] as $nestedRelation => $nestedRelations) {
                if (is_string($nestedRelation)) {
                    static::addWith($query, $nestedRelation, $nestedRelations, $remainingRelationFilters);
                } elseif (is_callable($nestedRelations)) {
                    $nestedRelations($query);
                }
            }
        });
    }

    /**
     * Build relation-filter closures for scopes that target a specific relation.
     *
     * @param Controller $controller
     * @return array<string, Closure>
     */
    protected static function buildScopeRelationFilters(Controller $controller): array
    {
        $scopes = request()?->input('scopes');

        if (! $scopes) {
            return [];
        }

        $relationScopes = array_values(array_filter($scopes, fn ($scope) => str_contains($scope['name'], '.')));

        if (empty($relationScopes)) {
            return [];
        }

        ScopesValidator::validateForRelations($controller, $relationScopes);

        $filters = [];

        foreach ($relationScopes as $scope) {
            $relation = Str::beforeLast($scope['name'], '.');
            $scopeName = Str::afterLast($scope['name'], '.');
            $params = $scope['params'] ?? [];

            $newClosure = function ($query) use ($scopeName, $params) {
                // Ensure all base columns are still selected when the scope uses addSelect.
                // Inside a with() callback Laravel passes a Relation, so we need getBaseQuery().
                $baseQuery = $query instanceof Relation ? $query->getBaseQuery() : $query->getQuery();
                if ($baseQuery->columns === null) {
                    $query->addSelect($query->getModel()->getTable() . '.*');
                }
                $query->$scopeName(...$params);
            };

            if (isset($filters[$relation])) {
                $existing = $filters[$relation];
                $filters[$relation] = function ($query) use ($existing, $newClosure) {
                    $existing($query);
                    $newClosure($query);
                };
            } else {
                $filters[$relation] = $newClosure;
            }
        }

        return $filters;
    }

    /**
     * Build relation-filter closures for dot-notation select columns (e.g. "project_lines.description").
     * The last segment is the column name; everything before it is the dot-notation relation path.
     *
     * When a deeply-nested column is selected (e.g. "project_lines.product.description_internal"),
     * the parent relation's query (project_lines) must also include the FK column (products_id)
     * so that Eloquent can eager-load the child BelongsTo relation (product).
     *
     * @return array<string, Closure>
     */
    protected static function buildSelectRelationFilters(): array
    {
        $selects = request()?->input('select');

        if (! $selects) {
            return [];
        }

        $relationSelects = array_values(array_filter((array) $selects, fn ($col) => Str::contains($col, '.')));

        if (empty($relationSelects)) {
            return [];
        }

        // Collect requested columns per relation path.
        // e.g. 'project_lines' => ['description'],  'project_lines.product' => ['description_internal']
        $relationColumns = [];

        // Collect child BelongsTo relation names whose FK must be added to the parent select.
        // e.g. 'project_lines' => ['product']  (because project_lines needs products_id)
        $relationChildFks = [];

        foreach ($relationSelects as $select) {
            $relation = Str::beforeLast($select, '.');
            $column   = Str::afterLast($select, '.');

            $relationColumns[$relation][] = $column;

            // For a nested path like "project_lines.product", register that the
            // parent ("project_lines") needs the FK for the child ("product").
            if (Str::contains($relation, '.')) {
                $parentRelation    = Str::beforeLast($relation, '.');
                $childRelationName = Str::afterLast($relation, '.');

                $relationChildFks[$parentRelation][] = $childRelationName;
            }
        }

        // Also populate $relationChildFks from the 'with' relations.
        // When 'project_lines.product' is in 'with' and project_lines has an explicit
        // select (from any column), products_id must be injected so Eloquent can
        // eager-load the product relation.
        foreach ((array) (request()?->getRelations() ?? []) as $withRelation) {
            if (! Str::contains($withRelation, '.')) {
                continue;
            }

            $parentRelation    = Str::beforeLast($withRelation, '.');
            $childRelationName = Str::afterLast($withRelation, '.');

            $relationChildFks[$parentRelation][] = $childRelationName;
        }

        $filters = [];

        // Build one closure per relation that handles both column selects and child FKs.
        foreach ($relationColumns as $relation => $columns) {
            $childFks = array_unique($relationChildFks[$relation] ?? []);

            $newClosure = function ($query) use ($columns, $childFks) {
                $baseQuery = $query instanceof Relation ? $query->getBaseQuery() : $query->getQuery();

                if ($baseQuery->columns === null) {
                    // First explicit select for this relation: also add the key column that
                    // Laravel needs to match eager-loaded records back to their parents.
                    if ($query instanceof HasOneOrMany) {
                        // FK lives on the related model (e.g. project_lines.projects_id)
                        $query->addSelect($query->getQualifiedForeignKeyName());
                    } elseif ($query instanceof BelongsToRelation) {
                        // Owner key lives on the related model (e.g. products.id)
                        $query->addSelect($query->getQualifiedOwnerKeyName());
                    } else {
                        $query->addSelect($query->getModel()->getTable() . '.' . $query->getModel()->getKeyName());
                    }
                }

                foreach ($columns as $column) {
                    $query->addSelect($query->getModel()->getTable() . '.' . $column);
                }

                // Add the FK columns for any BelongsTo child relations so Eloquent can
                // eager-load them (e.g. add products_id to project_lines).
                foreach ($childFks as $childRelationName) {
                    try {
                        $modelClass    = $query->getModel()::class;
                        $childRelation = (new $modelClass)->$childRelationName();
                        if ($childRelation instanceof BelongsToRelation) {
                            $query->addSelect($childRelation->getQualifiedForeignKeyName());
                        }
                    } catch (\Throwable) {
                        // Relation does not exist on this model; skip silently.
                    }
                }
            };

            if (isset($filters[$relation])) {
                $existing = $filters[$relation];
                $filters[$relation] = function ($query) use ($existing, $newClosure) {
                    $existing($query);
                    $newClosure($query);
                };
            } else {
                $filters[$relation] = $newClosure;
            }
        }

        // For parent relations that have no direct column selects but still need child FKs
        // injected (e.g. when only 'with' drives FK injection or a scope adds explicit columns).
        foreach ($relationChildFks as $parentRelation => $childRelationNames) {
            if (isset($filters[$parentRelation])) {
                continue; // Already handled in the loop above.
            }

            $childFks = array_unique($childRelationNames);

            $filters[$parentRelation] = function ($query) use ($childFks) {
                $baseQuery = $query instanceof Relation ? $query->getBaseQuery() : $query->getQuery();

                // If columns is still null the query will use SELECT * which already includes
                // all FKs — no explicit injection needed.
                if ($baseQuery->columns === null) {
                    return;
                }

                foreach ($childFks as $childRelationName) {
                    try {
                        $modelClass    = $query->getModel()::class;
                        $childRelation = (new $modelClass)->$childRelationName();
                        if ($childRelation instanceof BelongsToRelation) {
                            $query->addSelect($childRelation->getQualifiedForeignKeyName());
                        }
                    } catch (\Throwable) {
                        // Relation does not exist on this model; skip silently.
                    }
                }
            };
        }

        return $filters;
    }

    /**
     * @param class-string $modelClass
     * @param array $accessors
     * @return array
     */
    protected static function getAccessorRelations(string $modelClass, array $accessors)
    {
        $relations = [];

        foreach ($accessors as $accessor => $nestedAccessors) {
            $accessor = Str::camel($accessor);

            if (! method_exists($modelClass, $accessor)) {
                continue;
            }

            // If the accessor is declared as a public method, we can not call it statically
            $attribute = ((new ReflectionMethod($modelClass, $accessor))->isPublic())
                ? (new $modelClass())->$accessor()
                : $modelClass::$accessor();

            if ($attribute instanceof Relation) {
                $relations[$accessor] ??= [];
                $relations[$accessor] += static::getAccessorRelations($attribute->getModel()::class, $nestedAccessors);

                continue;
            }

            if ($attribute instanceof Attribute && ($with = app(AttributeRelationCache::class)->get($modelClass, $accessor))) {
                foreach ($with as $key => $relation) {
                    $relationKey = is_callable($relation) ? $key : $relation;
                    $relationValue = is_callable($relation) ? [$relation] : [];

                    $relations[$relationKey] = [
                        ...($relations[$relationKey] ?? []),
                        ...$relationValue,
                    ];
                }
            }
        }

        return $relations;
    }
}
