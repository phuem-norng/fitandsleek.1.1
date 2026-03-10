import base64
import logging
import os
import sys
from typing import Optional
from urllib.parse import unquote_to_bytes

import requests
from dotenv import load_dotenv
from psycopg2 import connect
from psycopg2.extras import RealDictCursor
from qdrant_client import QdrantClient, models

load_dotenv()

LOG_PATH = "storage/logs/qdrant_sync_clip.log"
logging.basicConfig(
    filename=LOG_PATH,
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(message)s",
    encoding="utf-8",
)
logger = logging.getLogger("qdrant_sync_clip")
logger.addHandler(logging.StreamHandler(sys.stdout))


def decode_data_url(url: str) -> Optional[bytes]:
    """Decode a data URL (data:[<mediatype>][;base64],<data>)."""
    try:
        header, data_part = url.split(",", 1)
    except ValueError:
        logger.error("Invalid data URL: missing comma separator")
        return None

    try:
        if ";base64" in header.lower():
            return base64.b64decode(data_part)
        return unquote_to_bytes(data_part)
    except Exception as exc:  # broad for robustness
        logger.error("Failed to decode data URL", exc_info=exc)
        return None


def fetch_image(url: str, timeout: int = 30) -> Optional[bytes]:
    if url.startswith("data:"):
        return decode_data_url(url)

    try:
        resp = requests.get(url, timeout=timeout)
        resp.raise_for_status()
        return resp.content
    except Exception as exc:  # broad for logging clarity
        logger.error("Failed to download image %s", url, exc_info=exc)
        return None


def embed_image(image_bytes: bytes) -> Optional[list]:
    endpoint = os.getenv("LOCAL_CLIP_ENDPOINT", "http://127.0.0.1:8000/embed")
    try:
        resp = requests.post(
            endpoint,
            files={"file": ("image.jpg", image_bytes, "image/jpeg")},
            timeout=60,
            verify=False,
        )
        resp.raise_for_status()
        data = resp.json()
        embedding = data.get("embedding")
        if not isinstance(embedding, list):
            logger.error("Local CLIP response missing embedding field")
            return None
        return [float(x) for x in embedding]
    except Exception as exc:
        logger.error("Failed to embed image via local CLIP", exc_info=exc)
        return None


def main():
    qdrant_url = os.getenv("QDRANT_URL")
    qdrant_api_key = os.getenv("QDRANT_API_KEY")
    collection = os.getenv("QDRANT_COLLECTION", "products_clip_512")
    vector_size = int(os.getenv("QDRANT_VECTOR_SIZE", "512"))

    db_url = os.getenv("DATABASE_URL")
    if not db_url:
        raise SystemExit("DATABASE_URL is missing")

    logger.info("Connecting to Qdrant %s", qdrant_url)
    client = QdrantClient(
        url=qdrant_url,
        api_key=qdrant_api_key,
        prefer_grpc=False,
        timeout=60,
    )

    logger.info("Creating collection %s (size=%s)", collection, vector_size)
    client.recreate_collection(
        collection_name=collection,
        vectors_config=models.VectorParams(size=vector_size, distance=models.Distance.COSINE),
    )

    logger.info("Connecting to database")
    conn = connect(db_url)
    cur = conn.cursor(cursor_factory=RealDictCursor)
    cur.execute("SELECT id, name, image_url FROM products WHERE image_url IS NOT NULL")
    products = cur.fetchall()
    logger.info("Found %s products", len(products))

    base_url = os.getenv("APP_URL", "http://127.0.0.1:8000").rstrip("/")

    for row in products:
        pid = row["id"]
        name = row.get("name") or ""
        raw_url = (row.get("image_url") or "").strip()

        if not raw_url:
            logger.error("Skip ID %s: image_url empty", pid)
            continue

        if raw_url.startswith("data:"):
            image_url = raw_url
        elif raw_url.startswith("http://") or raw_url.startswith("https://"):
            image_url = raw_url
        else:
            image_url = f"{base_url}/{raw_url.lstrip('/')}"

        logger.info("Processing ID %s (%s)", pid, name)

        image_bytes = fetch_image(image_url)
        if not image_bytes:
            logger.error("Skip ID %s: could not fetch image", pid)
            continue

        vector = embed_image(image_bytes)
        if not vector:
            logger.error("Skip ID %s: no embedding returned", pid)
            continue

        client.upsert(
            collection_name=collection,
            points=[
                models.PointStruct(
                    id=pid,
                    vector=vector,
                    payload={"product_id": pid, "name": name, "image_url": image_url},
                )
            ],
        )
        logger.info("Synced ID %s", pid)

    cur.close()
    conn.close()
    logger.info("Sync completed. Log: %s", LOG_PATH)


if __name__ == "__main__":
    try:
        main()
    except Exception as exc:
        logger.exception("Fatal error during CLIP sync", exc_info=exc)
        raise
