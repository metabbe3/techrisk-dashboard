<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use OwenIt\Auditing\Contracts\Auditable;

class InvestigationDocument extends Model implements Auditable
{
    use HasFactory;
    use \OwenIt\Auditing\Auditable;

    /**
     * Izinkan semua field untuk diisi melalui form (Mass Assignment).
     */
    protected $fillable = [
        'incident_id',
        'file_path',
        'description',
        'pic_status',
        'original_filename',
        'markdown_path',
        'markdown_converted_at',
        'markdown_conversion_status',
        'ai_summary',
        'ai_summary_model',
        'ai_summary_at',
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

    public function getMarkdownContent(): ?string
    {
        if (! $this->markdown_path) {
            return null;
        }

        return \Illuminate\Support\Facades\Storage::disk('local')->get($this->markdown_path);
    }
}
