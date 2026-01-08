<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ActionImprovement extends Model
{
    use HasFactory;

    protected $fillable = [
        'incident_id',
        'title',
        'detail',
        'due_date',
        'pic_email',
        'reminder',
        'reminder_frequency',
        'status',
    ];

    protected $casts = [
        'pic_email' => 'array',
    ];

    public function incident()
    {
        return $this->belongsTo(Incident::class);
    }
}
