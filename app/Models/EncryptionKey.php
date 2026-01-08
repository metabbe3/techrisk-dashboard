<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EncryptionKey extends Model
{
    use HasFactory;

    protected $fillable = [
        'investigation_document_id',
        'key',
        'salt',
        'method',
    ];

    public function investigationDocument()
    {
        return $this->belongsTo(InvestigationDocument::class);
    }

    public function setKeyAttribute($value)
    {
        $this->attributes['key'] = base64_encode($value);
    }

    public function getKeyAttribute($value)
    {
        return base64_decode($value);
    }
}
