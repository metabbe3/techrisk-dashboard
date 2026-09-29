<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Contracts\Auditable;

class AiWorkflow extends Model implements Auditable
{
    use \Illuminate\Database\Eloquent\Concerns\HasUuids;
    use \OwenIt\Auditing\Auditable;

    protected $fillable = ['name', 'description'];

    public function steps(): HasMany
    {
        return $this->hasMany(AiWorkflowStep::class, 'workflow_id')->orderBy('position');
    }
}
