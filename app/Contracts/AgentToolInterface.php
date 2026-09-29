<?php

declare(strict_types=1);
namespace App\Contracts;

interface AgentToolInterface
{
    public function execute(array $arguments): string;
}
