<?php

declare(strict_types=1);

namespace Fhferreira\LlmsTxt\Console;

use FilesystemIterator;
use Illuminate\Console\Command;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

/**
 * Remove a leftover in-repo copy of this package now that the composer-installed
 * copy (in `vendor/fhferreira/llms-txt/`) is active.
 *
 * Use case
 * --------
 * During package development it's common to ship the package as a path repo
 * at e.g. `packages/fhferreira/llms-txt/` so the host can load it without
 * `composer install`. Once the package is published and installed normally,
 * the in-repo copy becomes redundant and should be removed to prevent drift.
 *
 * Safety
 * ------
 *   - Refuses to delete if the calling command is itself loaded from the
 *     target path (would self-destruct mid-run).
 *   - Interactive confirmation by default; pass `--force` to skip.
 *   - Idempotent: no-op when the target path doesn't exist.
 */
final class CleanupLocalCommand extends Command
{
    protected $signature = 'llms:cleanup-local
                            {--path=packages/fhferreira/llms-txt : Path (relative to base_path or absolute) to the in-repo copy}
                            {--force : Skip the confirmation prompt}';

    protected $description = 'Remove the in-repo path-package copy of fhferreira/llms-txt once installed via Composer.';

    public function handle(): int
    {
        $base   = method_exists($this->laravel, 'basePath') ? $this->laravel->basePath() : (string) getcwd();
        $option = (string) $this->option('path');
        $target = str_starts_with($option, '/') ? $option : rtrim($base, '/') . '/' . ltrim($option, '/');
        $target = rtrim($target, '/');

        if (! is_dir($target)) {
            $this->components->info("Nothing to clean up — {$target} does not exist.");

            return self::SUCCESS;
        }

        $selfFile = (new ReflectionClass(self::class))->getFileName();
        $realSelf = $selfFile ? realpath($selfFile) : false;
        $realTgt  = realpath($target);
        if ($realSelf && $realTgt && str_starts_with($realSelf, $realTgt . DIRECTORY_SEPARATOR)) {
            $this->components->error("Refusing to delete: this command is loaded from {$target}. Install the package via composer first so it runs from vendor/, then re-run.");

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm("Remove {$target} (recursive) and any now-empty parent dirs?", false)) {
            $this->components->warn('Aborted — nothing removed.');

            return self::FAILURE;
        }

        $this->removeRecursive($target);
        $this->components->info("Removed {$target}.");

        // Clean now-empty parent dirs, walking up but never above $base
        $parent = dirname($target);
        while ($parent && $parent !== $base && str_starts_with($parent, $base . DIRECTORY_SEPARATOR)) {
            if (! is_dir($parent) || (new FilesystemIterator($parent))->valid()) {
                break;
            }
            rmdir($parent);
            $this->components->info("Also removed empty parent {$parent}.");
            $parent = dirname($parent);
        }

        return self::SUCCESS;
    }

    private function removeRecursive(string $dir): void
    {
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iter as $node) {
            /** @var \SplFileInfo $node */
            $node->isDir() ? rmdir($node->getPathname()) : unlink($node->getPathname());
        }
        rmdir($dir);
    }
}
