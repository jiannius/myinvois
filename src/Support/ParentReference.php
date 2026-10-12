<?php

namespace Jiannius\Myinvois\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * A parent model as plain data (morph class + key) so it can ride in a queue payload or an
 * event instead of a serialised model, and be loaded again on the worker.
 */
class ParentReference
{
    /**
     * @return array{type: string, id: mixed}
     */
    public static function make(Model $parent) : array
    {
        return ['type' => $parent->getMorphClass(), 'id' => $parent->getKey()];
    }

    /**
     * Load the model a reference points to, or null when it no longer exists. Global scopes
     * are skipped: a worker has no tenant / branch context to satisfy them.
     *
     * @param  array{type: string, id: mixed}  $reference
     */
    public static function resolve(array $reference) : ?Model
    {
        $class = Relation::getMorphedModel($reference['type']) ?? $reference['type'];

        if (! is_string($class) || ! class_exists($class) || ! is_subclass_of($class, Model::class)) return null;

        return (new $class)->newQueryWithoutScopes()->find($reference['id']);
    }
}
