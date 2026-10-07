<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Services\Backup\BackupFailed;
use App\Services\Backup\BackupStorage;
use App\Services\Backup\FilesBackup;
use App\Support\AdminDashboard;
use App\Support\BackupSchedule;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use PharData;
use ReflectionClass;
use Tests\TestCase;

/**
 * 7.2: backup:database and backup:files. mysqldump is never started (Process::fake): the fake plays
 * its part by writing the --result-file the way the real tool does.
 */
class BackupTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'S3cret"pa\ss#word';

    private const USER = 'dbuser_x';

    private string $dir;

    /** @var list<string>|null the arguments mysqldump was started with */
    private ?array $args = null;

    /** @var array<string, mixed> what the fake saw in the options file at the moment of the call */
    private array $seen = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'backup-test-'.bin2hex(random_bytes(4));

        config([
            'database.connections.backup_test' => [
                'driver' => 'mysql', 'host' => 'db.example.test', 'port' => '3306', 'database' => 'appdb_x',
                'username' => self::USER, 'password' => self::PASSWORD, 'charset' => 'utf8mb4',
            ],
            'backup.connection' => 'backup_test',
            'backup.path' => $this->dir,
            'backup.mysqldump' => 'mysqldump',
        ]);

        ActivityLog::query()->toBase()->delete();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    private function posix(): bool
    {
        return DIRECTORY_SEPARATOR === '/';
    }

    private function dump(bool $complete = true, int $padding = 3000): string
    {
        return "-- MariaDB dump\nCREATE TABLE `x` (id int);\n-- ".str_repeat('x', $padding)."\n".($complete ? "-- Dump completed on 2026-10-07 02:30:00\n" : "INSERT INTO `x` VALUES (1);\n");
    }

    /** Fake mysqldump: records the call, checks the options file, writes the dump, answers with $exit. */
    private function fakeDump(string $dump, int $exit = 0, string $stderr = ''): void
    {
        Process::fake(function ($process) use ($dump, $exit, $stderr) {
            $this->args = $process->command;
            $defaults = substr($this->args[1], strlen('--defaults-extra-file='));
            $result = substr(collect($this->args)->first(fn ($a) => str_starts_with($a, '--result-file=')), strlen('--result-file='));

            $this->seen = [
                'defaults' => file_get_contents($defaults),
                'defaults_mode' => $this->posix() ? fileperms($defaults) & 0777 : null,
                'result_mode' => $this->posix() ? fileperms($result) & 0777 : null,
                'dir_mode' => $this->posix() ? fileperms($this->dir) & 0777 : null,
                'defaults_file' => $defaults,
            ];

            file_put_contents($result, $dump);

            return Process::result(output: '', errorOutput: $stderr, exitCode: $exit);
        });
    }

    /** @return list<string> */
    private function files(): array
    {
        return array_values(array_diff(is_dir($this->dir) ? scandir($this->dir) : [], ['.', '..']));
    }

    private function touchBackup(string $name): void
    {
        File::ensureDirectoryExists($this->dir);
        file_put_contents($this->dir.DIRECTORY_SEPARATOR.$name, 'old');
    }

    private function allLogs(): string
    {
        return ActivityLog::all()->toJson(JSON_UNESCAPED_UNICODE);
    }

    public function test_the_arguments_are_exact_and_the_options_file_comes_first(): void
    {
        $this->fakeDump($this->dump());

        $this->artisan('backup:database')->assertSuccessful();

        $this->assertSame('mysqldump', $this->args[0]);
        $this->assertStringStartsWith('--defaults-extra-file=', $this->args[1], 'the options file must be the first argument');
        $this->assertStringStartsWith('--result-file=', $this->args[2]);
        $this->assertSame(
            ['--single-transaction', '--routines', '--triggers', '--skip-lock-tables', '--default-character-set=utf8mb4', 'appdb_x'],
            array_slice($this->args, 3),
        );
        $this->assertNotContains('--column-statistics=0', $this->args);
        $this->assertNotContains('--no-tablespaces', $this->args);
        Process::assertRanTimes(fn ($process) => is_array($process->command), 1);
    }

    public function test_the_password_never_reaches_the_arguments_the_log_or_the_dashboard(): void
    {
        $this->fakeDump($this->dump(), 2, "mysqldump: Access denied for user '".self::USER."'@'db.example.test' (using password: YES) ".self::PASSWORD);
        Log::spy();

        $this->artisan('backup:database')->assertFailed();

        $this->assertStringNotContainsString(self::PASSWORD, implode(' ', $this->args));
        $this->assertStringNotContainsString('password', implode(' ', $this->args));
        $this->assertStringNotContainsString(self::PASSWORD, $this->allLogs());
        $this->assertStringNotContainsString(self::USER, $this->allLogs());

        Log::shouldHaveReceived('warning')->once()->withArgs(function ($message, $context = []) {
            $text = json_encode($context);

            return ! str_contains($text, self::USER) && ! str_contains($text, 'db.example.test') && ! str_contains($text, 'S3cret') && str_contains($text, '***');
        });
    }

    public function test_the_options_file_is_private_quoted_and_deleted_afterwards(): void
    {
        $this->fakeDump($this->dump());

        $this->artisan('backup:database')->assertSuccessful();

        $this->assertStringContainsString('[client]', $this->seen['defaults']);
        $this->assertStringContainsString('user="'.self::USER.'"', $this->seen['defaults']);
        $this->assertStringContainsString('password="S3cret\\"pa\\\\ss#word"', $this->seen['defaults'], 'quotes and backslashes are escaped');
        $this->assertStringContainsString('host="db.example.test"', $this->seen['defaults']);
        $this->assertStringContainsString('port=3306', $this->seen['defaults']);
        $this->assertFileDoesNotExist($this->seen['defaults_file']);

        if ($this->posix()) {
            $this->assertSame(0600, $this->seen['defaults_mode'], 'the options file is 0600 BEFORE the password is written');
            $this->assertSame(0600, $this->seen['result_mode'], 'the dump file is 0600 BEFORE mysqldump gets it');
            $this->assertSame(0700, $this->seen['dir_mode']);
        }
    }

    public function test_the_options_file_is_deleted_when_the_dump_fails_too(): void
    {
        $this->fakeDump($this->dump(), 2);

        $this->artisan('backup:database')->assertFailed();

        $this->assertFileDoesNotExist($this->seen['defaults_file']);
        $this->assertSame([], $this->files());
    }

    public function test_a_successful_backup_is_a_private_valid_gzip_with_the_expected_name(): void
    {
        $this->fakeDump($dump = $this->dump());

        $this->artisan('backup:database')->assertSuccessful();

        $files = $this->files();
        $this->assertCount(1, $files);
        $this->assertMatchesRegularExpression('/^testing-db-\d{8}-\d{6}\.sql\.gz$/', $files[0]);
        $this->assertSame($dump, gzdecode(file_get_contents($this->dir.DIRECTORY_SEPARATOR.$files[0])));

        if ($this->posix()) {
            $this->assertSame(0600, fileperms($this->dir.DIRECTORY_SEPARATOR.$files[0]) & 0777);
            $this->assertSame(0700, fileperms($this->dir) & 0777);
        }

        $log = ActivityLog::where('action', 'backup.database_created')->sole();
        $this->assertSame('system', $log->actor_type);
        $this->assertSame($files[0], $log->subject_label);
        $this->assertStringContainsString('Backup baze je napravljen', $log->description);
    }

    public function test_the_umask_is_restored(): void
    {
        $this->fakeDump($this->dump());
        $before = umask(0022);
        $this->posix() || $this->markTestSkipped('umask has no effect on Windows');

        $this->artisan('backup:database')->assertSuccessful();

        $this->assertSame(0022, umask($before));
    }

    public function test_a_failed_dump_leaves_no_partial_file_and_deletes_no_older_backup(): void
    {
        $old = ['testing-db-20260101-020000.sql.gz', 'testing-db-20260102-020000.sql.gz'];
        array_map(fn ($n) => $this->touchBackup($n), $old);
        $this->fakeDump($this->dump(), 2);

        $this->artisan('backup:database')->assertFailed();

        $this->assertEqualsCanonicalizing($old, $this->files());
        $log = ActivityLog::where('action', 'backup.failed')->sole();
        $this->assertSame('system', $log->actor_type);
        $this->assertStringContainsString('mysqldump nije uspeo', $log->description);
        $this->assertSame(0, ActivityLog::where('action', 'backup.database_created')->count());
    }

    public function test_a_too_small_or_unfinished_dump_is_a_failure(): void
    {
        $this->touchBackup('testing-db-20260101-020000.sql.gz');

        $this->fakeDump("-- tiny\n-- Dump completed on x\n");
        $this->artisan('backup:database')->assertFailed();
        $this->assertSame(['testing-db-20260101-020000.sql.gz'], $this->files());

        $this->fakeDump($this->dump(complete: false));
        $this->artisan('backup:database')->assertFailed();
        $this->assertSame(['testing-db-20260101-020000.sql.gz'], $this->files());

        $reasons = ActivityLog::where('action', 'backup.failed')->pluck('description')->all();
        $this->assertStringContainsString('premali', $reasons[0]);
        $this->assertStringContainsString('nije završen', $reasons[1]);
    }

    public function test_retention_keeps_the_newest_and_never_touches_other_files(): void
    {
        config(['backup.db_keep' => 3]);
        $names = [];
        foreach (range(1, 6) as $day) {
            $names[] = sprintf('testing-db-202601%02d-023000.sql.gz', $day);
        }
        array_map(fn ($n) => $this->touchBackup($n), $names);
        $strangers = ['notes.txt', 'production-db-20260101-023000.sql.gz', 'testing-files-20260101-030000.tar.gz', 'testing-db-bad.sql.gz', 'testing-db-20260101-023000.sql'];
        array_map(fn ($n) => $this->touchBackup($n), $strangers);
        $this->fakeDump($this->dump());

        $this->artisan('backup:database')->assertSuccessful();

        $remaining = array_values(array_filter($this->files(), fn ($f) => preg_match('/^testing-db-\d{8}-\d{6}\.sql\.gz$/', $f)));
        $this->assertCount(3, $remaining);
        $this->assertContains('testing-db-20260106-023000.sql.gz', $remaining);
        $this->assertContains('testing-db-20260105-023000.sql.gz', $remaining);
        $this->assertNotContains('testing-db-20260101-023000.sql.gz', $remaining);

        foreach ($strangers as $stranger) {
            $this->assertContains($stranger, $this->files(), "$stranger must not be touched");
        }
    }

    public function test_leftovers_of_an_interrupted_run_are_removed_but_only_of_this_kind(): void
    {
        foreach (['.tmp-db-abc123.cnf', '.tmp-db-abc123.sql', '.tmp-db-abc123.sql.gz', '.tmp-files-def456.tar'] as $name) {
            $this->touchBackup($name);
        }
        $this->touchBackup('notes.txt');
        $this->fakeDump($this->dump());

        $this->artisan('backup:database')->assertSuccessful();

        $files = $this->files();
        $this->assertContains('.tmp-files-def456.tar', $files, 'the files backup owns its own leftovers');
        $this->assertContains('notes.txt', $files);
        $this->assertNotContains('.tmp-db-abc123.cnf', $files);
        $this->assertNotContains('.tmp-db-abc123.sql', $files);
        $this->assertNotContains('.tmp-db-abc123.sql.gz', $files);
    }

    public function test_it_refuses_a_database_that_is_not_mysql(): void
    {
        config(['backup.connection' => null]); // the default connection of the tests is SQLite
        Process::fake();

        $this->artisan('backup:database')->assertFailed();

        Process::assertNothingRan();
        $this->assertStringContainsString('nije MySQL ni MariaDB', ActivityLog::where('action', 'backup.failed')->sole()->description);
    }

    public function test_the_folder_may_not_be_reachable_from_the_web(): void
    {
        foreach ([storage_path('app/public/backups'), public_path('backups'), storage_path('app/public')] as $path) {
            $existed = is_dir($path);
            config(['backup.path' => $path]);
            Process::fake();

            $this->artisan('backup:database')->assertFailed();
            $this->artisan('backup:files')->assertFailed();

            Process::assertNothingRan();
            $existed || $this->assertDirectoryDoesNotExist($path);
        }

        $this->assertStringContainsString('javnog direktorijuma', ActivityLog::where('action', 'backup.failed')->latest('id')->first()->description);
    }

    public function test_the_default_folder_is_outside_the_public_disk(): void
    {
        $default = storage_path('app/backups');

        $this->assertStringStartsNotWith(storage_path('app/public'), $default);
        $this->assertStringStartsNotWith(public_path(), $default);
        $this->assertSame(realpath(storage_path('app')), realpath(dirname($default)));
        $this->assertSame('*', trim(explode("\n", (string) file_get_contents(storage_path('app/.gitignore')))[0]));
    }

    public function test_the_schedule_has_both_jobs_at_the_agreed_times_and_never_overlaps_the_prune(): void
    {
        $schedule = new Schedule;
        BackupSchedule::register($schedule);
        $events = collect($schedule->events())->keyBy(fn ($e) => trim(str_replace("'", '', preg_replace('/^.*artisan.?\s+/', '', $e->command))));

        $this->assertSame('30 2 * * *', $events['backup:database']->expression);
        $this->assertSame('0 3 * * 0', $events['backup:files']->expression);
        $this->assertTrue($events['backup:database']->withoutOverlapping);
        $this->assertTrue($events['backup:files']->withoutOverlapping);
        // The overlap lock is short (minutes): a killed process must not block the next run for 24 hours.
        $this->assertSame(120, $events['backup:database']->expiresAt);
        $this->assertSame(120, $events['backup:files']->expiresAt);

        $prune = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'activitylog:prune'));
        $this->assertNotNull($prune);
        $this->assertSame('0 0 * * *', $prune->expression);
        $this->assertNotSame($prune->expression, $events['backup:database']->expression);
    }

    public function test_the_backups_are_not_scheduled_where_there_is_no_mysql(): void
    {
        $this->assertFalse(config('backup.enabled'), 'phpunit.xml uses SQLite');
        $this->assertEmpty(collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains($e->command, 'backup:')));
    }

    public function test_every_backup_action_and_reason_is_translated(): void
    {
        $translations = json_decode(file_get_contents(lang_path('sr_Latn.json')), true, flags: JSON_THROW_ON_ERROR);

        foreach (['backup.database_created', 'backup.files_created', 'backup.failed'] as $action) {
            $this->assertNotEmpty($translations["activity.action.$action"] ?? null, $action);
        }

        foreach ((new ReflectionClass(BackupFailed::class))->getConstants() as $reason) {
            $this->assertNotEmpty($translations["backup.reason.$reason"] ?? null, $reason);
        }
    }

    public function test_the_dashboard_hides_successful_backups_and_shows_a_failure(): void
    {
        ActivityLog::create(['created_at' => now(), 'actor_type' => 'system', 'action' => 'backup.database_created']);
        ActivityLog::create(['created_at' => now(), 'actor_type' => 'system', 'action' => 'backup.files_created']);
        ActivityLog::create(['created_at' => now(), 'actor_type' => 'system', 'action' => 'backup.failed']);

        $this->assertSame(['backup.failed'], array_column(AdminDashboard::data()['recent_activities'], 'action'));
    }

    public function test_the_files_backup_holds_the_images_and_the_private_catalog_but_not_the_env(): void
    {
        $public = $this->dir.'-public';
        File::ensureDirectoryExists($public.'/catalog/models');
        file_put_contents($public.'/catalog/models/a.jpg', 'jpg');
        file_put_contents($public.'/b.png', 'png');
        file_put_contents($public.'/.gitignore', '*');
        $catalog = $this->dir.'-catalog.php';
        file_put_contents($catalog, '<?php return [];');
        config(['filesystems.disks.public.root' => $public, 'catalog.real_catalog_path' => $catalog]);

        try {
            $this->artisan('backup:files')->assertSuccessful();

            $files = $this->files();
            $this->assertCount(1, $files);
            $this->assertMatchesRegularExpression('/^testing-files-\d{8}-\d{6}\.tar\.gz$/', $files[0]);

            $path = $this->dir.DIRECTORY_SEPARATOR.$files[0];
            $archive = new PharData($path);
            $names = [];
            foreach (new \RecursiveIteratorIterator($archive) as $entry) {
                $names[] = str_replace(chr(92), '/', substr($entry->getPathname(), strlen('phar://'.str_replace(chr(92), '/', $path).'/')));
            }
            unset($archive);
            sort($names);

            $this->assertSame(['private/real_catalog.php', 'public/b.png', 'public/catalog/models/a.jpg'], $names);
            // .gitignore placeholders are skipped; the application's .env is not in these sources at all.
            $this->assertEmpty(array_filter($names, fn ($n) => str_contains($n, 'env')));

            if ($this->posix()) {
                $this->assertSame(0600, fileperms($path) & 0777);
            }

            $log = ActivityLog::where('action', 'backup.files_created')->sole();
            $this->assertSame($files[0], $log->subject_label);
            $this->assertStringContainsString('broj fajlova: 3', $log->description);
        } finally {
            File::deleteDirectory($public);
            @unlink($catalog);
        }
    }

    public function test_the_files_backup_keeps_the_four_newest_and_does_nothing_without_files(): void
    {
        $empty = $this->dir.'-empty';
        File::ensureDirectoryExists($empty);
        file_put_contents($empty.'/.gitignore', '*');
        config(['filesystems.disks.public.root' => $empty, 'catalog.real_catalog_path' => $this->dir.'-missing.php']);

        try {
            $this->touchBackup('testing-files-20260101-030000.tar.gz');
            $this->artisan('backup:files')->assertSuccessful();

            $this->assertSame(['testing-files-20260101-030000.tar.gz'], $this->files(), 'nothing to back up: nothing is created or deleted');
            $this->assertSame(0, ActivityLog::where('action', 'like', 'backup.%')->count());

            file_put_contents($empty.'/a.jpg', 'x');
            foreach (range(2, 6) as $week) {
                $this->touchBackup(sprintf('testing-files-202601%02d-030000.tar.gz', $week * 4));
            }
            $this->artisan('backup:files')->assertSuccessful();

            $kept = array_values(array_filter($this->files(), fn ($f) => str_starts_with($f, 'testing-files-')));
            $this->assertCount(4, $kept);
            $this->assertNotContains('testing-files-20260101-030000.tar.gz', $kept);
        } finally {
            File::deleteDirectory($empty);
        }
    }

    public function test_an_archive_without_entries_is_a_failure_and_older_backups_stay(): void
    {
        $public = $this->dir.'-public';
        File::ensureDirectoryExists($public);
        file_put_contents($public.'/a.jpg', 'jpg');
        config(['filesystems.disks.public.root' => $public, 'catalog.real_catalog_path' => $this->dir.'-missing.php']);

        $old = ['testing-files-20260101-030000.tar.gz', 'testing-files-20260102-030000.tar.gz'];
        array_map(fn ($n) => $this->touchBackup($n), $old);
        config(['backup.files_keep' => 1]); // pruning WOULD delete one of them after a success

        // The archive is written but, when opened, holds nothing.
        $this->app->bind(FilesBackup::class, fn () => new class(BackupStorage::fromConfig()) extends FilesBackup
        {
            protected function entryCount(string $archive): int
            {
                return 0;
            }
        });

        try {
            $this->artisan('backup:files')->assertFailed();

            $this->assertEqualsCanonicalizing($old, $this->files(), 'no partial archive is left and no older backup is deleted');
            $log = ActivityLog::where('action', 'backup.failed')->sole();
            $this->assertStringContainsString('arhiva nije napravljena', $log->description);
            $this->assertSame(0, ActivityLog::where('action', 'backup.files_created')->count());
        } finally {
            File::deleteDirectory($public);
        }
    }

    public function test_the_empty_catalog_frame_of_the_repository_is_not_backed_up(): void
    {
        $empty = $this->dir.'-empty2';
        File::ensureDirectoryExists($empty);
        config(['filesystems.disks.public.root' => $empty, 'catalog.real_catalog_path' => database_path('seeders/data/real_catalog.php')]);

        try {
            $this->artisan('backup:files')->assertSuccessful();

            $this->assertSame([], $this->files());
        } finally {
            File::deleteDirectory($empty);
        }
    }
}
