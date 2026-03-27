<?php

namespace App\Services;

use App\Models\Proposal;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Spatie\Browsershot\Browsershot;

class ProposalPdfService
{
    /**
     * Generate a PDF from proposal data and return the storage path.
     */
    public function generate(Proposal $proposal, array $proposalData): ?string
    {
        try {
            $html = view('proposal-pdf', [
                'proposal' => $proposal,
                'data' => $proposalData,
            ])->render();

            $pdf = Browsershot::html($html)
                ->setNodeBinary(config('browsershot.node_binary_path'))
                ->addChromiumArguments(config('browsershot.chrome_args', []))
                ->setOption('landscape', false)
                ->margins(0, 0, 0, 0)
                ->format('A4')
                ->showBackground()
                ->pdf();

            $filename = 'proposals/' . $proposal->id . '_' . time() . '.pdf';
            Storage::disk('local')->put($filename, $pdf);

            return $filename;

        } catch (\Throwable $e) {
            Log::error("ProposalPdfService: Failed to generate PDF for proposal #{$proposal->id}: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Return raw PDF bytes for download.
     */
    public function getPdfContent(Proposal $proposal): ?string
    {
        if (!$proposal->pdf_path || !Storage::disk('local')->exists($proposal->pdf_path)) {
            // Regenerate from stored data
            if ($proposal->proposal_data) {
                $html = view('proposal-pdf', [
                    'proposal' => $proposal,
                    'data' => $proposal->proposal_data,
                ])->render();

                return Browsershot::html($html)
                    ->setNodeBinary(config('browsershot.node_binary_path'))
                    ->addChromiumArguments(config('browsershot.chrome_args', []))
                    ->setOption('landscape', false)
                    ->margins(0, 0, 0, 0)
                    ->format('A4')
                    ->showBackground()
                    ->pdf();
            }

            return null;
        }

        return Storage::disk('local')->get($proposal->pdf_path);
    }
}
