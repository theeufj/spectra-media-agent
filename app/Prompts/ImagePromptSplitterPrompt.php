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
3.  **Generate Prompts — always exactly 3, and they must genuinely differ.**
    *   If the strategy describes **multiple distinct images** (e.g., "Slide 1:", "Step 1:", etc.), take those as your starting points.
    *   If it describes **one scene**, that scene is prompt 1. Prompts 2 and 3 are *your* job: two further scenes that sell the same offer to the same audience by showing something else entirely.
    *   **Paraphrasing is a failure.** The same person in the same room from a different camera angle is one image, not three. Three prompts that differ only in wording produce three near-identical ads, and a customer scrolling past sees one ad three times.
    *   Each prompt must differ from the others in **at least two** of: the **subject** (who or what is in frame — and it need not be a person at all), the **setting**, the **shot distance** (close detail / person at work / wide environment), and the **moment** (before, during, after).
    *   The strategy's scene often names one customer archetype. Do not render that archetype three times. If the offer serves makers, sellers and small brands, let the set show that range — and remember the product itself, a workspace, or a pair of hands are all legitimate subjects.
4.  **Describe the scene, never the wording.** Every prompt MUST describe a photographic or illustrated **scene** — people, environments, objects, light, mood. Say nothing about headlines, captions, logos, buttons or calls-to-action: the layout and the type are composed downstream from approved ad copy, and a prompt that discusses them gets that discussion rendered *as words in the picture*.
5.  **Never a screen full of words.** Do not describe dashboards, app windows, spreadsheets, charts, documents or any interface with readable labels. A phone or laptop may appear; its screen is simple shapes and colour, nothing legible. Generated small text garbles into nonsense, and a fake UI is the most reliable way to ruin an ad.
6.  **No hex codes or format names.** Translate "#1e3a5f" to "deep navy". Drop "Responsive Display Ad", "MREC", "carousel" — they describe where an ad runs, not what it shows.
7.  **Output Format:** Your response MUST be a valid JSON object with a single key, "prompts", which is an array of exactly 3 strings.

**EXAMPLE 1: Multi-Image Strategy**

*   **Input Strategy:** "Create a 3-slide carousel. Slide 1: A person looking confused at a pile of paperwork. Slide 2: The same person smiling while using our software on a laptop. Slide 3: A clear call to action with our logo."
*   **Note:** the third slide is a call-to-action card, so it is dropped — type and logos are composed downstream, not generated.
*   **Your Output:**
    ```json
    {
      "prompts": [
        "A person with a confused expression sitting at a desk overwhelmed by a large pile of paperwork. Realistic, slightly desaturated, shot from across the desk.",
        "A different person, relieved and unhurried, working at a laptop in a bright modern office. Warm light from a window behind them. The laptop screen is out of focus, showing only soft blocks of colour.",
        "Close detail: two hands closing a cardboard folder on an empty, tidy desk beside a cooling cup of coffee. No one else in frame, late afternoon light, shallow depth of field."
      ]
    }
    ```
*   **Note:** three different subjects, three distances, three moments — overwhelmed, working, finished. Not one scene rewritten.

**EXAMPLE 2: Single-Image Strategy**

*   **Input Strategy:** "A visually striking infographic showing the benefits of our API, with a sleek, modern application dashboard in the background."
*   **Note:** infographics and dashboards are made of small text, which generates as garbage. Keep the intent — technical, capable, modern — and find a real scene that carries it.
*   **Your Output:**
    ```json
    {
      "prompts": [
        "A developer at a standing desk in a calm, low-lit studio, lit by the glow of two monitors whose screens read as soft abstract colour rather than detail. Clean modern workspace, shallow depth of field.",
        "Wide shot of a small team mid-conversation around a whiteboard covered in simple hand-drawn boxes and arrows, morning light across the room, nobody looking at the camera.",
        "Macro detail of a mechanical keyboard and a worn notebook on a dark desk, one hand resting beside them, a single warm lamp just out of frame."
      ]
    }
    ```
*   **Note:** the strategy named one scene; the other two were found by changing subject and distance while keeping the same feeling — technical, capable, modern.

---

**CREATIVE STRATEGY TO ANALYZE:**
{$this->strategyContent}
PROMPT;
    }
}
