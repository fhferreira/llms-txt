<?php

declare(strict_types=1);

namespace Fhferreira\LlmsTxt\Tests\Unit;

use Fhferreira\LlmsTxt\Console\CleanupLocalCommand;
use Illuminate\Console\Command;
use Illuminate\Container\Container;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class CleanupLocalCommandTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/llms-cleanup-test-' . uniqid('', true);
        mkdir($this->base, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->base);
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) return;
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') continue;
            $p = $dir . '/' . $f;
            is_dir($p) ? $this->rrmdir($p) : unlink($p);
        }
        rmdir($dir);
    }

    private function makeCommand(): CommandTester
    {
        $app = new class extends Container {
            public string $base = '';
            public function basePath(?string $path = null): string
            {
                return $this->base . ($path ? '/' . ltrim($path, '/') : '');
            }
            public function runningInConsole(): bool { return true; }
            public function runningUnitTests(): bool { return true; }
            public function environment(): string { return 'testing'; }
        };
        $app->base = $this->base;

        $cmd = new CleanupLocalCommand();
        $cmd->setLaravel($app);

        $console = new Application();
        $console->add($cmd);

        return new CommandTester($console->find('llms:cleanup-local'));
    }

    #[Test]
    public function nothing_to_do_when_target_missing(): void
    {
        $tester = $this->makeCommand();
        $tester->execute(['--force' => true]);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Nothing to clean up', $tester->getDisplay());
    }

    #[Test]
    public function force_removes_target_dir(): void
    {
        mkdir($this->base . '/packages/fhferreira/llms-txt/src', 0777, true);
        file_put_contents($this->base . '/packages/fhferreira/llms-txt/src/X.php', '<?php');
        file_put_contents($this->base . '/packages/fhferreira/llms-txt/composer.json', '{}');

        $tester = $this->makeCommand();
        $tester->execute(['--force' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertDirectoryDoesNotExist($this->base . '/packages/fhferreira/llms-txt');
        // empty parents also removed
        self::assertDirectoryDoesNotExist($this->base . '/packages/fhferreira');
        self::assertDirectoryDoesNotExist($this->base . '/packages');
    }

    #[Test]
    public function does_not_remove_parent_when_other_packages_present(): void
    {
        mkdir($this->base . '/packages/fhferreira/llms-txt', 0777, true);
        mkdir($this->base . '/packages/other/something', 0777, true);
        file_put_contents($this->base . '/packages/other/something/x.php', '<?php');

        $tester = $this->makeCommand();
        $tester->execute(['--force' => true]);

        self::assertDirectoryDoesNotExist($this->base . '/packages/fhferreira');
        self::assertDirectoryExists($this->base . '/packages/other/something');
    }

    #[Test]
    public function custom_path_option_is_respected(): void
    {
        mkdir($this->base . '/custom/in-repo-copy', 0777, true);
        file_put_contents($this->base . '/custom/in-repo-copy/file.txt', 'x');

        $tester = $this->makeCommand();
        $tester->execute([
            '--path'  => 'custom/in-repo-copy',
            '--force' => true,
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertDirectoryDoesNotExist($this->base . '/custom/in-repo-copy');
        self::assertDirectoryDoesNotExist($this->base . '/custom');
    }

    #[Test]
    public function declined_confirmation_aborts(): void
    {
        mkdir($this->base . '/packages/fhferreira/llms-txt', 0777, true);

        $tester = $this->makeCommand();
        $tester->setInputs(['no']);
        $tester->execute([]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertDirectoryExists($this->base . '/packages/fhferreira/llms-txt');
    }
}
