<?php

namespace App\Contracts;

interface AgentToolInterface
{
    public function execute(array $arguments): string;
}
