#!/bin/bash

# A script to test image generation with the Gemini API.
#
# Usage:
# 1. Set your GEMINI_API_KEY environment variable:
#    export GEMINI_API_KEY="your_api_key_here"
# 2. Make the script executable:
#    chmod +x generate_image.sh
# 3. Run the script with your prompt:
#    ./generate_image.sh "A futuristic cityscape at sunset, with flying cars and neon lights."

set -e # Exit immediately if a command exits with a non-zero status.

# --- Configuration ---
MODEL_ID="gemini-2.5-flash-image"
API_ENDPOINT="https://generativelanguage.googleapis.com/v1beta/models/${MODEL_ID}:streamGenerateContent"
PROMPT="$1"

# --- Validation ---
if [ -z "$GEMINI_API_KEY" ]; then
    echo "Error: GEMINI_API_KEY environment variable is not set."
    echo "Please set it before running the script:"
    echo "export GEMINI_API_KEY=\"your_api_key_here\""
    exit 1
fi

if [ -z "$PROMPT" ]; then
    echo "Error: No prompt provided."
    echo "Usage: ./generate_image.sh \"Your image prompt here.\""
    exit 1
fi

# --- Create JSON Payload ---
# Using a heredoc to create the JSON payload.
# The prompt is safely escaped by placing it in quotes.
cat << EOF > request.json
{
    "contents": [
      {
        "role": "user",
        "parts": [
          {
            "text": "$PROMPT"
          }
        ]
      }
    ],
    "generationConfig": {
      "responseModalities": ["IMAGE", "TEXT"],
      "imageConfig": {
        "image_size": "1K"
      }
    }
}
EOF

echo "Generated request.json with your prompt."

# --- Send Request ---
echo "Sending request to Gemini API..."

curl \
-X POST \
-H "Content-Type: application/json" \
"${API_ENDPOINT}?key=${GEMINI_API_KEY}" -d '@request.json' -o response.json

echo "Response saved to response.json"
echo "Done."
