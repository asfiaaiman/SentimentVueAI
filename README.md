# Sentiment Analysis App with REST API

A full-stack sentiment analysis application built with Laravel 11, Vue 3, Inertia.js, and a Python FastAPI ML service. The app provides real-time sentiment analysis with multiple ML backends and emotion detection.

## Features

- **Real-time sentiment analysis** with multiple ML backends (VADER, Naive Bayes, DistilBERT)
- **Emotion detection** using transformer models
- **Batch processing** for CSV files and social media handles
- **REST API** for integration with other applications
- **Modern UI** built with Vue 3, Inertia.js, and Tailwind CSS
- **Graceful error handling** when ML service is unavailable
- **Caching** for improved performance

## Tech Stack

### Backend
- **Laravel 11** - PHP framework
- **SQLite** - Database (included)
- **Laravel Sanctum** - API authentication
- **Laravel Fortify** - Authentication features
- **Laravel Queue** - Background job processing

### Frontend
- **Vue 3** - JavaScript framework
- **Inertia.js** - SPA without API complexity
- **Tailwind CSS** - Utility-first CSS framework
- **Vite** - Build tool

### ML Service
- **FastAPI** - Python web framework
- **NLTK VADER** - Rule-based sentiment analysis
- **Scikit-learn** - Naive Bayes classifier
- **Transformers** - DistilBERT model
- **LIME** - Model explainability

## Prerequisites

- **PHP 8.2+** with Composer
- **Node.js 18+** with npm
- **Python 3.10+** with pip
- **SQLite** (included with PHP)
- **Laravel Herd** (recommended) or use `php artisan serve`

## Quick Start

### 1. Clone and Install Dependencies

```bash
# Clone the repository
git clone <repository-url>
cd sentiment-analysis-app-with-restAPI

# Install PHP dependencies
composer install

# Install Node.js dependencies
npm install

# Create environment file
cp .env.example .env

# Generate application key
php artisan key:generate

# Run database migrations
php artisan migrate
```

### 2. Configure ML Service

Update `.env` to point to the ML service:

```env
ML_SERVER_URL=http://127.0.0.1:8010
ML_SERVER_TIMEOUT=30
ML_CACHE_TTL=86400
```

### 3. Set Up Python ML Service

```bash
# Navigate to ML service directory
cd ml_service

# Create virtual environment
python -m venv .venv

# Activate virtual environment
# Windows PowerShell:
. .venv\Scripts\Activate.ps1
# macOS/Linux:
# source .venv/bin/activate

# Install dependencies
pip install -r requirements.txt

# Download required NLTK data
python -c "import nltk; nltk.download('vader_lexicon'); nltk.download('movie_reviews')"
```

### 4. Start the Services

#### Option A: Using Composer (Recommended)
```bash
# From project root - starts Laravel, queue worker, and Vite
composer run dev
```

#### Option B: Manual Setup
```bash
# Terminal 1: Start ML service
cd ml_service
. .venv\Scripts\Activate.ps1
$env:MODEL_BACKEND="vader"
$env:EMOTION_ENABLED="false"
uvicorn app:app --host 127.0.0.1 --port 8010 --reload

# Terminal 2: Start Laravel with queue worker
php artisan serve
php artisan queue:work

# Terminal 3: Start Vite (if not using composer run dev)
npm run dev
```

### 5. Access the Application

- **Web Interface**: `http://127.0.0.1:8000/sentiment`
- **API Documentation**: `http://127.0.0.1:8000/api/sentiment/analyze`
- **ML Service**: `http://127.0.0.1:8010/analyze`

## Configuration

### ML Service Configuration

Set environment variables before starting the ML service:

```bash
# Backend options: vader, naive_bayes, distilbert
$env:MODEL_BACKEND="vader"

# Enable/disable emotion detection
$env:EMOTION_ENABLED="false"

# CORS settings
$env:CORS_ALLOWED_ORIGINS="http://127.0.0.1:8000"
```

### Laravel Configuration

Key configuration in `.env`:

```env
# ML Service
ML_SERVER_URL=http://127.0.0.1:8010
ML_SERVER_TIMEOUT=30
ML_CACHE_TTL=86400

# Database (SQLite by default)
DB_CONNECTION=sqlite
DB_DATABASE=database/database.sqlite

# App URL
APP_URL=http://127.0.0.1:8000
```

## API Endpoints

### Sentiment Analysis

#### Single Text Analysis
```bash
POST /api/sentiment/analyze
Content-Type: application/json

{
  "text": "I love this product!",
  "async": false
}
```

Response:
```json
{
  "label": "positive",
  "confidence": 0.692
}
```

#### Batch Analysis
```bash
POST /api/sentiment/batch-csv
Content-Type: multipart/form-data

file: [CSV file]
has_header: true
column: "text"
```

#### Async Analysis
```bash
# Submit job
POST /api/sentiment/analyze
{
  "text": "I love this product!",
  "async": true
}

# Check status
GET /api/sentiment/status/{request_id}
```

### ML Service Direct Endpoints

#### Analyze
```bash
POST http://127.0.0.1:8010/analyze
{
  "text": "I love this product!",
  "explain": true
}
```

#### Batch
```bash
POST http://127.0.0.1:8010/batch
{
  "texts": ["I love it", "This is terrible", "It's okay"]
}
```

#### Evaluate
```bash
POST http://127.0.0.1:8010/evaluate
{
  "samples": [
    {"text": "I love it", "label": "positive"},
    {"text": "Terrible experience", "label": "negative"}
  ]
}
```

## ML Backends

### VADER (Default)
- **Best for**: Short texts, social media posts
- **Speed**: Very fast
- **Accuracy**: Good for general sentiment

### Naive Bayes
- **Best for**: Longer texts, reviews
- **Speed**: Fast
- **Accuracy**: Good with training data
- **Features**: LIME explanations available

### DistilBERT
- **Best for**: Complex texts, nuanced sentiment
- **Speed**: Slower (first load downloads model)
- **Accuracy**: Highest
- **Features**: Transformer-based, state-of-the-art

## Troubleshooting

### Common Issues

#### ML Service Connection Errors
```bash
# Check if ML service is running
netstat -ano | findstr :8010

# Test ML service directly
Invoke-RestMethod -Method Post -Uri http://127.0.0.1:8010/analyze -ContentType 'application/json' -Body '{"text":"test"}'
```

#### Port Conflicts
- ML service: Use port 8010
- Laravel: Use port 8000 (default) or 8001
- Vite: Auto-proxies through Laravel

#### Cache Issues
```bash
# Clear all caches
php artisan cache:clear
php artisan config:clear
php artisan route:clear
```

#### NLTK Data Missing
```bash
cd ml_service
. .venv\Scripts\Activate.ps1
python -c "import nltk; nltk.download('vader_lexicon'); nltk.download('movie_reviews')"
```

### Performance Tips

1. **First ML request** may be slow due to model downloads
2. **Disable emotion detection** for faster responses: `$env:EMOTION_ENABLED="false"`
3. **Use VADER** for fastest responses: `$env:MODEL_BACKEND="vader"`
4. **Enable caching** in Laravel (already configured)

## Development

### Running Tests
```bash
# PHP tests
vendor\bin\pest

# Frontend linting
npm run lint
npm run format:check
```

### Code Style
```bash
# Format PHP code
vendor\bin\pint

# Format frontend code
npm run format
```

## Production Deployment

### ML Service
- Use a production ASGI server like Gunicorn with Uvicorn workers
- Set up proper environment variables
- Consider using Docker for containerization

### Laravel App
- Use a production web server (Nginx/Apache)
- Set up proper database (PostgreSQL/MySQL)
- Configure queue workers with Supervisor
- Enable caching and optimization

## Contributing

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Run tests and linting
5. Submit a pull request

## License

This project is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
