<?php

namespace Weap\Junction\Http\Controllers\Filters;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Weap\Junction\Http\Controllers\Controller;
use Weap\Junction\Http\Controllers\Validators\Scopes as ScopesValidator;

class Scopes extends Filter
{
    /**
     * @param Controller $controller
     * @param Builder|Relation $query
     */
    public static function apply(Controller $controller, Builder|Relation $query): void
    {
        $scopes = request()?->input('scopes');

        if (! $scopes) {
            return;
        }

        // Only apply scopes without a dot in their name to the main query.
        // Scopes with dot-notation (e.g. "products.colors") are handled by the Relations filter.
        $mainScopes = array_values(array_filter($scopes, fn ($scope) => ! str_contains($scope['name'], '.')));

        if (empty($mainScopes)) {
            return;
        }

        $mainScopes = ScopesValidator::validate($controller, $mainScopes);

        foreach ($mainScopes as $scope) {
            $scopeName = $scope['name'];
            $params = $scope['params'] ?? [];
            $query->$scopeName(...$params);
        }
    }
}
