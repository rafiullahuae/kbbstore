#!/usr/bin/env python3
"""Builds models/manifest.json from Hugging Face tree listings (one JSON per repo, fetched with
   curl "https://huggingface.co/api/models/<repo>/tree/main?recursive=1" -o <owner>_<name>.json).
   usage: python3 -I tools/make-model-manifest.py <dir with the listings> <out manifest.json>"""
import json, os, sys

# The speech models are the *_timestamped exports (cross-attention outputs for word-level times; the plain
# onnx-community/whisper-* exports cannot give word timestamps). Each carries BOTH runtimes app/captions/models.js
# picks between: WASM = int8 ("_quantized") encoder + decoder; WebGPU = fp16 encoder + 4-bit ("_q4") decoder.
WHISPER = ["config.json", "generation_config.json", "preprocessor_config.json", "tokenizer.json", "tokenizer_config.json",
           "onnx/encoder_model_quantized.onnx", "onnx/decoder_model_merged_quantized.onnx",
           "onnx/encoder_model_fp16.onnx", "onnx/decoder_model_merged_q4.onnx"]
# The face finder (auto-reframe) is NOT here: app/auto uses MediaPipe BlazeFace, vendored with the app
# (vendor/mediapipe/models/blaze_face_short_range.tflite, 230 KB), so it needs no download.
SPEC = [
    ("whisper-small", "onnx-community/whisper-small_timestamped", "Captions (accurate): speech to text with word timing, best for Arabic", "captions", True, WHISPER),
    ("whisper-base", "onnx-community/whisper-base_timestamped", "Captions (fast): speech to text with word timing, quicker on older Macs", "captions", True, WHISPER),
    ("brief-llm", "onnx-community/Qwen2.5-0.5B-Instruct", "Writing: turns your brief into hooks, titles and on-screen text, offline (Brief to Designs, next update)", "auto", False,
     ["config.json", "generation_config.json", "tokenizer.json", "tokenizer_config.json", "onnx/model_q4f16.onnx"]),
    ("image-understanding", "onnx-community/Florence-2-base-ft", "Looking: describes what is in your video to suggest a recipe (next update)", "auto", False,
     ["config.json", "generation_config.json", "preprocessor_config.json", "tokenizer.json", "tokenizer_config.json", "onnx/vision_encoder_fp16.onnx", "onnx/encoder_model_fp16.onnx", "onnx/embed_tokens_fp16.onnx", "onnx/decoder_model_merged_q4.onnx"]),
    # Lane C (round 2, app/content). The tagger is REQUIRED: auto-tagging on import is what lets b-roll match your
    # photos and clips by what is in them (CLIP zero-shot against the studio's own tag list; MIT, int8, 156 MB).
    # Post copy runs a local instruct model: Qwen2.5-1.5B (Apache-2.0) 4-bit/fp16 on the Mac GPU (WebGPU), and
    # Qwen2.5-0.5B (Apache-2.0) int8 on the CPU (WASM) when there is no WebGPU. Both optional: a rule-based writer
    # covers the post copy when neither is installed.
    ("media-tagger", "Xenova/clip-vit-base-patch32", "B-roll: tags your photos and clips on import (what is in them) so b-roll matches your words", "content", True,
     ["config.json", "preprocessor_config.json", "tokenizer.json", "tokenizer_config.json", "onnx/vision_model_quantized.onnx", "onnx/text_model_quantized.onnx"]),
    ("copy-llm", "onnx-community/Qwen2.5-1.5B-Instruct", "Writing: Instagram/TikTok captions, keywords, hashtags and comment prompts in English and Arabic, offline on the Mac's GPU", "content", False,
     ["config.json", "generation_config.json", "tokenizer.json", "tokenizer_config.json", "onnx/model_q4f16.onnx"]),
    ("copy-llm-lite", "onnx-community/Qwen2.5-0.5B-Instruct", "Writing (no-GPU fallback): the same post copy on the CPU when the browser has no WebGPU", "content", False,
     ["config.json", "generation_config.json", "tokenizer.json", "tokenizer_config.json", "onnx/model_quantized.onnx"]),
    ("upscaler", "Xenova/swin2SR-classical-sr-x2-64", "Pictures (upscale): sharpens and enlarges AI pictures and small photos 2x, for full-HD designs", "designs", False,
     ["config.json", "preprocessor_config.json", "onnx/model_fp16.onnx"], {"group": "Image AI", "licence": "Apache-2.0"}),
    # Round 3 (Brief -> Designs): the offline picture model. SDXS-512-DreamShaper (IDKiro, CreativeML Open RAIL++-M:
    # commercial use allowed, with use restrictions) -- a ONE-step distillation of Stable Diffusion 1.5 / DreamShaper 8, so
    # a picture is one UNet pass (seconds on a Mac GPU, ~14 s on a CPU). ONNX conversion by creador (fp16, WebGPU-ready,
    # TAESD for previews and SD1.5's full VAE decoder for the kept picture). Chosen over SD-Turbo (Stability AI Community
    # Licence: commercial use needs registration and ends above US$1M revenue) because it is openly licensed, 2.6x smaller
    # (983 MB vs 2.58 GB) and one step instead of 1-4.
    ("image-ai", "creador/sdxs-512-dreamshaper-onnx", "Pictures (AI): makes backgrounds, scenes, artwork and worker photos offline on the Mac's GPU, for Brief to Designs", "designs", False,
     ["manifest.json", "tokenizer.json", "tokenizer_config.json", "text_encoder_fp16.onnx", "unet_fp16.onnx", "taesd_decoder_fp16.onnx", "vae_large_decoder_fp16.onnx"],
     {"group": "Image AI", "licence": "CreativeML Open RAIL++-M (use restrictions apply)", "licenceUrl": "https://huggingface.co/spaces/CompVis/stable-diffusion-license"}),
]

src, dst = sys.argv[1], sys.argv[2]
out = {"v": 1, "revision": "main",
       "note": "Models the studio downloads ONCE, after one Allow, into models/<repo>/<path>. Each file has its size and "
               "sha256 (large LFS files) or gitBlobSha1 (small text files). Built by tools/make-model-manifest.py; the engine installer reads this and app/captions/models.js loads from the same models/<repo>/ paths.",
       "models": []}
for mid, repo, purpose, owner, required, files, *extra in SPEC:
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
    out["models"].append(dict({"id": mid, "repo": repo, "purpose": purpose, "owner": owner, "required": required,
                               "size": sum(x["size"] for x in fl), "files": fl}, **(extra[0] if extra else {})))
with open(dst, "w") as fh:
    json.dump(out, fh, indent=1)
for m in out["models"]:
    print(m["id"], round(m["size"] / 1e6), "MB", "required" if m["required"] else "optional")
print(round(sum(m["size"] for m in out["models"]) / 1e9, 2), "GB in all")
