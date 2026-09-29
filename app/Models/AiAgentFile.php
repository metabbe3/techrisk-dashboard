<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;

class AiAgentFile extends Model implements Auditable
{
    use \Illuminate\Database\Eloquent\Concerns\HasUuids;
    use \OwenIt\Auditing\Auditable;

    protected $fillable = ['agent_id', 'filename', 'path', 'mime', 'size', 'extracted_text'];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(AiAgent::class, 'agent_id');
    }
}
