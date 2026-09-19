<?php

namespace Tests\Feature;

use App\Services\Testing\CreativeBenchmark;
use Tests\TestCase;

class CreativeBenchmarkTest extends TestCase
{
    public function test_missing_review_cannot_pass_the_release_benchmark(): void
    {
        $benchmark = new CreativeBenchmark;
        $fixtures = json_decode(file_get_contents(base_path('tests/Fixtures/Creative/briefs.json')), true, flags: JSON_THROW_ON_ERROR);
        $result = $benchmark->assess([], $fixtures);
        $this->assertFalse($result['passed']);
        $this->assertCount(8, $result['issues']);
    }

    public function test_three_copies_of_a_text_file_cannot_stand_in_for_reviewed_images(): void
    {
        $benchmark = new CreativeBenchmark;
        $result = $benchmark->assess([
            'reviewer' => 'Test reviewer', 'reviewed_at' => now()->toIso8601String(),
            'prompt_fingerprint' => $benchmark->fingerprint(),
            'cases' => [[
                'fixture_id' => 'example', 'notes' => 'Review notes',
                'scores' => array_fill_keys(CreativeBenchmark::CRITERIA, 5),
                'assets' => array_fill(0, 3, ['path' => __FILE__, 'sha256' => hash_file('sha256', __FILE__)]),
            ]],
        ], [['id' => 'example', 'placement' => 'google-search', 'video' => false]]);
        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('distinct rendered files', implode(' ', $result['issues']));
        $this->assertStringContainsString('not a usable rendered image', implode(' ', $result['issues']));
    }
}
