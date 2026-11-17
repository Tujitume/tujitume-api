<?php

namespace App\Modules\ProgramImport\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Resources\ApiResponseResource;
use App\Modules\ProgramImport\Policies\ImportAccess;
use App\Modules\ProgramImport\Registry\ImportTypeRegistry;
use App\Modules\ProgramImport\Registry\StartPointCatalog;
use App\Modules\ProgramImport\Support\TemplateGenerator;
use Illuminate\Http\Request;

class CatalogController extends Controller
{
    public function startPoints(Request $r)
    {
        abort_unless(ImportAccess::canRead($r->user()), 403);
        $out = [];
        foreach (StartPointCatalog::all() as $key => $p) {
            $l = StartPointCatalog::landing($key);
            $out[] = ['key' => $key] + $p + ['landing' => $l->describe(), 'skipped' => $l->skipped()];
        }

        return ApiResponseResource::success('Start points', $out);
    }

    public function types(Request $r)
    {
        abort_unless(ImportAccess::canRead($r->user()), 403);

        return ApiResponseResource::success('Import types', ['types' => ImportTypeRegistry::describe(), 'form' => ['type' => 'form', 'label' => 'Form (Google Form link or pasted questions)']]);
    }

    public function template(Request $r, string $name)
    {
        abort_unless(ImportAccess::canRead($r->user()), 403);
        $format = $r->query('format', 'xlsx');
        if (! in_array($format, ['csv', 'xlsx'], true) || ! TemplateGenerator::exists($name)) {
            return ApiResponseResource::error('Unknown template.', ['code' => 'unknown_template'], 404);
        }
        try {
            $path = (new TemplateGenerator)->build($name, $format);
        } catch (\InvalidArgumentException $e) {
            return ApiResponseResource::error('A start point needs several sheets, so download the XLSX template.', ['code' => $e->getMessage()], 422);
        }

        return response()->download($path, "tujitume-import-{$name}.{$format}", ['Content-Type' => $format === 'csv' ? 'text/csv' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend(true);
    }
}
