# FitAndSleek AI Image Search

Backend + FastAPI embedding service + Qdrant vector search.

## Components

- FastAPI CLIP service (vision_local/, deploy to Hugging Face Spaces via Docker)
- Laravel backend orchestrator calling the embedding endpoint and Qdrant
- Scheduler keep-alive ping every 15m to prevent cold starts

## Deploy (FastAPI on Hugging Face)

1. Create Docker Space, upload `vision_local/` files (main.py, requirements.txt, Dockerfile).
2. Set secrets (Space > Settings):
    - `AI_SERVICE_KEY` (recommended)
3. Test docs: `https://<space>.hf.space/docs`

## Laravel setup

- `.env`:
    - `LOCAL_CLIP_ENDPOINT=https://<space>.hf.space/embed`
    - `AI_SERVICE_KEY=<same-as-space>`
    - `QDRANT_URL`, `QDRANT_API_KEY`, `QDRANT_COLLECTION`, `QDRANT_VECTOR_SIZE`
- `php artisan config:clear`
- Enable scheduler via cron: `* * * * * cd /path && php artisan schedule:run >> /dev/null 2>&1`

## Security

- FastAPI checks `x-api-key` header when `AI_SERVICE_KEY` is set.
- Laravel sends the key automatically when calling local/HF endpoint.

## Notes

- Model: `clip-ViT-B-32` (CPU), ~400-500MB download, ~1-1.5GB RAM runtime.
- Cold start: free Spaces may sleep; keep-alive ping mitigates.
