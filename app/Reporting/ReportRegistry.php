<?php

namespace App\Reporting;

class ReportRegistry
{
    /**
     * @var array<string, ReportDefinition>
     */
    protected array $definitions = [];

    /**
     * @param  iterable<ReportDefinition>  $definitions
     */
    public function __construct(iterable $definitions = [])
    {
        foreach ($definitions as $definition) {
            $this->register($definition);
        }
    }

    public function register(ReportDefinition $definition): void
    {
        $this->definitions[$definition->code()] = $definition;
    }

    public function get(string $code): ?ReportDefinition
    {
        return $this->definitions[$code] ?? null;
    }

    /**
     * @return list<string>
     */
    public function codes(): array
    {
        return array_keys($this->definitions);
    }
}
