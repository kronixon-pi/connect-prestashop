<?php

declare(strict_types=1);

namespace Kronixon\Connect\Tests\Unit\Update;

use Kronixon\Connect\Tests\Support\MemoryFiles;
use Kronixon\Connect\Update\Swap;
use Kronixon\Connect\Update\UpdateError;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The module folder is replaced by two renames next to it, never copied over (Spec §10 item 4).
 */
final class SwapTest extends TestCase
{
    private const MODULES = '/shop/modules';

    private const LIVE = '/shop/modules/kronixonconnect';

    /** @var MemoryFiles */
    private $fs;

    /** @var Swap */
    private $swap;

    /** @var array<string, string> */
    private $old = ['kronixonconnect.php' => 'old main', 'src/A.php' => 'old A', 'src/Gone.php' => 'only old'];

    /** @var array<string, string> */
    private $new = ['kronixonconnect.php' => 'new main', 'src/A.php' => 'new A', 'src/Deep/B.php' => 'new B', 'upgrade/upgrade-0.9.0.php' => 'up'];

    protected function setUp(): void
    {
        $this->fs = new MemoryFiles();
        $this->fs->seed(self::MODULES, []);
        $this->fs->seed(self::LIVE, $this->old, 0640, 0750);
        $this->swap = new Swap($this->fs, self::MODULES.'/');
    }

    public function test_stage_swap_and_commit_leave_exactly_the_new_folder_with_the_shops_permissions(): void
    {
        $this->swap->preflight(1000);
        $this->swap->stage($this->new);
        self::assertSame($this->sorted($this->old), $this->fs->tree(self::LIVE), 'staging does not touch the folder in use');

        $this->swap->swap();
        self::assertSame($this->sorted($this->new), $this->fs->tree(self::LIVE));
        self::assertSame($this->sorted($this->old), $this->fs->tree($this->swap->prev()));
        self::assertFalse($this->fs->exists($this->swap->next()));
        self::assertSame([self::LIVE], $this->fs->forgotten, 'OPcache forgets the old scripts');

        self::assertTrue($this->swap->commit());
        self::assertFalse($this->fs->exists($this->swap->prev()));
        self::assertSame(0640 | 0600, $this->fs->mode(self::LIVE.'/src/Deep/B.php'));
        self::assertSame(0750 | 0700, $this->fs->mode(self::LIVE.'/src/Deep'));
    }

    public function test_a_process_killed_while_staging_leaves_the_old_module_and_the_next_attempt_works(): void
    {
        $this->fs->writesUntilKill = 2;
        try {
            $this->swap->stage($this->new);
            self::fail('not killed');
        } catch (RuntimeException $killed) {
            self::assertSame('killed', $killed->getMessage());
        }
        self::assertSame($this->sorted($this->old), $this->fs->tree(self::LIVE));
        self::assertNotSame([], $this->fs->tree($this->swap->next()), 'a half stage is left behind');

        // The next request starts over.
        $this->fs->writesUntilKill = null;
        $this->swap->stage($this->new);
        $this->swap->swap();
        self::assertSame($this->sorted($this->new), $this->fs->tree(self::LIVE));
    }

    public function test_a_full_disk_while_staging_removes_the_stage_and_keeps_the_old_module(): void
    {
        $this->fs->writesUntilFull = 2;

        $this->expectServerError('write_failed', function (): void {
            $this->swap->stage($this->new);
        });
        self::assertFalse($this->fs->exists($this->swap->next()));
        self::assertSame($this->sorted($this->old), $this->fs->tree(self::LIVE));
    }

    public function test_a_folder_php_may_not_write_or_too_little_disk_stops_before_anything_is_written(): void
    {
        $this->fs->readOnly = [self::LIVE.'/src'];
        $this->expectServerError('read_only', function (): void {
            $this->swap->preflight(1000);
        });

        $this->fs->readOnly = [];
        $this->fs->free = 4000000.0;
        $this->expectServerError('disk_space', function (): void {
            $this->swap->preflight(1000);
        });

        // OPcache that never looks at the files again, and no opcache_invalidate(): the new code would never run.
        $this->fs->free = null;
        $this->fs->forgettable = false;
        $this->expectServerError('opcache_static', function (): void {
            $this->swap->preflight(1000);
        });
        $this->fs->forgettable = true;

        // The modules folder itself is read-only: the stage cannot be made.
        $this->fs->readOnly = [self::MODULES];
        $this->expectServerError('read_only', function (): void {
            $this->swap->stage($this->new);
        });
        self::assertSame($this->sorted($this->old), $this->fs->tree(self::LIVE));
    }

    public function test_a_failed_second_rename_puts_the_old_folder_back(): void
    {
        $this->swap->stage($this->new);
        $this->fs->failRenames = [$this->swap->next().'>'.self::LIVE];

        $this->expectServerError('swap_failed', function (): void {
            $this->swap->swap();
        });
        self::assertSame($this->sorted($this->old), $this->fs->tree(self::LIVE));
        self::assertFalse($this->fs->exists($this->swap->prev()));
        self::assertFalse($this->fs->exists($this->swap->next()));
    }

    public function test_rollback_restores_the_old_folder_and_drops_the_new_one(): void
    {
        $this->swap->stage($this->new);
        $this->swap->swap();

        self::assertTrue($this->swap->rollback());
        self::assertSame($this->sorted($this->old), $this->fs->tree(self::LIVE));
        self::assertFalse($this->fs->exists($this->swap->prev()));
        self::assertFalse($this->fs->exists($this->swap->failed()));
        // Nothing to roll back to any more.
        self::assertFalse($this->swap->rollback());
        self::assertSame($this->sorted($this->old), $this->fs->tree(self::LIVE));
    }

    public function test_a_previous_folder_a_failed_commit_left_is_removed_by_the_next_swap(): void
    {
        $this->fs->seed($this->swap->prev(), ['kronixonconnect.php' => 'older']);
        $this->swap->stage($this->new);
        $this->swap->swap();

        self::assertSame($this->sorted($this->old), $this->fs->tree($this->swap->prev()));
    }

    /**
     * @param  array<string, string>  $files
     * @return array<string, string>
     */
    private function sorted(array $files): array
    {
        ksort($files, SORT_STRING);

        return $files;
    }

    private function expectServerError(string $code, callable $action): void
    {
        try {
            $action();
            self::fail('no error, expected '.$code);
        } catch (UpdateError $error) {
            self::assertSame(UpdateError::SERVER, $error->kind());
            self::assertSame($code, $error->code());
        }
    }
}
