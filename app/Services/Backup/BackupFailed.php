<?php

namespace App\Services\Backup;

use RuntimeException;

/**
 * A backup that did not succeed. The reason is a short code (translated as backup.reason.<code>): the
 * message that reaches the activity log never carries a path, a credential or the output of the tools.
 */
final class BackupFailed extends RuntimeException
{
    public const UNSUPPORTED_DATABASE = 'unsupported_database';

    public const PROC_OPEN_UNAVAILABLE = 'proc_open_unavailable';

    public const UNSAFE_PATH = 'unsafe_path';

    public const CANNOT_WRITE = 'cannot_write';

    public const DUMP_FAILED = 'dump_failed';

    public const DUMP_TOO_SMALL = 'dump_too_small';

    public const DUMP_INCOMPLETE = 'dump_incomplete';

    public const ARCHIVE_FAILED = 'archive_failed';

    public const CONFIG_INVALID = 'config_invalid';

    public function __construct(public readonly string $reason, string $detail = '')
    {
        parent::__construct(trim($reason.' '.$detail));
    }

    /** Human text of the reason, for the activity log and the console. */
    public function label(): string
    {
        return __('backup.reason.'.$this->reason);
    }
}
