<?php

namespace App\Services\VideoGeneration;

use Illuminate\Support\Facades\Process;

class PerformanceMaxVideo
{
    public const MIN_SECONDS = 10;

    public static function duration(string $bytes): ?float
    {
        $path = tempnam(sys_get_temp_dir(), 'pmax-video-');
        if ($path === false) {
            return null;
        }

        try {
            if (file_put_contents($path, $bytes) !== strlen($bytes)) {
                return null;
            }

            $probe = Process::timeout(15)->run([
                'ffprobe', '-v', 'error', '-f', 'mov', '-protocol_whitelist', 'file,pipe', '-select_streams', 'v:0',
                '-show_entries', 'stream=duration', '-of', 'json', $path,
            ]);
            $seconds = json_decode($probe->output(), true)['streams'][0]['duration'] ?? null;

            return $probe->successful() && is_numeric($seconds) && (float) $seconds > 0 ? (float) $seconds : null;
        } finally {
            unlink($path);
        }
    }
}
