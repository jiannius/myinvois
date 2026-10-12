<?php

namespace Jiannius\Myinvois\Support;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Jiannius\Myinvois\Enums\Status;
use Jiannius\Myinvois\Exceptions\MyinvoisInFlightException;
use Jiannius\Myinvois\Models\MyinvoisDocument;

/**
 * The in-flight lock: one cache lock per parent model and environment, so the same
 * document cannot reach LHDN twice at once. No migration, no extra Status case.
 *
 * A submission takes the locks of all its parents (all or nothing) under one owner
 * token. The token can travel in a queue payload: the worker proves ownership with
 * ensure() and frees the locks with release().
 */
class SubmissionLock
{
    public const DEFAULT_SECONDS = 300;

    /**
     * Seconds a lock lives if nothing releases it.
     */
    public static function seconds() : int
    {
        $seconds = config('services.myinvois.lock_seconds');

        return is_numeric($seconds) && $seconds > 0 ? (int) $seconds : static::DEFAULT_SECONDS;
    }

    /**
     * The cache lock key for a parent in an environment.
     */
    public static function key(Model|array $parent, bool $preprod) : string
    {
        $reference = $parent instanceof Model ? ParentReference::make($parent) : $parent;

        return 'myinvois:submit:'.($preprod ? 'preprod' : 'prod').':'.$reference['type'].':'.$reference['id'];
    }

    /**
     * Take the lock of every parent, all or nothing, and return the owner token.
     *
     * @param  iterable<Model>  $parents
     *
     * @throws MyinvoisInFlightException when any lock is already held
     */
    public static function acquire(iterable $parents, bool $preprod) : string
    {
        $owner = bin2hex(random_bytes(16));
        $taken = [];

        foreach (static::unique($parents, $preprod) as $key => $parent) {
            if (static::store()->lock($key, static::seconds(), $owner)->get()) {
                $taken[] = $key;
                continue;
            }

            static::forget($taken, $owner);

            throw static::inFlight('This document is already being submitted to MyInvois. Please wait for it to finish.', $parent);
        }

        return $owner;
    }

    /**
     * Make sure the locks are still ours (they expire after the TTL): keep the ones we
     * hold, re-take the ones that lapsed, and refuse when someone else holds one.
     *
     * @param  iterable<Model>  $parents
     *
     * @throws MyinvoisInFlightException
     */
    public static function ensure(iterable $parents, bool $preprod, string $owner) : void
    {
        foreach (static::unique($parents, $preprod) as $key => $parent) {
            if (static::store()->restoreLock($key, $owner)->isOwnedByCurrentProcess()) continue;

            if (! static::store()->lock($key, static::seconds(), $owner)->get()) {
                throw static::inFlight('This document is already being submitted to MyInvois. Please wait for it to finish.', $parent);
            }
        }
    }

    /**
     * Free the locks held by the owner. Locks held by anyone else are left alone.
     *
     * @param  iterable<Model|array{type: string, id: mixed}>  $parents  models or references
     */
    public static function release(iterable $parents, bool $preprod, string $owner) : void
    {
        static::forget(array_keys(static::unique($parents, $preprod)), $owner);
    }

    /**
     * Refuse parents whose latest submission in this environment is SUBMITTED or VALID.
     * Call it while holding the lock, so two submitters cannot both pass the check.
     *
     * @param  iterable<Model>  $parents
     *
     * @throws MyinvoisInFlightException
     */
    public static function assertSubmittable(iterable $parents, bool $preprod) : void
    {
        $model = MyinvoisDocument::$useModel;

        foreach (static::unique($parents, $preprod) as $parent) {
            $latest = (new $model)->newQueryWithoutScopes()
                ->where('parent_type', $parent->getMorphClass())
                ->where('parent_id', $parent->getKey())
                ->when(
                    $preprod,
                    fn ($q) => $q->where('is_preprod', true),
                    fn ($q) => $q->where(fn ($q) => $q->where('is_preprod', false)->orWhereNull('is_preprod')),
                )
                ->orderByDesc('id')
                ->first();

            if ($latest?->status?->is(Status::SUBMITTED, Status::VALID)) {
                throw static::inFlight(
                    $latest->status->is(Status::VALID)
                        ? 'This document has already been submitted to MyInvois and is valid.'
                        : 'This document has already been submitted to MyInvois and is awaiting its result.',
                    $parent,
                );
            }
        }
    }

    protected static function inFlight(string $message, Model $parent) : MyinvoisInFlightException
    {
        return new MyinvoisInFlightException($message, [ParentReference::make($parent)]);
    }

    protected static function store() : Repository
    {
        return Cache::store(config('services.myinvois.lock_store'));
    }

    /**
     * @param  array<int, string>  $keys
     */
    protected static function forget(array $keys, string $owner) : void
    {
        foreach ($keys as $key) static::store()->restoreLock($key, $owner)->release();
    }

    /**
     * Parents keyed by their lock key, de-duplicated and in a stable order (so two
     * submitters always take locks in the same order).
     *
     * @param  iterable<Model|array{type: string, id: mixed}>  $parents
     * @return array<string, Model|array{type: string, id: mixed}>
     */
    protected static function unique(iterable $parents, bool $preprod) : array
    {
        $keyed = [];

        foreach ($parents as $parent) $keyed[static::key($parent, $preprod)] = $parent;

        ksort($keyed);

        return $keyed;
    }
}
