<?php

namespace App\Services\Agents;

interface CommentGenerator
{
    /**
     * Redacta en markdown el cuerpo del comentario del día (sin encabezado).
     *
     * @throws \RuntimeException si no se pudo generar
     */
    public function generate(DailyProgress $progress): string;

    public function name(): string;
}
