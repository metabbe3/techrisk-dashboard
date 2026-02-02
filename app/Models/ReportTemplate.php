<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReportTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'user_id',
        'filters',
        'columns',
        'metrics',
        'email',
        'schedule',
    ];

    protected $casts = [
        'filters' => 'array',
        'columns' => 'array',
        'metrics' => 'array',
    ];
}
