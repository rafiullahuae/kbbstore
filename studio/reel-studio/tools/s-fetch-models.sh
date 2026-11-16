#!/usr/bin/env bash
# Lane S harness only: fetch the word-alignment model into studio/reel-studio/models/ (git-ignored) so
# tools/s-check.cjs can run offline afterwards. The app itself never runs this: it asks the owner once and goes
# through engine.fetchModel() with the pinned sha256 in models/manifest.json.
set -e
M="$(cd "$(dirname "$0")/.." && pwd)/models"
get(){ mkdir -p "$(dirname "$M/$1/$2")"; [ -s "$M/$1/$2" ] || curl -sSfL --retry 3 -o "$M/$1/$2" "https://huggingface.co/$1/resolve/main/$2"; echo "ok $1/$2 $(stat -c%s "$M/$1/$2")"; }
for f in config.json preprocessor_config.json vocab.json tokenizer_config.json special_tokens_map.json onnx/model_q4.onnx; do
  get NewComer00/wav2vec2-xlsr-multilingual-56-ONNX "$f"
done
if [ "$1" = "--en" ]; then
  for f in config.json preprocessor_config.json tokenizer.json tokenizer_config.json onnx/model_quantized.onnx; do get Xenova/wav2vec2-base-960h "$f"; done
fi
echo DONE
