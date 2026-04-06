<?php

namespace App\Services\Agents;

/**
 * Represents a single step in an execution plan.
 */
class ExecutionStep
{
    public string $action;
    public string $description;
    public array $params;

    public function __construct(
        string $action,
        string $description = '',
        array $params = [],
    ) {
        $this->action = $action;
        $this->description = $description;
        $this->params = $params;
    }
}
