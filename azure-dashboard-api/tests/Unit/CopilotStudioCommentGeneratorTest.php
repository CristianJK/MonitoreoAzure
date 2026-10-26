<?php

namespace Tests\Unit;

use App\Services\Agents\CopilotStudioCommentGenerator;
use PHPUnit\Framework\TestCase;

class CopilotStudioCommentGeneratorTest extends TestCase
{
    public function test_strips_header_added_by_the_agent(): void
    {
        $text = "**Avance del 2026-09-16** (PR [#94819](https://x) – `rama` → `Develop_V8.2`)\n\n- Se ajustó el servicio.";

        $this->assertSame('- Se ajustó el servicio.', CopilotStudioCommentGenerator::stripHeader($text));
    }

    public function test_keeps_content_without_header(): void
    {
        $text = "- **Servicio**: se ajustó el resumen 2026-09-16.\n- Pruebas.";

        $this->assertSame($text, CopilotStudioCommentGenerator::stripHeader($text));
    }
}
