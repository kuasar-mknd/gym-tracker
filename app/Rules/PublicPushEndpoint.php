<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rejects push endpoints that would make the server call its own network.
 *
 * The endpoint is supplied by the client and stored verbatim, then POSTed to by
 * the WebPush channel every time a notification fires. Laravel's `url` rule
 * accepts any scheme and any host, so on its own it let an authenticated user
 * point the server at loopback, private ranges, link-local — the cloud metadata
 * endpoint included. Blind SSRF: the response never comes back to the caller,
 * but the request is made.
 */
class PublicPushEndpoint implements ValidationRule
{
    /**
     * @param  Closure(string, string|null=): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('L\'endpoint doit être une URL.');

            return;
        }

        $parts = parse_url($value);

        if ($parts === false || ($parts['scheme'] ?? null) !== 'https' || ! isset($parts['host'])) {
            $fail('L\'endpoint doit être une URL https.');

            return;
        }

        foreach ($this->addressesFor($parts['host']) as $address) {
            if ($this->isReserved($address)) {
                $fail('L\'endpoint doit désigner un hôte public.');

                return;
            }
        }
    }

    /**
     * Every address the host resolves to — the literal itself when it is an IP.
     *
     * A hostname is checked through DNS because `https://internal.example.com`
     * looks public and may resolve to 10.0.0.5. An unresolvable host yields no
     * address and is therefore accepted: it cannot be reached either, and
     * failing here would reject legitimate endpoints on a transient DNS blip.
     *
     * @return list<string>
     */
    private function addressesFor(string $host): array
    {
        // parse_url keeps the brackets around an IPv6 literal, and FILTER_VALIDATE_IP
        // rejects them — without this, https://[::1]/ fell through to DNS, resolved
        // to nothing, and was accepted.
        $literal = trim($host, '[]');

        if (filter_var($literal, FILTER_VALIDATE_IP) !== false) {
            return [$literal];
        }

        $records = dns_get_record($host, DNS_A | DNS_AAAA);

        if ($records === false) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (array $record): ?string => $record['ip'] ?? $record['ipv6'] ?? null,
            $records
        )));
    }

    /**
     * Private, loopback, link-local and otherwise reserved ranges.
     */
    private function isReserved(string $address): bool
    {
        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }
}
