#!/usr/bin/env bash
# Lane R2 harness only: fetch the speech models + TTS voices + a public-domain sample into
# studio/reel-studio/models/ (git-ignored) so tools/r2-check.cjs can run offline afterwards.
# The app itself never runs this: it asks the owner, then goes through engine.fetchModel().
set -e
M="$(cd "$(dirname "$0")/.." && pwd)/models"
get(){ mkdir -p "$(dirname "$M/$1/$2")"; [ -s "$M/$1/$2" ] || curl -sSfL --retry 3 -o "$M/$1/$2" "https://huggingface.co/$1/resolve/main/$2"; echo "ok $1/$2 $(stat -c%s "$M/$1/$2")"; }
for r in onnx-community/whisper-base_timestamped onnx-community/whisper-small_timestamped; do
  for f in config.json generation_config.json preprocessor_config.json tokenizer.json tokenizer_config.json \
           onnx/encoder_model_quantized.onnx onnx/decoder_model_merged_quantized.onnx; do get $r $f; done
done
for r in Xenova/mms-tts-eng Xenova/mms-tts-ara; do
  for f in config.json tokenizer.json tokenizer_config.json onnx/model.onnx; do get $r $f; done
done
mkdir -p "$M/_r2-test"
# JFK inaugural address excerpt (US government work, public domain).
[ -s "$M/_r2-test/jfk.wav" ] || curl -sSfL -o "$M/_r2-test/jfk.wav" \
  https://huggingface.co/datasets/Xenova/transformers.js-docs/resolve/main/jfk.wav
echo DONE
