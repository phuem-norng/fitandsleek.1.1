import os
import requests
import logging
from dotenv import load_dotenv
from psycopg2 import connect
from qdrant_client import QdrantClient, models

# Load config ពី .env
load_dotenv()

logging.basicConfig(
    filename="storage/logs/qdrant_sync_debug.log",
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(message)s",
    encoding="utf-8",
)
logger = logging.getLogger("qdrant_sync")
logger.addHandler(logging.StreamHandler())


def get_vector_from_cohere(text: str):
    api_key = os.getenv("COHERE_API_KEY")
    model = os.getenv("COHERE_MODEL", "embed-multilingual-v3.0")

    if not api_key:
        raise Exception("Cohere API key is missing (COHERE_API_KEY)")

    url = "https://api.cohere.com/v1/embed"
    headers = {
        "Authorization": f"Bearer {api_key}",
        "Content-Type": "application/json",
        "Accept": "application/json",
    }

    payload = {
        "model": model,
        "texts": [text],
        "input_type": "search_document",
    }

    try:
        response = requests.post(url, headers=headers, json=payload, timeout=60)
    except requests.RequestException as exc:
        raise Exception(f"Cohere request failed: {exc}") from exc

    if response.status_code != 200:
        raise Exception(f"Cohere API Error: {response.status_code} {response.text}")

    try:
        data = response.json()
    except ValueError as exc:
        raise Exception(f"Cohere API Error: invalid JSON response: {exc}") from exc

    embeddings = data.get("embeddings")
    if not embeddings or not isinstance(embeddings, list):
        raise Exception("Cohere API Error: missing embeddings in response")

    return [float(v) for v in embeddings[0]]

def main():
    # ១. តភ្ជាប់ទៅ Qdrant
    client = QdrantClient(
        url=os.getenv("QDRANT_URL"), 
        api_key=os.getenv("QDRANT_API_KEY"),
        prefer_grpc=False,
        timeout=60,
    )
    
    collection_name = os.getenv("QDRANT_COLLECTION", "products_cohere_1024")
    vector_size = int(os.getenv("QDRANT_VECTOR_SIZE", "1024"))
    
    # ២. បង្កើត Collection ថ្មីទំហំ 1024
    logger.info(f"Creating collection {collection_name} (size={vector_size})...")
    client.recreate_collection(
        collection_name=collection_name,
        vectors_config=models.VectorParams(size=vector_size, distance=models.Distance.COSINE)
    )

    # ៣. ទាញទិន្នន័យពី PostgreSQL (Render)
    conn = connect(os.getenv("DATABASE_URL"))
    cur = conn.cursor()
    cur.execute("SELECT id, name, description FROM products WHERE name IS NOT NULL")
    products = cur.fetchall()

    logger.info(f"Found {len(products)} products. Starting sync...")

    for p_id, p_name, p_desc in products:
        try:
            logger.info(f"Processing ID {p_id} ({p_name})")
            text = (p_name or "").strip()
            if p_desc:
                text = f"{text}. {p_desc.strip()}" if text else p_desc.strip()

            if not text:
                raise Exception("Missing text content for embedding")

            # បំប្លែងជា Vector តាម Cohere (text embedding)
            vector = get_vector_from_cohere(text)

            # បាញ់ចូល Qdrant
            client.upsert(
                collection_name=collection_name,
                points=[models.PointStruct(
                    id=p_id,
                    vector=vector,
                    payload={"name": p_name, "description": p_desc}
                )]
            )
            logger.info(f"Synced: {p_name}")
            
        except Exception as e:
            logger.error(f"Error for ID {p_id}: {e}")
    cur.close()
    conn.close()
    logger.info("Sync completed.")

if __name__ == "__main__":
    try:
        main()
    except Exception as exc:
        logger.exception("Fatal error during sync", exc_info=exc)
        raise