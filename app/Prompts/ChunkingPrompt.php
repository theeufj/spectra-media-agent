<?php

namespace App\Prompts;

class ChunkingPrompt
{
    private string $textToChunk;

    public function __construct(string $textToChunk)
    {
        $this->textToChunk = $textToChunk;
    }

    public function getPrompt(): string
    {
        // Use a HEREDOC for better readability
        return <<<PROMPT
You are an expert text-processing engine. Your task is to break the following text into semantically coherent chunks.

## Rules
1.  **Semantic Coherence:** Each chunk must be a self-contained piece of information, focusing on one specific topic, argument, or logical unit.
2.  **Completeness:** Do not split a single sentence across two chunks. A chunk must contain complete sentences.
3.  **Granularity:** Aim for chunks that are a few sentences to a paragraph long.
4.  **Output Format:** Respond with ONLY a valid JSON **object**.
5.  **Keys:** The keys of the JSON object must be **strings of sequential integers** (e.g., "1", "2", "3", ...), representing the order of the chunks.
6.  **Strictly NO extra text:** Do not include any conversational text, explanations, or markdown like ```json. Your response must start with `{` and end with `}`.

## Example
{"1": "This is the first logical chunk, which might be one or two paragraphs.", "2": "This is the second chunk. It discusses a new, related sub-topic.", "3": "And this is the final one."}

## Text to Chunk
{$this->textToChunk}

## JSON Output:
PROMPT;
    }
}