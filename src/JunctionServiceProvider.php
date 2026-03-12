<?php

namespace Weap\Junction;

use Illuminate\Http\Request;
use Illuminate\Routing\PendingResourceRegistration;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Weap\Junction\Commands\CleanMediaTemporaryUploads;
use Weap\Junction\Extensions\RelationExtension;

class JunctionServiceProvider extends ServiceProvider
{
    /**
     * @return void
     */
    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../database/migrations' => database_path('migrations'),
        ], 'migrations');

        $this->publishes([
            __DIR__ . '/../config/junction.php' => config_path('junction.php'),
        ]);

        $this->commands([
            CleanMediaTemporaryUploads::class,
        ]);

        if (class_exists(Media::class) && config('junction.route.media.enabled', true)) {
            Route::middleware(config('junction.route.media.middleware', ['api']))
                ->prefix(config('junction.route.media.prefix', ''))
                ->group(__DIR__ . '/../routes/media_library.php');
        }

        $this->bootRouteMacros();

        $this->bootRequestMacros();

        $this->app->scoped(RelationExtension::class, fn () => new RelationExtension());

        $this->app->scoped(AttributeRelationCache::class, fn () => new AttributeRelationCache());
    }

    /**
     * @return void
     */
    protected function bootRouteMacros(): void
    {
        Route::macro('junctionResource', function ($name, $controller, array $options = []) {
            $defaults = ['index', 'indexPost', 'store', 'show', 'showPost', 'update', 'destroy', 'action'];

            $only = $options['only'] ?? $defaults;

            if (isset($options['except'])) {
                $only = array_diff($only, (array) $options['except']);
            }

            $registrar = new ResourceRegistrar($this);

            return new PendingResourceRegistration(
                $registrar,
                $name,
                $controller,
                array_merge(['only' => $only], $options)
            );
        });
    }

    /**
     * @return void
     */
    protected function bootRequestMacros(): void
    {
        Request::macro('getPluckFields', fn () => $this->input('pluck'));

        Request::macro('getAccessors', fn () => $this->input('appends'));

        Request::macro('getRelations', function () {
            $relations = $this->input('with');

            $accessors = $this->getAccessors();

            if (is_string($accessors)) {
                $accessors = [ $accessors ];
            }

            foreach ($accessors ?? [] as $accessor) {
                if (! Str::contains($accessor, '.')) {
                    continue;
                }

                $accessorRelation = Str::beforeLast($accessor, '.');

                if (is_string($relations)) {
                    $relations = [$relations];
                }

                if (! Arr::first($relations ?? [], fn ($relation) => Str::startsWith($relation, $accessorRelation))) {
                    $relations ??= [];
                    $relations[] = $accessorRelation;
                }
            }

            // Auto-include relation paths from dot-notation scopes (e.g. "products.colors" → "products").
            // This mirrors the accessor logic above so the user does not need to repeat
            // the relation in both `with` and `scopes`.
            $scopes = $this->input('scopes');

            foreach ($scopes ?? [] as $scope) {
                $scopeName = is_string($scope) ? $scope : ($scope['name'] ?? null);

                if (! $scopeName || ! Str::contains($scopeName, '.')) {
                    continue;
                }

                $scopeRelation = Str::beforeLast($scopeName, '.');

                if (is_string($relations)) {
                    $relations = [$relations];
                }

                if (! Arr::first($relations ?? [], fn ($relation) => Str::startsWith($relation, $scopeRelation))) {
                    $relations ??= [];
                    $relations[] = $scopeRelation;
                }
            }

            // Auto-include relation paths from dot-notation select columns (e.g. "products.colors" → "products").
            $selects = $this->input('select');

            foreach ((array) ($selects ?? []) as $select) {
                if (! Str::contains($select, '.')) {
                    continue;
                }

                $selectRelation = Str::beforeLast($select, '.');

                if (is_string($relations)) {
                    $relations = [$relations];
                }

                if (! Arr::first($relations ?? [], fn ($relation) => Str::startsWith($relation, $selectRelation))) {
                    $relations ??= [];
                    $relations[] = $selectRelation;
                }
            }

            return $relations;
        });
    }
}
