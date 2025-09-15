<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Review extends Model
{
    use HasFactory;

    protected $fillable = [
        'product',
        'rating',
        'text',
        'sentiment_label',
        'sentiment_confidence',
        'emotion_label',
        'emotion_confidence',
        'analyzed_at',
    ];
}


