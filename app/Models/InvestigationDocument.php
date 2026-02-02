<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class InvestigationDocument extends Model
{
    use HasFactory;

    /**
     * Izinkan semua field untuk diisi melalui form (Mass Assignment).
     */
    protected $fillable = [
        'incident_id',
        'file_path',
        'description',
        'pic_status',
        'original_filename',
    ];

    /**
     * Mendefinisikan relasi bahwa dokumen ini dimiliki oleh sebuah insiden.
     */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function encryptionKey(): HasOne
    {
        return $this->hasOne(EncryptionKey::class);
    }
}
