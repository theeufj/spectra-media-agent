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
    *   If the strategy describes **multiple distinct images** (e.g., "Slide 1:", "Step 1:", etc.), create a separate, detailed prompt for each — up to a **maximum of 2 prompts**.
    *   If the strategy describes a **single image concept**, create just one detailed prompt for that image.
4.  **Describe the scene, never the wording.** Every prompt MUST describe a photographic or illustrated **scene** — people, environments, objects, light, mood. Say nothing about headlines, captions, logos, buttons or calls-to-action: the layout and the type are composed downstream from approved ad copy, and a prompt that discusses them gets that discussion rendered *as words in the picture*.
5.  **Never a screen full of words.** Do not describe dashboards, app windows, spreadsheets, charts, documents or any interface with readable labels. A phone or laptop may appear; its screen is simple shapes and colour, nothing legible. Generated small text garbles into nonsense, and a fake UI is the most reliable way to ruin an ad.
6.  **No hex codes or format names.** Translate "#1e3a5f" to "deep navy". Drop "Responsive Display Ad", "MREC", "carousel" — they describe where an ad runs, not what it shows.
7.  **Output Format:** Your response MUST be a valid JSON object with a single key, "prompts", which is an array of strings (maximum 2 items).

**EXAMPLE 1: Multi-Image Strategy**

*   **Input Strategy:** "Create a 3-slide carousel. Slide 1: A person looking confused at a pile of paperwork. Slide 2: The same person smiling while using our software on a laptop. Slide 3: A clear call to action with our logo."
*   **Note:** the third slide is a call-to-action card, so it is dropped — type and logos are composed downstream, not generated.
*   **Your Output:**
    ```json
    {
      "prompts": [
        "A person with a confused expression sitting at a desk overwhelmed by a large pile of paperwork. The style should be realistic and slightly desaturated.",
        "The same person, now looking happy and relieved, working at a laptop in a bright modern office. Warm optimistic light from a window behind them. The laptop screen is out of focus, showing only soft blocks of colour."
      ]
    }
    ```

**EXAMPLE 2: Single-Image Strategy**

*   **Input Strategy:** "A visually striking infographic showing the benefits of our API, with a sleek, modern application dashboard in the background."
*   **Note:** infographics and dashboards are made of small text, which generates as garbage. Keep the intent — technical, capable, modern — and find a real scene that carries it.
*   **Your Output:**
    ```json
    {
      "prompts": [
        "A developer at a standing desk in a calm, low-lit studio, lit by the glow of two monitors whose screens read as soft abstract colour rather than detail. Clean modern workspace, shallow depth of field."
      ]
    }
    ```

---

**CREATIVE STRATEGY TO ANALYZE:**
{$this->strategyContent}
PROMPT;
    }
}
