<?php

declare(strict_types=1);

namespace App\PaymentsCore\Domain\Support;

final class PostbackNetworkGuard
{
    public static function isAllowedUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($url);
        if (! is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if (self::isExplicitlyAllowedBenchmarkHost($scheme, $host)) {
            return true;
        }

        if ($scheme !== 'https') {
            return false;
        }

        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.local')) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return self::isAllowedIp($host);
        }

        $resolvedIps = self::resolveHostIps($host);
        if ($resolvedIps === []) {
            return false;
        }

        foreach ($resolvedIps as $ip) {
            if (! self::isAllowedIp($ip)) {
                return false;
            }
        }

        return true;
    }

    public static function isAllowedIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE | FILTER_FLAG_IPV4) === false) {
                return false;
            }

            $packed = inet_pton($ip);
            if (! is_string($packed)) {
                return false;
            }

            $octets = unpack('C4', $packed);
            if (! is_array($octets)) {
                return false;
            }

            if ($octets[1] === 127) {
                return false;
            }

            if ($octets[1] === 169 && $octets[2] === 254) {
                return false;
            }

            return true;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE | FILTER_FLAG_IPV6) === false) {
            return false;
        }

        $packed = inet_pton($ip);
        if (! is_string($packed)) {
            return false;
        }

        $loopbackPacked = inet_pton('::1');
        if (is_string($loopbackPacked) && hash_equals($packed, $loopbackPacked)) {
            return false;
        }

        $bytes = unpack('C16', $packed);
        if (! is_array($bytes)) {
            return false;
        }

        if ($bytes[1] === 0xFE && ($bytes[2] & 0xC0) === 0x80) {
            return false;
        }

        return true;
    }

    private static function resolveHostIps(string $host): array
    {
        $ips = [];

        $ipv4 = gethostbynamel($host);
        if (is_array($ipv4)) {
            $ips = array_merge($ips, $ipv4);
        }

        if (function_exists('dns_get_record')) {
            $aRecords = dns_get_record($host, DNS_A);
            if (is_array($aRecords)) {
                foreach ($aRecords as $record) {
                    $ip = $record['ip'] ?? null;
                    if (is_string($ip) && $ip !== '') {
                        $ips[] = $ip;
                    }
                }
            }

            $aaaaRecords = dns_get_record($host, DNS_AAAA);
            if (is_array($aaaaRecords)) {
                foreach ($aaaaRecords as $record) {
                    $ip = $record['ipv6'] ?? null;
                    if (is_string($ip) && $ip !== '') {
                        $ips[] = $ip;
                    }
                }
            }
        }

        $normalized = [];
        foreach ($ips as $ip) {
            if (is_string($ip) && $ip !== '') {
                $normalized[] = strtolower(trim($ip));
            }
        }

        return array_values(array_unique($normalized));
    }

    private static function isExplicitlyAllowedBenchmarkHost(string $scheme, string $host): bool
    {
        if ($host === '') {
            return false;
        }

        if (! in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        $allowedHosts = config('postbacks.benchmark_allowed_hosts', []);
        if (! is_array($allowedHosts)) {
            return false;
        }

        return in_array($host, $allowedHosts, true);
    }
}
