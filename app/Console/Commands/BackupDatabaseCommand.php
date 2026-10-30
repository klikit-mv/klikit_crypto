<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * Compressed database backup to the `backups` disk (point it at S3 or another off-site
 * store in production). Uses mysqldump --single-transaction so trading is not blocked.
 */
class BackupDatabaseCommand extends Command
{
    protected $signature = 'backup:database {--keep=14 : Days of backups to keep} {--connection= : Database connection (default: the app default)}';

    protected $description = 'Dump the database to the backups disk and prune old dumps';

    public function handle(): int
    {
        $connection = config('database.connections.'.($this->option('connection') ?: config('database.default')));
        $name = 'db-'.now()->utc()->format('Ymd-His').'.sql.gz';
        $tmp = storage_path('app/'.$name);

        if ($connection['driver'] === 'sqlite') {
            file_put_contents($tmp, gzencode((string) file_get_contents($connection['database']), 9));
        } else {
            $process = new Process([
                'mysqldump', '--single-transaction', '--quick', '--routines', '--no-tablespaces',
                '-h', $connection['host'], '-P', (string) $connection['port'], '-u', $connection['username'], $connection['database'],
            ], null, ['MYSQL_PWD' => (string) $connection['password']], null, 3600);
            $out = gzopen($tmp, 'wb9');
            $process->run(fn ($type, $buffer) => $type === Process::OUT ? gzwrite($out, $buffer) : $this->getOutput()->getErrorStyle()->write($buffer));
            gzclose($out);

            if (! $process->isSuccessful()) {
                @unlink($tmp);
                $this->error('mysqldump failed.');

                return self::FAILURE;
            }
        }

        $disk = Storage::disk('backups');
        $stream = fopen($tmp, 'rb');
        $disk->writeStream($name, $stream);
        fclose($stream);
        $size = filesize($tmp);
        unlink($tmp);

        $cutoff = now()->subDays((int) $this->option('keep'))->getTimestamp();
        foreach ($disk->files() as $file) {
            if (str_starts_with($file, 'db-') && $disk->lastModified($file) < $cutoff) {
                $disk->delete($file);
            }
        }

        $this->info("Backup $name written (".number_format($size / 1024, 1).' KiB).');

        return self::SUCCESS;
    }
}
