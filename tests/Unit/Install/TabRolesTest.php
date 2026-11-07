<?php

declare(strict_types=1);

namespace Kronixon\Connect\Tests\Unit\Install;

use InvalidArgumentException;
use Kronixon\Connect\Install\TabRoles;
use PHPUnit\Framework\TestCase;

final class TabRolesTest extends TestCase
{
    /** @var list<string> */
    private $queries = [];

    public function test_it_reads_the_four_role_ids_of_each_tab_by_slug(): void
    {
        $roles = $this->roles([['id_authorization_role' => '967'], ['id_authorization_role' => '965'], ['id_authorization_role' => '968'], ['id_authorization_role' => '966']]);

        self::assertSame([965, 966, 967, 968], $roles->roleIds(['AdminKronixonConnect']));
        self::assertSame(["SELECT `id_authorization_role` FROM `pfx_authorization_role` WHERE `slug` IN ('ROLE_MOD_TAB_ADMINKRONIXONCONNECT_CREATE', 'ROLE_MOD_TAB_ADMINKRONIXONCONNECT_READ', 'ROLE_MOD_TAB_ADMINKRONIXONCONNECT_UPDATE', 'ROLE_MOD_TAB_ADMINKRONIXONCONNECT_DELETE')"], $this->queries);
    }

    public function test_it_deletes_only_the_access_rows_of_those_roles(): void
    {
        $roles = $this->roles([]);

        self::assertTrue($roles->deleteAccess([965, 966, 967, 968]));
        self::assertSame(['DELETE FROM `pfx_access` WHERE `id_authorization_role` IN (965, 966, 967, 968)'], $this->queries);
    }

    public function test_no_tab_or_no_role_runs_no_delete(): void
    {
        $roles = $this->roles(false);

        self::assertSame([], $roles->roleIds(['AdminKronixonConnect']), 'a failed select reads as no role');
        self::assertSame([], $roles->roleIds([]));
        self::assertTrue($roles->deleteAccess([]));
        self::assertCount(1, $this->queries, 'only the one select');
    }

    public function test_a_class_name_outside_the_identifier_form_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->roles([])->roleIds(["Admin') OR ('1"]);
    }

    /**
     * @param  array<int, array<string, mixed>>|false  $rows
     */
    private function roles($rows): TabRoles
    {
        $this->queries = [];

        return new TabRoles(function (string $sql) use ($rows) {
            $this->queries[] = $sql;

            return $rows;
        }, function (string $sql): bool {
            $this->queries[] = $sql;

            return true;
        }, 'pfx_');
    }
}
