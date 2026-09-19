# Image provider operation

The direct xAI path uses `AI_IMAGE_PROVIDER=xai` and
`AI_MODEL_IMAGE_XAI=grok-imagine-image-2.0`. Requests send `aspect_ratio`,
`response_format=b64_json` and `n=1`; they must not send OpenAI's `size` field.
Existing Forge environments pinned to the older image model need their
`AI_MODEL_IMAGE_XAI` setting updated, followed by config caching and a graceful
Horizon restart. Model names remain configured in `config/ai.php`.

Gemini handles reference images and fallback generation. All image callers use
the same atomic cache-backed pacer, scoped to the Google project and model:

| Setting | Default | Purpose |
| --- | --- | --- |
| `AI_GEMINI_IMAGE_INTERVAL_SECONDS` | 15 | Minimum gap between request starts across workers |
| `AI_GEMINI_IMAGE_MAX_WAIT_SECONDS` | 120 | Maximum time one call waits for admission |
| `AI_GEMINI_IMAGE_COOLDOWN_SECONDS` | 60 | Initial shared cooldown after HTTP 429 |

Repeated 429s increase the shared backoff to 300 seconds. A longer `Retry-After`
or Vertex `RetryInfo.retryDelay` takes precedence. Waiting workers recheck the
cooldown before sending. A call that cannot obtain admission within its wait
budget returns no image without contacting Google; existing caller retries and
visible failure reporting still apply. This smooths bursts but cannot fix a
daily quota or billing outage. The cache must be shared by all workers (Forge
uses the database cache); no lock is held during an API call or sleep.

References: [xAI image API](https://docs.x.ai/developers/model-capabilities/images/generation),
[Vertex 429 guidance](https://cloud.google.com/vertex-ai/generative-ai/docs/provisioned-throughput/error-code-429).
