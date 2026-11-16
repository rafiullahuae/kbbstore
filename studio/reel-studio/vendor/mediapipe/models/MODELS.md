# MediaPipe models (offline, vendored — no download)

| File | Task | Size | sha256 | Licence | Used by |
|---|---|---|---|---|---|
| blaze_face_short_range.tflite | face detector | 230 KB | b4578f35…0152f | Apache-2.0 | app/auto (auto-reframe) |
| selfie_segmenter.tflite (float16, latest) | person segmentation | 244 KB | 191ac952…658b | Apache-2.0 | app/look (background removal, object-cover transition) |
| face_landmarker.task (float16, latest) | 478 face landmarks | 3.6 MB | 64184e22…9ff | Apache-2.0 | app/look (skin touch-up) |

From storage.googleapis.com/mediapipe-models/… (the MediaPipe Tasks model zoo); run by tasks-vision 0.10.21 in a
worker on the CPU. Licence: ../LICENSE.txt (Apache-2.0).
