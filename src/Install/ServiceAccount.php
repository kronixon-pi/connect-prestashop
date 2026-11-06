<?php

/**
 * Kronixon Connect for PrestaShop
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License version 3.0
 * that is bundled with this package in the file LICENSE.
 * It is also available at https://opensource.org/licenses/AFL-3.0
 *
 * @author    Kronixon
 * @copyright 2026 Kronixon
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

declare(strict_types=1);

namespace Kronixon\Connect\Install;

use Configuration;
use Employee;
use Language;
use Profile;
use Throwable;

/**
 * The identity the module acts as in the shop (Spec §3.3): its own Profile without any access
 * right and an inactive Employee in it, created at install and removed at uninstall. Later
 * endpoints put this Employee into the PrestaShop Context for catalog reads and writes, so shop
 * logs and hooks show "Kronixon Connect" instead of a real administrator. It cannot log in: it is
 * inactive and its password is a hash of random bytes nobody knows.
 */
final class ServiceAccount
{
    public const PROFILE_ID = 'KX_PROFILE_ID';

    public const EMPLOYEE_ID = 'KX_EMPLOYEE_ID';

    private const NAME = 'Kronixon Connect';

    private function __construct()
    {
    }

    public static function create(): bool
    {
        $profile = new Profile();
        foreach (Language::getLanguages(false) as $language) {
            $profile->name[(int) $language['id_lang']] = self::NAME;
        }
        if (! $profile->add()) {
            return false;
        }
        Configuration::updateGlobalValue(self::PROFILE_ID, (string) $profile->id);

        $employee = new Employee();
        $employee->firstname = 'Kronixon';
        $employee->lastname = 'Connect';
        $employee->email = 'connect-'.substr(sha1((string) Configuration::getGlobalValue('KX_INSTANCE_ID')), 0, 12).'@kronixon.invalid';
        $employee->passwd = password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT);
        $employee->id_profile = (int) $profile->id;
        $employee->id_lang = (int) Configuration::get('PS_LANG_DEFAULT');
        $employee->active = false;
        if (! $employee->add()) {
            return false;
        }

        return (bool) Configuration::updateGlobalValue(self::EMPLOYEE_ID, (string) $employee->id);
    }

    /** Safe on a half-created account: deletes what exists and forgets the ids. */
    public static function remove(): bool
    {
        $employeeId = (int) Configuration::getGlobalValue(self::EMPLOYEE_ID);
        $ok = $employeeId <= 0 || self::delete(new Employee($employeeId));
        $profileId = (int) Configuration::getGlobalValue(self::PROFILE_ID);
        $ok = ($profileId <= 0 || self::delete(new Profile($profileId))) && $ok;
        Configuration::deleteByName(self::EMPLOYEE_ID);
        Configuration::deleteByName(self::PROFILE_ID);

        return $ok;
    }

    /**
     * @param  Employee|Profile  $object
     */
    private static function delete($object): bool
    {
        try {
            return ! $object->id || (bool) $object->delete();
        } catch (Throwable $e) {
            return false;
        }
    }
}
