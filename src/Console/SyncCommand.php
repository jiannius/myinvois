<?php

namespace Jiannius\Myinvois\Console;

use Illuminate\Console\Command;
use Jiannius\Myinvois\Support\StatusSync;

class SyncCommand extends Command
{
    protected $signature = 'myinvois:sync
        {--limit= : Max submissions to check this run (default: services.myinvois.sync_limit, 100)}
        {--dry-run : List what would be checked without calling MyInvois}
        {--include-valid : Also re-check each parent\'s latest VALID submission for the first 72 hours}';

    protected $description = 'Refresh MyInvois statuses for submissions that are still submitted (the safety net behind the submission jobs)';

    public function handle() : int
    {
        $limit = $this->option('limit');
        $limit = is_numeric($limit) && $limit > 0 ? (int) $limit : (int) config('services.myinvois.sync_limit', 100);

        $result = (new StatusSync(
            limit: $limit > 0 ? $limit : 100,
            dryRun: (bool) $this->option('dry-run'),
            includeValid: (bool) $this->option('include-valid'),
        ))->run();

        $this->line('MyInvois '.($result['preprod'] ? 'sandbox (preprod)' : 'production').' sync'.($this->option('dry-run') ? ' (dry run)' : ''));

        if ($this->option('dry-run')) {
            foreach ($result['would_check'] as $uid) $this->line("  would check {$uid}");
        }

        $this->line("  submissions to check: {$result['candidates']}");
        $this->line("  checked: {$result['checked']}, rows changed: {$result['changed']}");

        if ($result['skipped_no_parent']) $this->line("  skipped, no parent: {$result['skipped_no_parent']}");
        if ($result['skipped_environment']) $this->line("  skipped, other environment: {$result['skipped_environment']}");
        if ($result['skipped_auth']) $this->line("  skipped, credentials failed earlier in this run: {$result['skipped_auth']}");

        foreach ($result['errors'] as $error) $this->error("  {$error}");

        return $result['errors'] ? self::FAILURE : self::SUCCESS;
    }
}
