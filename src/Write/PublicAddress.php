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

namespace Kronixon\Connect\Write;

/**
 * Whether an address is public, by explicit tables instead of PHP's FILTER_FLAG_NO_PRIV_RANGE /
 * NO_RES_RANGE (which let 100.64.0.0/10, 198.18.0.0/15, 192.0.0.0/24 and multicast through on every
 * version, and IPv4-mapped IPv6 on PHP 7.4). The tables follow IANA's IPv4 and IPv6 Special-Purpose
 * Address Registries: every block that is not globally reachable, or is for documentation,
 * benchmarking or translation, is refused. An IPv6 address must also be global unicast (2000::/3); an
 * IPv4-mapped one (::ffff:a.b.c.d) is judged by its IPv4 address. Plain bit comparisons on
 * inet_pton(), the same on PHP 7.4 to 8.5.
 */
final class PublicAddress
{
    /** @var array<string, string> block => why it is refused */
    public const IPV4 = [
        '0.0.0.0/8' => 'this network',
        '10.0.0.0/8' => 'private',
        '100.64.0.0/10' => 'shared address space (carrier-grade NAT; cloud metadata services)',
        '127.0.0.0/8' => 'loopback',
        '169.254.0.0/16' => 'link-local (cloud metadata services)',
        '172.16.0.0/12' => 'private',
        '192.0.0.0/24' => 'IETF protocol assignments',
        '192.0.2.0/24' => 'documentation (TEST-NET-1)',
        '192.88.99.0/24' => '6to4 relay anycast (deprecated)',
        '192.168.0.0/16' => 'private',
        '198.18.0.0/15' => 'benchmarking',
        '198.51.100.0/24' => 'documentation (TEST-NET-2)',
        '203.0.113.0/24' => 'documentation (TEST-NET-3)',
        '224.0.0.0/4' => 'multicast',
        '240.0.0.0/4' => 'reserved, with the limited broadcast 255.255.255.255',
    ];

    /** @var array<string, string> block => why it is refused; ::ffff:0:0/96 is judged by its IPv4 address */
    public const IPV6 = [
        '::/96' => 'unspecified, loopback and IPv4-compatible (deprecated)',
        '::ffff:0:0:0/96' => 'IPv4-translated',
        '64:ff9b::/96' => 'NAT64 (reaches IPv4 addresses through a translator)',
        '64:ff9b:1::/48' => 'local-use NAT64',
        '100::/64' => 'discard-only',
        '2001::/23' => 'IETF protocol assignments (Teredo, benchmarking, ORCHID)',
        '2001:db8::/32' => 'documentation',
        '2002::/16' => '6to4 (reaches IPv4 addresses through a relay)',
        '3fff::/20' => 'documentation',
        '5f00::/16' => 'segment routing (SRv6)',
        'fc00::/7' => 'unique local',
        'fe80::/10' => 'link-local',
        'fec0::/10' => 'site-local (deprecated)',
        'ff00::/8' => 'multicast',
    ];

    /** Global unicast: every other IPv6 address is not public. */
    private const IPV6_GLOBAL = '2000::/3';

    /** IPv4-mapped IPv6 (::ffff:a.b.c.d): the IPv4 address decides. */
    private const IPV6_MAPPED = '::ffff:0:0/96';

    /**
     * Why $ip may not be fetched from, or null when it is a public address.
     */
    public static function refusal(string $ip): ?string
    {
        $packed = @inet_pton($ip);
        if (! is_string($packed)) {
            return 'not an IP address';
        }
        if (strlen($packed) === 16 && self::in($packed, self::IPV6_MAPPED)) {
            $packed = substr($packed, 12);
        }
        foreach (strlen($packed) === 4 ? self::IPV4 : self::IPV6 as $block => $why) {
            if (self::in($packed, $block)) {
                return $block.' '.$why;
            }
        }
        if (strlen($packed) === 16 && ! self::in($packed, self::IPV6_GLOBAL)) {
            return self::IPV6_GLOBAL.' not global unicast';
        }

        return null;
    }

    /** Whether the packed address is in the block `address/bits` of its family. */
    private static function in(string $packed, string $block): bool
    {
        [$address, $bits] = explode('/', $block);
        $network = (string) inet_pton($address);
        if (strlen($network) !== strlen($packed)) {
            return false;
        }
        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        if (substr($packed, 0, $bytes) !== substr($network, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($packed[$bytes]) & $mask) === (ord($network[$bytes]) & $mask);
    }
}
