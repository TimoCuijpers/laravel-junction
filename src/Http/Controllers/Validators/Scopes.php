<?php

namespace Weap\Junction\Http\Controllers\Validators;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Weap\Junction\Http\Controllers\Controller;

class Scopes
{
    /**
     * @param Controller $controller
     * @param array $scopes
     * @return array
     *
     * @throws ValidationException
     */
    public static function validate(Controller $controller, array $scopes): array
    {
        $scopeCollection = collect($scopes);

        if ($scopeCollection->isEmpty()) {
            return [];
        }

        $filteredScopes = $scopeCollection->filter(function ($scope) use ($controller) {
            return app($controller->model)::query()->hasNamedScope($scope['name']);
        });

        if ($filteredScopes->count() !== $scopeCollection->count()) {
            throw ValidationException::withMessages([
                'scopes' => 'Invalid scopes',
            ]);
        }

        return $scopeCollection->toArray();
    }

    /**
     * Validate scopes that target a specific relation.
     * Each scope must have a 'relation' key with a dot-notation relation path.
     *
     * @param Controller $controller
     * @param array $scopes
     * @return array
     *
     * @throws ValidationException
     */
    public static function validateForRelations(Controller $controller, array $scopes): array
    {
        $scopeCollection = collect($scopes);

        if ($scopeCollection->isEmpty()) {
            return [];
        }

        $filteredScopes = $scopeCollection->filter(function ($scope) use ($controller) {
            $relationPath = Str::beforeLast($scope['name'], '.');
            $scopeName = Str::afterLast($scope['name'], '.');

            $relatedModelClass = static::resolveRelatedModel($controller->model, $relationPath);

            if (! $relatedModelClass) {
                return false;
            }

            return app($relatedModelClass)::query()->hasNamedScope($scopeName);
        });

        if ($filteredScopes->count() !== $scopeCollection->count()) {
            throw ValidationException::withMessages([
                'scopes' => 'Invalid scopes',
            ]);
        }

        return $scopeCollection->toArray();
    }

    /**
     * Resolve the related model class by traversing a dot-notation relation path
     * starting from the given model class.
     *
     * @param class-string $modelClass
     * @param string $relationPath  Dot-notation path, e.g. "products.category"
     * @return class-string|null
     */
    protected static function resolveRelatedModel(string $modelClass, string $relationPath): ?string
    {
        $parts = explode('.', $relationPath);
        $currentModel = app($modelClass);

        foreach ($parts as $part) {
            if (! method_exists($currentModel, $part)) {
                return null;
            }

            $relation = $currentModel->$part();
            $currentModel = app($relation->getRelated()::class);
        }

        return $currentModel::class;
    }
}
