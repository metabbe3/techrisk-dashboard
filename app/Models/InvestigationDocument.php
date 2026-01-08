<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory; // <-- DITAMBAHKAN
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;   // <-- DIPINDAHKAN KE ATAS

class InvestigationDocument extends Model
{
    use HasFactory;

    /**
     * Izinkan semua field untuk diisi melalui form (Mass Assignment).
     */
    protected $guarded = [];

    /**
     * Mendefinisikan relasi bahwa dokumen ini dimiliki oleh sebuah insiden.
     */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function encryptionKey()
    {
        return $this->hasOne(EncryptionKey::class);
    }
}