<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StatusUpdate extends Model
{
    use HasFactory;

    /**
     * Izinkan semua field untuk diisi melalui form (Mass Assignment).
     * Ini penting agar Filament bisa menyimpan data.
     */
    protected $guarded = [];

    protected $casts = [
        'update_date' => 'datetime',
    ];

    /**
     * Mendefinisikan relasi bahwa status ini dimiliki oleh sebuah insiden.
     * Ini menghubungkan model StatusUpdate ke model Incident.
     */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }
}
