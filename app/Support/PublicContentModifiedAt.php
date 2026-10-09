<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;

class PublicContentModifiedAt
{
    /**
     * A truthful content date, omitted when the source history is unavailable.
     *
     * @param  list<string>  $sources
     */
    public function forSources(array $sources): ?string
    {
        $existing = array_values(array_filter($sources, fn (string $source) => is_file(base_path($source))));
        if ($existing === []) {
            return null;
        }

        $fingerprint = hash('sha256', implode('|', array_map(
            fn (string $source) => $source.':'.hash_file('sha256', base_path($source)),
            $existing,
        )));

        return Cache::remember('public-seo:lastmod:'.$fingerprint, now()->addDay(), function () use ($existing): ?string {
            try {
                $result = Process::path(base_path())->timeout(5)->run([
                    'git', 'log', '-1', '--format=%cI%x09%P', '--', ...$existing,
                ]);
                [$date, $parents] = array_pad(explode("\t", trim($result->output()), 2), 2, '');

                // Forge shallow checkouts can present their oldest available
                // commit as the change date of every file. Without a parent
                // we cannot prove when this content changed, so omit it.
                return $result->successful() && $parents !== '' && preg_match('/^\d{4}-\d{2}-\d{2}T/', $date)
                    ? $date
                    : null;
            } catch (\Throwable $e) {
                // A source date is optional. Missing git in a release must not
                // make the sitemap fail or invent a modification date.
                return null;
            }
        });
    }
}
