<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Read-your-writes with a read replica ("I see what I just changed").
 *
 * Writes go to the PRIMARY database; reads go to the REPLICA. The replica copies the
 * primary a little later (replication lag: usually a few ms, seconds under heavy load,
 * or much more if replication stops). Right after a write, a read from the replica
 * could still return the OLD data.
 *
 * How it is solved here (exact, not a guess based on time):
 *  1. After a write, we ask the primary for its GTID set (@@gtid_executed = the ids of
 *     ALL transactions committed so far, including ours) and keep it in Redis for that
 *     "scope" (e.g. "products" or "user:7").
 *  2. Before reading that scope from the replica, we ask the replica (instant, no waiting):
 *       SELECT GTID_SUBSET('<that set>', @@global.gtid_executed)
 *     1 = the replica already applied our write -> read from the replica (fast)
 *     0 = not yet                               -> read from the PRIMARY (correct)
 *  Once the replica has caught up, it is used again automatically, whatever the lag was.
 *
 * Scopes used in this project:
 *   "products"   after any product create / update / delete. Shared by everybody: it
 *                also protects the Redis cache from being refilled with old data.
 *   "user:{id}"  after that user's order is created or its status changes.
 *
 * Inside the SAME request, Laravel already reads from the primary after a write
 * ('sticky' => true in config/database.php). This class covers the NEXT requests.
 *
 * Without a replica (DB_READ_HOST = DB_WRITE_HOST, or SQLite in the tests) there is
 * nothing to do. Without GTIDs, a time window is used instead (read_your_writes_seconds).
 * If Redis cannot be reached we cannot know, so we read from the primary (always correct).
 */
final class ReadYourWrites
{
    /** Results of the replica check, once per scope per request. */
    private static array $checked = [];

    public static function remember(string $scope): void
    {
        if (! self::hasReplica()) {
            return;
        }

        try {
            $gtids = (string) (DB::connection()->selectOne('select @@global.gtid_executed as g', [], false)->g ?? '');
        } catch (Throwable) {
            $gtids = '';
        }

        try {
            // Kept for at most read_your_writes_seconds; normally the replica catches up in ms.
            Performance::store()->put(self::key($scope), $gtids === '' ? 'time' : $gtids, self::seconds());
            unset(self::$checked[$scope]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    public static function mustReadPrimary(string $scope): bool
    {
        if (! self::hasReplica()) {
            return false;
        }

        if (array_key_exists($scope, self::$checked)) {
            return self::$checked[$scope];
        }

        try {
            $marker = Performance::store()->get(self::key($scope));
        } catch (Throwable) {
            return self::$checked[$scope] = true;
        }

        if ($marker === null) {
            return self::$checked[$scope] = false;   // no recent write: the replica is fine
        }

        if ($marker === 'time') {
            return self::$checked[$scope] = true;    // no GTID: primary during the time window
        }

        try {
            // Asked on the REPLICA (read connection): has it executed every transaction of the marker?
            $applied = (int) DB::connection()->selectOne('select GTID_SUBSET(?, @@global.gtid_executed) as applied', [$marker])->applied;
        } catch (Throwable) {
            $applied = 0;
        }

        return self::$checked[$scope] = $applied !== 1;
    }

    /** Sends the query to the primary when the replica does not have the scope's last write yet. */
    public static function apply(Builder $query, string ...$scopes): Builder
    {
        foreach ($scopes as $scope) {
            if (self::mustReadPrimary($scope)) {
                return $query->useWritePdo();
            }
        }

        return $query;
    }

    /** For the tests / long-running processes. */
    public static function flush(): void
    {
        self::$checked = [];
    }

    public static function hasReplica(): bool
    {
        $config = config('database.connections.'.config('database.default'));

        return isset($config['read']['host'], $config['write']['host'])
            && ($config['read']['host'] != $config['write']['host']
                || ($config['read']['port'] ?? null) != ($config['port'] ?? null));
    }

    private static function seconds(): int
    {
        return max(1, (int) config('performance.read_your_writes_seconds', 60));
    }

    private static function key(string $scope): string
    {
        return 'ryw:'.$scope;
    }
}
