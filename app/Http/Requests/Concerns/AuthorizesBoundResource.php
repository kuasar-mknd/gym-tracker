<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Runs the ownership check before the rules, not after them.
 *
 * A FormRequest authorises, then validates; the controller runs after both. With
 * the ownership check left downstream of the rules, a malformed payload aimed at
 * someone else's row answered 422 while the same payload aimed at an id that does
 * not exist answered 404 — the very distinction #1418 is about, surviving in a
 * corner the status-code fix does not reach.
 *
 * The controllers keep their own `authorize()` call. It is the policy of record,
 * every other verb reaches it, and a request class is the wrong place for a rule
 * the whole application depends on.
 */
trait AuthorizesBoundResource
{
    /**
     * The model the URL named, found by type rather than by parameter name.
     *
     * The name differs between the web and API routes that share these request
     * classes — `template` against `workout_template` — and reading it by name
     * silently returned null on one of the two, which `can()` then refuses. The
     * routes concerned bind exactly one model, so its type identifies it.
     *
     * @param  class-string<Model>  $model
     */
    protected function boundResource(string $model): ?Model
    {
        foreach ($this->route()?->parameters() ?? [] as $parameter) {
            if ($parameter instanceof $model) {
                return $parameter;
            }
        }

        return null;
    }

    /**
     * @param  class-string<Model>  $model
     */
    protected function userMay(string $ability, string $model): bool
    {
        $resource = $this->boundResource($model);

        // No bound resource means the route did not name one — a store, or a
        // binding that has already 404ed. Nothing to authorise here; the
        // controller's own call stays in charge.
        if (! $resource instanceof Model) {
            return $this->user() !== null;
        }

        return $this->user()?->can($ability, $resource) ?? false;
    }
}
