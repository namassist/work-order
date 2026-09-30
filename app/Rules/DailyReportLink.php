<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A link in a daily report (FLOW.md §7): an absolute http or https URL with
 * a host and no credentials, at most work_order.daily_reports.links.max_length
 * characters, without whitespace or control characters, and, when
 * work_order.daily_reports.links.domains is set, on one of those domains or
 * a subdomain. The link is only stored and shown; the server never fetches it.
 */
class DailyReportLink implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $maxLength = config()->integer('work_order.daily_reports.links.max_length');

        if (! is_string($value) || mb_strlen($value) > $maxLength) {
            $fail(__('Tautan paling panjang :max karakter.', ['max' => $maxLength]));

            return;
        }

        $parts = preg_match('/[\s\p{C}]/u', $value) === 1 ? false : parse_url($value);

        if (
            $parts === false
            || ! in_array(mb_strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || ($parts['host'] ?? '') === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || filter_var($value, FILTER_VALIDATE_URL) === false
        ) {
            $fail(__('Tautan harus alamat lengkap yang diawali http:// atau https://.'));

            return;
        }

        $domains = $this->domains();

        if ($domains !== [] && ! $this->isOnDomain(mb_strtolower($parts['host']), $domains)) {
            $fail(__('Tautan hanya boleh ke domain :domains.', ['domains' => implode(', ', $domains)]));
        }
    }

    /**
     * @param  list<string>  $domains
     */
    private function isOnDomain(string $host, array $domains): bool
    {
        return array_any($domains, fn (string $domain): bool => $host === $domain || str_ends_with($host, '.'.$domain));
    }

    /**
     * @return list<string>
     */
    private function domains(): array
    {
        /** @var list<string> */
        return config()->array('work_order.daily_reports.links.domains');
    }
}
