<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use Throwable;

/**
 * Requirement 3 (layer 4): proves the read replica works.
 *
 *   php artisan perf:replica
 *
 *  1. which MySQL server answers the writes and which one answers the reads (server_id)
 *  2. the replica has the same data (row counts on both)
 *  3. the replica is read-only (writes there are refused)
 *  4. replication lag: writes a value on the primary and measures how long until the
 *     replica shows it. This delay is WHY read-your-writes is needed.
 */
class PerfReplica extends Command
{
    protected $signature = 'perf:replica';

    protected $description = 'Requirement 3: show the primary / replica split, row counts and the replication lag';

    public function handle(): int
    {
        $connection = DB::connection();

        if ($connection->getDriverName() !== 'mysql') {
            $this->error('perf:replica needs MySQL (Docker).');

            return self::FAILURE;
        }

        $readHost = config('database.connections.mysql.read.host.0');
        $writeHost = config('database.connections.mysql.write.host.0');

        // Taken BEFORE any write: after a write Laravel's 'sticky' option would send the
        // reads of this process to the primary.
        $read = $connection->getReadPdo();
        $write = $connection->getPdo();

        $info = fn (PDO $pdo) => $pdo->query('select @@server_id as id, @@hostname as host, @@read_only as ro')->fetch(PDO::FETCH_ASSOC);
        $count = fn (PDO $pdo, string $table) => (int) $pdo->query("select count(*) from `$table`")->fetchColumn();

        $w = $info($write);
        $r = $info($read);

        $this->line('<info>1) Who answers what</info>');
        $this->table(['', 'config host', 'server_id', 'read_only'], [
            ['WRITES (INSERT/UPDATE/DELETE) -> primary', $writeHost, $w['id'], $w['ro'] ? 'yes' : 'no'],
            ['READS (SELECT) -> replica', $readHost, $r['id'], $r['ro'] ? 'yes' : 'no'],
        ]);

        if ($w['id'] === $r['id']) {
            $this->warn('Reads and writes go to the SAME server: no replica configured (DB_READ_HOST).');

            return self::SUCCESS;
        }

        $this->line('<info>2) Same data on both servers</info>');
        $this->table(['table', 'primary', 'replica'], collect(['users', 'products', 'orders', 'order_items'])
            ->map(fn ($t) => [$t, number_format($count($write, $t)), number_format($count($read, $t))])->all());

        $this->line('<info>3) The replica refuses writes from the application</info>');
        try {
            $read->exec("insert into `cache` (`key`, `value`, `expiration`) values ('replica-write-test', '1', 0)");
            $this->error('   the replica accepted a write (it should be read-only)');
        } catch (Throwable $e) {
            $this->line('   refused: '.Str::limit($e->getMessage(), 110));
        }

        $this->line('<info>4) Replication lag (write on the primary -> visible on the replica)</info>');
        $lags = [];
        for ($i = 0; $i < 5; $i++) {
            $value = Str::uuid()->toString();
            $statement = $write->prepare("replace into `cache` (`key`, `value`, `expiration`) values ('replica-probe', ?, 0)");
            $start = hrtime(true);
            $statement->execute([$value]);

            $check = $read->prepare("select `value` from `cache` where `key` = 'replica-probe'");
            do {
                $check->execute();
                $seen = $check->fetchColumn() === $value;
                $ms = (hrtime(true) - $start) / 1e6;
            } while (! $seen && $ms < 10000 && usleep(500) === null);

            $lags[] = $seen ? sprintf('%.1f ms', $ms) : 'not visible after 10 s (replica stopped or far behind)';
        }
        foreach ($lags as $i => $lag) {
            $this->line('   probe '.($i + 1).': '.$lag);
        }

        $this->newLine();
        $this->line('During that delay a read from the replica returns the OLD value. That is why, after a write,');
        $this->line('the reads of that data go to the PRIMARY until the replica has applied the write');
        $this->line('(checked with the write\'s GTID: App\Support\ReadYourWrites).');

        return self::SUCCESS;
    }
}
