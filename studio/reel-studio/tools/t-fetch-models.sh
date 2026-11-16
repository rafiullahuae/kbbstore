#!/usr/bin/env bash
# Translation lane (T) harness only: fetch the translation candidates into studio/reel-studio/models/ (git-ignored) so
# tools/t-eval.cjs and tools/t-check.cjs run offline afterwards. The app itself never runs this: it asks the owner,
# then the engine's installer downloads from models/manifest.json.
#   bash tools/t-fetch-models.sh [chosen|all|madlad]   (default: chosen = only what the checks need)
set -e
M="$(cd "$(dirname "$0")/.." && pwd)/models"
get(){ mkdir -p "$(dirname "$M/$1/$2")"; [ -s "$M/$1/$2" ] || curl -sSfL --retry 5 -o "$M/$1/$2.part" "https://huggingface.co/$1/resolve/main/$2" && { [ -s "$M/$1/$2" ] || mv "$M/$1/$2.part" "$M/$1/$2"; }; echo "ok $1/$2 $(stat -c%s "$M/$1/$2")"; }
SEQ="config.json generation_config.json tokenizer.json tokenizer_config.json onnx/encoder_model_quantized.onnx onnx/decoder_model_merged_quantized.onnx"
opus(){ for p in "$@"; do for f in $SEQ; do get Xenova/opus-mt-$p $f; done; done; }
what="${1:-chosen}"
case "$what" in
  chosen) opus en-ar ar-en en-hi hi-en mul-en
          for f in $SEQ; do get Xenova/m2m100_418M $f; done ;;
  all)    opus en-ar ar-en en-hi hi-en en-mul mul-en
          for f in $SEQ; do get Xenova/m2m100_418M $f; done
          for f in config.json generation_config.json tokenizer.json tokenizer_config.json onnx/model_quantized.onnx; do get onnx-community/Qwen2.5-1.5B-Instruct $f; done ;;
  madlad) for f in config.json tokenizer.json tokenizer_config.json special_tokens_map.json onnx/encoder_model_quantized.onnx onnx/decoder_model_merged_quantized.onnx; do get Kutalia/madlad400-3b-mt-onnx $f; done ;;
esac
echo DONE
