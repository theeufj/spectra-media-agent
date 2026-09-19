<?php

namespace App\Console\Commands;

use App\Services\Testing\CreativeBenchmark;
use Illuminate\Console\Command;

class ReviewCreativeBenchmark extends Command
{
    protected $signature = 'creative:benchmark {manifest? : JSON review manifest} {--output= : Write JSON to a file}';

    protected $description = 'Prepare a fixed creative review set, or verify a rendered-asset review before release';

    public function handle(CreativeBenchmark $benchmark): int
    {
        $fixtures = json_decode(file_get_contents(base_path('tests/Fixtures/Creative/briefs.json')), true, flags: JSON_THROW_ON_ERROR);
        if (! $this->argument('manifest')) {
            $this->writeJson([
                'prompt_fingerprint' => $benchmark->fingerprint(),
                'reviewer' => null, 'reviewed_at' => null,
                'cases' => array_map(fn ($fixture) => [
                    'fixture_id' => $fixture['id'], 'brief' => $fixture,
                    'assets' => [], 'scores' => array_fill_keys(CreativeBenchmark::CRITERIA, null),
                    'video_reviewed' => false, 'notes' => '',
                ], $fixtures),
            ]);

            return self::SUCCESS;
        }
        $manifest = json_decode(file_get_contents($this->argument('manifest')), true, flags: JSON_THROW_ON_ERROR);
        $result = $benchmark->assess($manifest, $fixtures);
        $this->writeJson($result);

        return $result['passed'] ? self::SUCCESS : self::FAILURE;
    }

    private function writeJson(array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $path = $this->option('output');
        if (is_string($path) && $path !== '') {
            \Illuminate\Support\Facades\File::put($path, $json."\n");
            $this->info('Creative benchmark written to '.$path);

            return;
        }
        $this->line($json);
    }
}
