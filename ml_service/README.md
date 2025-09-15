# Sentiment ML Service (FastAPI + NLTK VADER)

## Setup

```bash
# from project root
cd ml_service
python -m venv .venv
# Windows PowerShell
. .venv/Scripts/Activate.ps1
# macOS/Linux
# source .venv/bin/activate

pip install -r requirements.txt
```

## Run

```bash
uvicorn app:app --host 0.0.0.0 --port 8000 --reload
```

The service exposes:
- POST /analyze
- POST /evaluate
- POST /batch

Request body:
```json
{ "text": "I love this product!" }
```

Response body:
```json
{ "label": "positive", "confidence": 0.96, "emotion_label": "joy", "emotion_confidence": 0.88, "explanation": [["great", 0.23], ["amazing", 0.18]] }
```

### Evaluate

POST `/evaluate` with optional labeled samples:

```json
{
  "samples": [
    { "text": "I love it", "label": "positive" },
    { "text": "Terrible experience", "label": "negative" },
    { "text": "Works as expected", "label": "neutral" }
  ]
}
```

Response:

```json
{
  "accuracy": 0.667,
  "f1_macro": 0.667,
  "f1_micro": 0.667,
  "per_label_f1": { "negative": 1.0, "neutral": 0.5, "positive": 0.5 }
}
```

### Batch

POST `/batch` with a list of texts:

```json
{ "texts": ["I love it", "Terrible experience", "Works as expected"] }
```

Response:

```json
{
  "items": [
    { "text": "I love it", "label": "positive", "confidence": 0.98, "emotion_label": "joy", "emotion_confidence": 0.91 },
    { "text": "Terrible experience", "label": "negative", "confidence": 0.97, "emotion_label": "anger", "emotion_confidence": 0.88 },
    { "text": "Works as expected", "label": "neutral", "confidence": 0.72, "emotion_label": "neutral", "emotion_confidence": 0.60 }
  ]
}
```

## Configure Laravel

Set the ML server URL in `.env` of Laravel:

```env
ML_SERVER_URL=http://127.0.0.1:8000
```

Then your frontend page at `/sentiment` will POST to `/api/sentiment/analyze`, which forwards to this service.

## Configuration

Environment variables:

- `MODEL_BACKEND` (default: `naive_bayes`) — one of `vader`, `naive_bayes`, `distilbert`
- `DISTILBERT_MODEL` (default: `distilbert-base-uncased-finetuned-sst-2-english`)
-- `TRAIN_BALANCING` (default: `none`) — `none`, `class_weight`, or `oversample`
 - `CORS_ALLOWED_ORIGINS` (default: `*`, comma-separated list)
 - `CORS_SUPPORTS_CREDENTIALS` (default: `false`)
 - `CORS_EXPOSED_HEADERS` (default: ``)
 - `CORS_MAX_AGE` (default: `0`)
 - `EMOTION_ENABLED` (default: `true`)
 - `EMOTION_MODEL` (default: `bhadresh-savani/distilbert-base-uncased-emotion`)

Caching:

- Predictions are cached in-process using an LRU cache (size 4096) keyed by input text.

Balancing strategies (Naive Bayes backend):

- `class_weight`: use `compute_sample_weight(balanced)` during fit
- `oversample`: naive oversampling to equalize class counts

