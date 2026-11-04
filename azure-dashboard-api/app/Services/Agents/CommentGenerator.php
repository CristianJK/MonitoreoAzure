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

    /**
     * Redacta en markdown el resumen técnico de un PR cerrado (sin encabezado ni fechas).
     *
     * @throws \RuntimeException si no se pudo generar
     */
    public function generateClosure(ClosureSummary $summary): string;

    public function name(): string;
}
