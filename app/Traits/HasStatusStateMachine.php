<?php

namespace App\Traits;

trait HasStatusStateMachine
{
    public function isComplete(): bool
    {
        return $this->status === 'completed';
    }

    public function isRunning(): bool
    {
        return $this->status === 'running';
    }

    public function hasFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function markRunning(): void
    {
        $this->update(['status' => 'running']);
    }

    public function markCompleted(): void
    {
        $this->update(['status' => 'completed']);
    }

    public function markFailed(string $error): void
    {
        $this->update([
            'status' => 'failed',
            'error_message' => $error,
        ]);
    }
}
