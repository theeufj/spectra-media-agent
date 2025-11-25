<?php

namespace App\Prompts;

use App\Models\BrandGuideline;

class ImagePrompt
{
    private string $strategyContent;
    private ?BrandGuideline $brandGuidelines;
    private ?array $productContext;

    public function __construct(string $strategyContent, ?BrandGuideline $brandGuidelines = null, ?array $productContext = null)
    {
        $this->strategyContent = $strategyContent;
        $this->brandGuidelines = $brandGuidelines;
        $this->productContext = $productContext;
    }

    public function getPrompt(): string
    {
        $brandContext = $this->brandGuidelines ? $this->formatBrandContext() : '';

        $productContextString = '';
        if (!empty($this->productContext)) {
            $productContextString = "\n\n**PRODUCT DETAILS:**\n" .
                "The image MUST feature or relate to the following product(s):\n" .
                json_encode($this->productContext, JSON_PRETTY_PRINT);
        }

        return "Generate a high-quality, visually compelling marketing image that adheres to the following requirements:\n\n" .
               $brandContext .
               $productContextString . "\n\n" .
               "**TECHNICAL SPECIFICATIONS:**\n" .
               "- Style: Professional, modern, high-resolution\n" .
               "- Format: Suitable for digital advertising\n" .
               "- Composition: Clear focal point, mobile-friendly, high contrast\n\n" .
               "**CREATIVE STRATEGY:**\n" .
               $this->strategyContent . "\n\n" .
               "**IMPORTANT:**\n" .
               "- Avoid text in the image (will be added separately)\n" .
               "- Ensure cultural sensitivity and inclusivity\n" .
               "- No stock photo clichés\n" .
               "- Brand recognition should be implicit through style";
    }

    private function formatBrandContext(): string
    {
        if (!$this->brandGuidelines) {
            return '';
        }

        $visualStyle = $this->brandGuidelines->visual_style;
        
        return "**BRAND STYLE GUIDELINES:**\n" .
               $this->brandGuidelines->getFormattedColorPalette() . "\n" .
               "**Visual Style:** {$visualStyle['overall_aesthetic']}\n" .
               "**Imagery Style:** {$visualStyle['imagery_style']}\n" .
               "**Description:** {$visualStyle['description']}\n\n";
    }
}
