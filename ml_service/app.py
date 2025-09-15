from fastapi import FastAPI, HTTPException
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel
from nltk.sentiment import SentimentIntensityAnalyzer
import nltk
import os
from functools import lru_cache
from typing import Literal

# Optional backends
from sklearn.feature_extraction.text import TfidfVectorizer
from sklearn.naive_bayes import MultinomialNB
from sklearn.pipeline import Pipeline
from sklearn.metrics import f1_score, accuracy_score
from sklearn.utils.class_weight import compute_sample_weight
from nltk.corpus import movie_reviews

from transformers import AutoTokenizer, AutoModelForSequenceClassification
import torch
from lime.lime_text import LimeTextExplainer


class AnalyzeRequest(BaseModel):
    text: str
    explain: bool | None = None


class AnalyzeResponse(BaseModel):
    label: str
    confidence: float
    emotion_label: str | None = None
    emotion_confidence: float | None = None
    explanation: list[tuple[str, float]] | None = None


class BatchAnalyzeRequest(BaseModel):
    texts: list[str]


class BatchAnalyzeItem(BaseModel):
    text: str
    label: str
    confidence: float
    emotion_label: str | None = None
    emotion_confidence: float | None = None


class BatchAnalyzeResponse(BaseModel):
    items: list[BatchAnalyzeItem]


BackendName = Literal["vader", "naive_bayes", "distilbert"]


app = FastAPI(title="Sentiment ML Service", version="0.2.0")

app.add_middleware(
    CORSMiddleware,
    allow_origins=[o.strip() for o in os.getenv("CORS_ALLOWED_ORIGINS", "*").split(",")],
    allow_credentials=os.getenv("CORS_SUPPORTS_CREDENTIALS", "false").lower() == "true",
    allow_methods=["*"],
    allow_headers=["*"],
    expose_headers=[h.strip() for h in os.getenv("CORS_EXPOSED_HEADERS", "").split(",") if h.strip()],
    max_age=int(os.getenv("CORS_MAX_AGE", "0")),
)


@app.on_event("startup")
def startup_event():
    try:
        nltk.data.find("sentiment/vader_lexicon.zip")
    except LookupError:
        nltk.download("vader_lexicon")
    # For NB backend
    try:
        nltk.data.find("corpora/movie_reviews.zip")
    except LookupError:
        nltk.download("movie_reviews")


sia = SentimentIntensityAnalyzer()


def get_backend() -> BackendName:
    name = os.getenv("MODEL_BACKEND", "naive_bayes").lower()
    if name not in {"vader", "naive_bayes", "distilbert"}:
        name = "naive_bayes"
    return name  # type: ignore


@lru_cache(maxsize=1)
def get_nb_model() -> Pipeline:
    docs: list[str] = []
    labels: list[str] = []
    for category in movie_reviews.categories():
        for fileid in movie_reviews.fileids(category):
            docs.append(movie_reviews.raw(fileid))
            labels.append("positive" if category == "pos" else "negative")

    balancing = os.getenv("TRAIN_BALANCING", "none").lower()  # none | class_weight | oversample

    if balancing == "oversample":
        # Simple naive oversampling to equalize class counts
        from collections import Counter
        counts = Counter(labels)
        max_count = max(counts.values())
        new_docs: list[str] = []
        new_labels: list[str] = []
        per_class: dict[str, list[int]] = {}
        for idx, y in enumerate(labels):
            per_class.setdefault(y, []).append(idx)
        for y, idxs in per_class.items():
            reps = max_count - len(idxs)
            new_docs.extend(docs)
            new_labels.extend(labels)
            if reps > 0:
                import random
                sampled = [random.choice(idxs) for _ in range(reps)]
                new_docs.extend([docs[i] for i in sampled])
                new_labels.extend([y for _ in sampled])
        docs, labels = new_docs, new_labels

    model = Pipeline([
        ("tfidf", TfidfVectorizer(stop_words="english", max_features=20000)),
        ("nb", MultinomialNB()),
    ])

    if balancing == "class_weight":
        sample_weight = compute_sample_weight(class_weight="balanced", y=labels)
        model.fit(docs, labels, nb__sample_weight=sample_weight)  # type: ignore[arg-type]
    else:
        model.fit(docs, labels)

    return model


@lru_cache(maxsize=1)
def get_distilbert():
    model_name = os.getenv("DISTILBERT_MODEL", "distilbert-base-uncased-finetuned-sst-2-english")
    tokenizer = AutoTokenizer.from_pretrained(model_name)
    model = AutoModelForSequenceClassification.from_pretrained(model_name)
    model.eval()
    return tokenizer, model


@lru_cache(maxsize=1)
def get_emotion_model():
    # Default to a small emotion model
    model_name = os.getenv("EMOTION_MODEL", "bhadresh-savani/distilbert-base-uncased-emotion")
    tokenizer = AutoTokenizer.from_pretrained(model_name)
    model = AutoModelForSequenceClassification.from_pretrained(model_name)
    model.eval()
    id2label = model.config.id2label
    return tokenizer, model, id2label


def _predict_with_vader(text: str) -> tuple[str, float]:
    scores = sia.polarity_scores(text)
    label = max(("negative", scores["neg"]), ("neutral", scores["neu"]), ("positive", scores["pos"]), key=lambda x: x[1])[0]
    confidence = float(max(scores["neg"], scores["neu"], scores["pos"]))
    return label, confidence


def _predict_with_nb(text: str) -> tuple[str, float]:
    model = get_nb_model()
    proba = model.predict_proba([text])[0]
    classes = list(model.classes_)
    idx = int(proba.argmax())
    label = classes[idx]
    confidence = float(proba[idx])
    return label, confidence


def _explain_with_lime_nb(text: str, num_features: int = 10) -> list[tuple[str, float]]:
    # Build a prediction function returning probabilities for class labels in the same order
    model = get_nb_model()
    class_names = list(model.classes_)
    explainer = LimeTextExplainer(class_names=class_names)

    def predict_proba(samples: list[str]):
        return model.predict_proba(samples)

    explanation = explainer.explain_instance(text, predict_proba, num_features=num_features)
    # Map contribution to the predicted class
    predicted_class = model.predict([text])[0]
    label_index = class_names.index(predicted_class)
    return explanation.as_list(label=label_index)


def _predict_with_distilbert(text: str) -> tuple[str, float]:
    tokenizer, model = get_distilbert()
    inputs = tokenizer(text, return_tensors="pt", truncation=True, max_length=256)
    with torch.no_grad():
        outputs = model(**inputs)
        probs = torch.softmax(outputs.logits, dim=-1).cpu().numpy()[0]
    # SST-2 labels: 0=NEGATIVE, 1=POSITIVE
    idx = int(probs.argmax())
    label = "positive" if idx == 1 else "negative"
    confidence = float(probs[idx])
    return label, confidence


def _predict_emotion(text: str) -> tuple[str, float]:
    tokenizer, model, id2label = get_emotion_model()
    inputs = tokenizer(text, return_tensors="pt", truncation=True, max_length=256)
    with torch.no_grad():
        outputs = model(**inputs)
        probs = torch.softmax(outputs.logits, dim=-1).cpu().numpy()[0]
    idx = int(probs.argmax())
    label = str(id2label[idx])
    confidence = float(probs[idx])
    return label, confidence


@lru_cache(maxsize=4096)
def cached_predict(text: str) -> tuple[str, float]:
    backend = get_backend()
    if backend == "vader":
        return _predict_with_vader(text)
    if backend == "distilbert":
        return _predict_with_distilbert(text)
    return _predict_with_nb(text)


@app.post("/analyze", response_model=AnalyzeResponse)
def analyze(req: AnalyzeRequest):
    content = (req.text or "").strip()
    if not content:
        raise HTTPException(status_code=422, detail="Text is required")

    label, confidence = cached_predict(content)
    emotion_enabled = os.getenv("EMOTION_ENABLED", "true").lower() == "true"
    emotion_label = None
    emotion_confidence = None
    if emotion_enabled:
        try:
            emotion_label, emotion_confidence = _predict_emotion(content)
        except Exception:
            emotion_label, emotion_confidence = None, None

    explanation = None
    if (req.explain or False) and get_backend() == "naive_bayes":
        try:
            explanation = _explain_with_lime_nb(content, num_features=int(os.getenv("LIME_NUM_FEATURES", "10")))
        except Exception:
            explanation = None

    return AnalyzeResponse(label=label, confidence=confidence, emotion_label=emotion_label, emotion_confidence=emotion_confidence, explanation=explanation)


@app.post("/batch", response_model=BatchAnalyzeResponse)
def batch(req: BatchAnalyzeRequest):
    if not isinstance(req.texts, list) or len(req.texts) == 0:
        raise HTTPException(status_code=422, detail="texts must be a non-empty list")
    items: list[BatchAnalyzeItem] = []
    emotion_enabled = os.getenv("EMOTION_ENABLED", "true").lower() == "true"
    for t in req.texts:
        content = (t or "").strip()
        if not content:
            continue
        label, confidence = cached_predict(content)
        e_label = None
        e_conf = None
        if emotion_enabled:
            try:
                e_label, e_conf = _predict_emotion(content)
            except Exception:
                e_label, e_conf = None, None
        items.append(BatchAnalyzeItem(text=content, label=label, confidence=float(confidence), emotion_label=e_label, emotion_confidence=e_conf))
    if not items:
        raise HTTPException(status_code=422, detail="No valid texts provided")
    return BatchAnalyzeResponse(items=items)


class EvaluateSample(BaseModel):
    text: str
    label: str


class EvaluateRequest(BaseModel):
    samples: list[EvaluateSample] | None = None


class EvaluateMetrics(BaseModel):
    accuracy: float
    f1_macro: float
    f1_micro: float
    per_label_f1: dict[str, float]


def _predict_label(text: str) -> str:
    label, _ = cached_predict(text)
    return label


@app.post("/evaluate", response_model=EvaluateMetrics)
def evaluate(req: EvaluateRequest):
    samples = req.samples or [
        EvaluateSample(text="I love this product", label="positive"),
        EvaluateSample(text="This is the worst thing ever", label="negative"),
        EvaluateSample(text="It works as expected", label="neutral"),
        EvaluateSample(text="Absolutely fantastic experience", label="positive"),
        EvaluateSample(text="Not good, very disappointed", label="negative"),
    ]

    if len(samples) == 0:
        raise HTTPException(status_code=422, detail="At least one sample is required")

    true_labels: list[str] = []
    pred_labels: list[str] = []
    for s in samples:
        t = (s.text or "").strip()
        if not t:
            continue
        true_labels.append(s.label)
        pred_labels.append(_predict_label(t))

    if len(true_labels) == 0:
        raise HTTPException(status_code=422, detail="No valid samples provided")

    labels = sorted(set(true_labels) | set(pred_labels))
    # Confusion counts
    tp: dict[str, int] = {l: 0 for l in labels}
    fp: dict[str, int] = {l: 0 for l in labels}
    fn: dict[str, int] = {l: 0 for l in labels}

    for y_true, y_pred in zip(true_labels, pred_labels):
        if y_true == y_pred:
            tp[y_true] += 1
        else:
            fp[y_pred] += 1
            fn[y_true] += 1

    per_label_f1: dict[str, float] = {}
    sum_f1 = 0.0
    for l in labels:
        precision = tp[l] / (tp[l] + fp[l]) if (tp[l] + fp[l]) > 0 else 0.0
        recall = tp[l] / (tp[l] + fn[l]) if (tp[l] + fn[l]) > 0 else 0.0
        f1 = (2 * precision * recall / (precision + recall)) if (precision + recall) > 0 else 0.0
        per_label_f1[l] = round(f1, 6)
        sum_f1 += f1

    macro_f1 = sum_f1 / len(labels) if labels else 0.0

    total_tp = sum(tp.values())
    total_fp = sum(fp.values())
    total_fn = sum(fn.values())
    precision_micro = total_tp / (total_tp + total_fp) if (total_tp + total_fp) > 0 else 0.0
    recall_micro = total_tp / (total_tp + total_fn) if (total_tp + total_fn) > 0 else 0.0
    micro_f1 = (2 * precision_micro * recall_micro / (precision_micro + recall_micro)) if (precision_micro + recall_micro) > 0 else 0.0

    accuracy = sum(1 for y, p in zip(true_labels, pred_labels) if y == p) / len(true_labels)

    return EvaluateMetrics(
        accuracy=round(float(accuracy), 6),
        f1_macro=round(float(macro_f1), 6),
        f1_micro=round(float(micro_f1), 6),
        per_label_f1=per_label_f1,
    )


