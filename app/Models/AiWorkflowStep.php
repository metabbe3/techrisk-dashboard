<?php

declare(strict_types=1);
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;

class AiWorkflowStep extends Model implements Auditable
{
    use \Illuminate\Database\Eloquent\Concerns\HasUuids;
    use \OwenIt\Auditing\Auditable;

    protected $fillable = ['workflow_id', 'agent_id', 'position'];

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(AiWorkflow::class, 'workflow_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(AiAgent::class, 'agent_id');
    }
}
