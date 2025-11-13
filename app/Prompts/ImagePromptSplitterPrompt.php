<?php

namespace App\Prompts;

class ImagePromptSplitterPrompt
{
    private string $strategyContent;

    public function __construct(string $strategyContent)
    {
        $this->strategyContent = $strategyContent;
    }

    public function getPrompt(): string
    {
        return <<<PROMPT
You are an expert prompt engineer. Your task is to analyze a creative strategy and break it down into a series of specific, actionable prompts for an image generation model.

**RULES:**
1.  **Analyze the Strategy:** Read the creative strategy carefully.
2.  **Identify Image Concepts:** Determine if the strategy describes a single image or a sequence of multiple images (like a carousel or storyboard).
3.  **Generate Prompts:**
    *   If the strategy describes **multiple distinct images** (e.g., "Slide 1:", "Step 1:", etc.), create a separate, detailed prompt for each image. Each prompt should be a self-contained instruction for generating that specific image.
    *   If the strategy describes a **single image concept**, create just one detailed prompt for that image.
4.  **Output Format:** Your response MUST be a valid JSON object with a single key, "prompts", which is an array of strings.

**EXAMPLE 1: Multi-Image Strategy**

*   **Input Strategy:** "Create a 3-slide carousel. Slide 1: A person looking confused at a pile of paperwork. Slide 2: The same person smiling while using our software on a laptop. Slide 3: A clear call to action with our logo."
*   **Your Output:**
    ```json
    {
      "prompts": [
        "A person with a confused expression sitting at a desk overwhelmed by a large pile of paperwork. The style should be realistic and slightly desaturated.",
        "The same person, now looking happy and relieved, using a modern, sleek software application on a laptop. The lighting should be bright and optimistic.",
        "A clean, minimalist graphic with the company's logo and the text 'Simplify Your Workflow Today!' in a bold, clear font."
      ]
    }
    ```

**EXAMPLE 2: Single-Image Strategy**

*   **Input Strategy:** "A visually striking infographic showing the benefits of our API, with a sleek, modern application dashboard in the background."
*   **Your Output:**
    ```json
    {
      "prompts": [
        "A visually striking infographic about the benefits of a powerful API. The design should be modern and clean, with a sleek application dashboard visible in the background."
      ]
    }
    ```

---

**CREATIVE STRATEGY TO ANALYZE:**
{$this->strategyContent}
PROMPT;
    }
}
