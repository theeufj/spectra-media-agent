<?php

namespace Tests\Feature;

use App\Support\PublicContentModifiedAt;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class PublicContentModifiedAtTest extends TestCase
{
    public function test_modification_date_uses_content_history_instead_of_release_time(): void
    {
        Process::fake([
            '*' => Process::result(output: "2026-09-18T12:34:56+10:00\t1234567890abcdef\n"),
        ]);
        $this->travelTo(now()->setDate(2026, 10, 8));

        $date = app(PublicContentModifiedAt::class)->forSources(['resources/js/Pages/Features.jsx']);

        $this->assertSame('2026-09-18T12:34:56+10:00', $date);
        Process::assertRan(fn ($process) => $process->command === [
            'git', 'log', '-1', '--format=%cI%x09%P', '--', 'resources/js/Pages/Features.jsx',
        ]);
    }

    public function test_unavailable_history_omits_the_date_instead_of_inventing_one(): void
    {
        Process::fake(['*' => Process::result(exitCode: 128)]);

        $this->assertNull(app(PublicContentModifiedAt::class)->forSources(['resources/js/Pages/Features.jsx']));
    }

    public function test_a_shallow_history_boundary_does_not_date_unchanged_files_to_the_release(): void
    {
        Process::fake(['*' => Process::result(output: "2026-10-08T12:00:00+11:00\t\n")]);

        $this->assertNull(app(PublicContentModifiedAt::class)->forSources(['resources/js/Pages/Features.jsx']));
    }
}
