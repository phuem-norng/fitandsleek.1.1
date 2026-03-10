import os
import requests
from dotenv import load_dotenv

load_dotenv()
model = os.getenv("HUGGINGFACE_MODEL")
token = os.getenv("HUGGINGFACE_API_TOKEN")

def probe(url, method="post", **kwargs):
    req_method = requests.get if method.lower() == "get" else requests.post
    resp = req_method(url, headers=kwargs.get("headers"), json=kwargs.get("json"), data=kwargs.get("data"))
    print("URL:", url)
    print("Status:", resp.status_code)
    print("Body:", resp.text)
    print("Headers:", resp.headers)
    print("\n" + "-"*40 + "\n")

probe(
    f"https://router.huggingface.co/models/{model}",
    headers={
        "Authorization": f"Bearer {token}",
        "Accept": "application/json",
        "Content-Type": "image/jpeg",
    },
    data=b"abc",
)

probe(
    "https://router.huggingface.co/pipeline/feature-extraction",
    headers={
        "Authorization": f"Bearer {token}",
        "Accept": "application/json",
    },
    json={
        "model": model,
        "inputs": {
            "image": "data:image/jpeg;base64,YWJj",
        },
    },
)

probe(
    f"https://api-inference.huggingface.co/models/{model}",
    headers={
        "Authorization": f"Bearer {token}",
        "Accept": "application/json",
        "Content-Type": "image/jpeg",
    },
    data=b"abc",
)

probe(
    "https://router.huggingface.co/",
    headers={
        "Authorization": f"Bearer {token}",
        "Accept": "application/json",
    },
    json={
        "model": model,
        "inputs": {
            "image": "data:image/jpeg;base64,YWJj",
        },
        "task": "feature-extraction",
    },
)

probe(
    "https://router.huggingface.co/v1/embeddings",
    headers={
        "Authorization": f"Bearer {token}",
        "Content-Type": "application/json",
    },
    json={
        "model": model,
        "input": "hello",
    },
)

probe(
    "https://router.huggingface.co/v1/models",
    headers={
        "Authorization": f"Bearer {token}",
    },
    method="get",
)

probe(
    "https://huggingface.co/api/whoami-v2",
    headers={"Authorization": f"Bearer {token}"},
    method="get",
)
