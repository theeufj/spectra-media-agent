<?php

namespace App\Services\Reporting;

use App\Models\Customer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Spatie\Browsershot\Browsershot;

class ReportPdfService
{
    /**
     * Generate a PDF report from report data and return the storage path.
     */
    public function generate(Customer $customer, array $report): ?string
    {
        try {
            $branding = $this->resolveBranding($customer);

            $html = view('reports.executive-pdf', [
                'report' => $report,
                'branding' => $branding,
            ])->render();

            $pdf = Browsershot::html($html)
                ->setNodeBinary(config('browsershot.node_binary_path'))
                ->addChromiumArguments(config('browsershot.chrome_args', []))
                ->setOption('landscape', false)
                ->margins(0, 0, 0, 0)
                ->format('A4')
                ->showBackground()
                ->pdf();

            $period = $report['period']['type'] ?? 'weekly';
            $date = now()->format('Y-m-d');
            $filename = "reports/{$customer->id}/{$period}_{$date}.pdf";

            Storage::disk('local')->put($filename, $pdf);

            return $filename;
        } catch (\Throwable $e) {
            Log::error("ReportPdfService: Failed to generate PDF for customer #{$customer->id}: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Get raw PDF bytes for download (regenerates if file missing).
     */
    public function getPdfContent(Customer $customer, array $report): ?string
    {
        $period = $report['period']['type'] ?? 'weekly';
        $date = $report['period']['end'] ?? now()->format('Y-m-d');
        $filename = "reports/{$customer->id}/{$period}_{$date}.pdf";

        if (Storage::disk('local')->exists($filename)) {
            return Storage::disk('local')->get($filename);
        }

        // Regenerate
        $path = $this->generate($customer, $report);
        if ($path) {
            return Storage::disk('local')->get($path);
        }

        return null;
    }

    /**
     * Resolve branding colors and logo for the PDF.
     * Agency tier customers can set custom branding; others get Spectra defaults.
     */
    protected function resolveBranding(Customer $customer): array
    {
        $customBranding = $customer->report_branding;

        if (!empty($customBranding) && !empty($customBranding['enabled'])) {
            return [
                'company_name' => $customBranding['company_name'] ?? $customer->name,
                'logo_url' => $customBranding['logo_url'] ?? null,
                'primary_color' => $customBranding['primary_color'] ?? '#ff4d00',
                'primary_dark' => $customBranding['primary_dark'] ?? '#992e00',
                'primary_darkest' => $customBranding['primary_darkest'] ?? '#330f00',
            ];
        }

        // Attempt to use extracted brand colors
        $brand = $customer->brandGuideline;
        if ($brand && !empty($brand->color_palette)) {
            $colors = $brand->color_palette;
            $primaryColors = $colors['primary_colors'] ?? [];
            $primaryHex = !empty($primaryColors) ? ($primaryColors[0]['hex'] ?? null) : null;

            if ($primaryHex) {
                return [
                    'company_name' => 'Spectra',
                    'logo_url' => null,
                    'primary_color' => $primaryHex,
                    'primary_dark' => $this->darkenColor($primaryHex, 30),
                    'primary_darkest' => $this->darkenColor($primaryHex, 60),
                ];
            }
        }

        // Default Spectra branding
        return [
            'company_name' => 'Spectra',
            'logo_url' => null,
            'primary_color' => '#ff4d00',
            'primary_dark' => '#992e00',
            'primary_darkest' => '#330f00',
        ];
    }

    /**
     * Darken a hex color by a percentage.
     */
    protected function darkenColor(string $hex, int $percent): string
    {
        $hex = ltrim($hex, '#');
        $r = max(0, hexdec(substr($hex, 0, 2)) - (int)(hexdec(substr($hex, 0, 2)) * $percent / 100));
        $g = max(0, hexdec(substr($hex, 2, 2)) - (int)(hexdec(substr($hex, 2, 2)) * $percent / 100));
        $b = max(0, hexdec(substr($hex, 4, 2)) - (int)(hexdec(substr($hex, 4, 2)) * $percent / 100));

        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }
}
