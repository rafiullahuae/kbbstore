#!/usr/bin/env python3
"""Builds models/manifest.json from Hugging Face tree listings (one JSON per repo, fetched with
   curl "https://huggingface.co/api/models/<repo>/tree/main?recursive=1" -o <owner>_<name>.json).
   usage: python3 -I tools/make-model-manifest.py <dir with the listings> <out manifest.json>"""
import json, os, sys

SPEC = [
    ("whisper-small", "onnx-community/whisper-small", "Captions (accurate): speech to text, best for Arabic", "captions", True,
     ["config.json", "generation_config.json", "preprocessor_config.json", "tokenizer.json", "tokenizer_config.json", "onnx/encoder_model_fp16.onnx", "onnx/decoder_model_merged_q4.onnx"]),
    ("whisper-base", "onnx-community/whisper-base", "Captions (fast): speech to text, quicker on older Macs", "captions", True,
     ["config.json", "generation_config.json", "preprocessor_config.json", "tokenizer.json", "tokenizer_config.json", "onnx/encoder_model.onnx", "onnx/decoder_model_merged_q4.onnx"]),
    ("brief-llm", "onnx-community/Qwen2.5-0.5B-Instruct", "Writing: turns your brief into hooks, titles and on-screen text, offline", "auto", False,
     ["config.json", "generation_config.json", "tokenizer.json", "tokenizer_config.json", "onnx/model_q4f16.onnx"]),
    ("image-understanding", "onnx-community/Florence-2-base-ft", "Looking: describes what is in your video to suggest a recipe", "auto", False,
     ["config.json", "generation_config.json", "preprocessor_config.json", "tokenizer.json", "tokenizer_config.json", "onnx/vision_encoder_fp16.onnx", "onnx/encoder_model_fp16.onnx", "onnx/embed_tokens_fp16.onnx", "onnx/decoder_model_merged_q4.onnx"]),
    ("upscaler", "Xenova/swin2SR-classical-sr-x2-64", "Pictures: sharpens and enlarges small photos 2x", "auto", False,
     ["config.json", "preprocessor_config.json", "onnx/model_fp16.onnx"]),
    ("face-detector", "opencv/face_detection_yunet", "Reframe: finds faces so 16:9 video can be turned into 9:16 (YuNet, MIT)", "auto", True,
     ["face_detection_yunet_2023mar.onnx"]),
]

src, dst = sys.argv[1], sys.argv[2]
out = {"v": 1, "revision": "main",
       "note": "Models the studio downloads ONCE, after one Allow, into models/<repo>/<path>. Each file has its size and "
               "sha256 (large LFS files) or gitBlobSha1 (small text files). R2/R3 add entries; the engine installer reads this.",
       "models": []}
for mid, repo, purpose, owner, required, files in SPEC:
    tree = {e["path"]: e for e in json.load(open(os.path.join(src, repo.replace("/", "_") + ".json"))) if e["type"] == "file"}
    fl = []
    for p in files:
        e = tree[p]
        f = {"path": p, "size": e["size"]}
        if e.get("lfs"):
            f["sha256"] = e["lfs"]["oid"]
        else:
            f["gitBlobSha1"] = e["oid"]
        fl.append(f)
    out["models"].append({"id": mid, "repo": repo, "purpose": purpose, "owner": owner, "required": required,
                          "size": sum(x["size"] for x in fl), "files": fl})
with open(dst, "w") as fh:
    json.dump(out, fh, indent=1)
for m in out["models"]:
    print(m["id"], round(m["size"] / 1e6), "MB", "required" if m["required"] else "optional")
print(round(sum(m["size"] for m in out["models"]) / 1e9, 2), "GB in all")
