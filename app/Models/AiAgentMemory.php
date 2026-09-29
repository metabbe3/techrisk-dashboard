<?php

namespace App\Models;

use App\Enums\AiAgentMemoryKind;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;

class AiAgentMemory extends Model implements Auditable
{
    use HasUuids;
    use \OwenIt\Auditing\Auditable;

    protected $fillable = [
        'agent_id',
        'run_id',
        'kind',
        'content',
    ];

    protected $casts = [
        'kind' => AiAgentMemoryKind::class,
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(AiAgent::class, 'agent_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AiAgentRun::class, 'run_id');
    }
}
