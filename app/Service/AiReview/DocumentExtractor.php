<?php

namespace App\Service\AiReview;

use App\Models\Programs\Ai\ApplicationDocumentExtraction;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use ZipArchive;

/**
 * Reads applicant files LOCALLY. Nothing is uploaded to an AI provider here; the extracted text is stored privately
 * and is what the model (after redaction) later sees. Unreadable files are recorded, never silently skipped.
 */
class DocumentExtractor
{
    public array $notes = [];

    /**
     * @return ApplicationDocumentExtraction a row whose status says what happened
     */
    public function extract(string $sourceType, int $sourceId, string $path, ?string $declaredType = null, ?string $originalName = null): ApplicationDocumentExtraction
    {
        $label = $originalName ?: basename($path);

        if ($this->isExcluded($declaredType) || $this->isExcluded($label)) {
            return $this->record($sourceType, $sourceId, hash('sha256', $path), ['status' => 'excluded', 'error' => 'Identity or verification documents are never read by AI.']);
        }

        $disk = Storage::disk('s3');
        try {
            if (! $disk->exists($path)) {
                return $this->record($sourceType, $sourceId, hash('sha256', $path), ['status' => 'unreadable', 'error' => 'File not found in storage.']);
            }
            $size = (int) $disk->size($path);
            if ($size > config('ai_review.max_file_mb') * 1024 * 1024) {
                return $this->record($sourceType, $sourceId, hash('sha256', $path.$size), ['status' => 'too_large', 'error' => 'File is larger than the AI review limit.']);
            }
            $bytes = $disk->get($path);
        } catch (\Throwable $e) {
            return $this->record($sourceType, $sourceId, hash('sha256', $path), ['status' => 'unreadable', 'error' => 'File could not be read.']);
        }

        $hash = hash('sha256', $bytes);
        $existing = ApplicationDocumentExtraction::where(['source_type' => $sourceType, 'source_id' => $sourceId, 'file_hash' => $hash])->first();
        if ($existing && $existing->status !== 'pending') {
            return $existing; // never re-extract an unchanged file
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $tmp = tempnam(sys_get_temp_dir(), 'aiex');
        file_put_contents($tmp, $bytes);

        try {
            $result = match (true) {
                $ext === 'pdf' => $this->pdf($tmp),
                $ext === 'docx' => $this->docx($tmp),
                $ext === 'xlsx' => $this->xlsx($tmp),
                $ext === 'pptx' => $this->pptx($tmp),
                in_array($ext, ['txt', 'md', 'csv'], true) => $this->plain($bytes),
                in_array($ext, ['png', 'jpg', 'jpeg', 'tif', 'tiff', 'bmp', 'webp'], true) => $this->image($tmp),
                default => ['status' => 'unsupported', 'error' => "File type .{$ext} is not supported."],
            };
        } catch (\Throwable $e) {
            $result = ['status' => 'unreadable', 'error' => 'File could not be read (it may be damaged or password protected).'];
        } finally {
            @unlink($tmp);
        }

        $result['mime'] = $ext;

        return $this->record($sourceType, $sourceId, $hash, $result);
    }

    // ── formats ───────────────────────────────────────────────────────────────

    private function pdf(string $file): array
    {
        $maxPages = (int) config('ai_review.max_pages_per_document');
        $pages = $this->pdfNativePages($file);
        $method = 'native_pdf';

        $chars = array_sum(array_map('mb_strlen', $pages));
        $looksScanned = ! $pages || $chars < 40 * max(1, count($pages));
        if ($looksScanned) {
            $ocr = $this->ocrPdf($file, $maxPages);
            if ($ocr !== null && array_sum(array_map('mb_strlen', $ocr)) > $chars) {
                $pages = $ocr;
                $method = 'ocr';
            }
        }

        return $this->pagesResult($pages, $method, $maxPages, true);
    }

    private function pdfNativePages(string $file): array
    {
        $bin = config('ai_review.ocr.pdftotext');
        if ($this->has($bin)) {
            $p = new Process([$bin, '-layout', '-enc', 'UTF-8', $file, '-'], null, null, null, 60);
            $p->run();
            if ($p->isSuccessful() && trim($p->getOutput()) !== '') {
                return array_values(array_map('trim', array_filter(explode("\f", $p->getOutput()), fn ($t) => $t !== '' && $t !== null)));
            }
        }

        try {
            $pdf = (new \Smalot\PdfParser\Parser())->parseFile($file);
            $pages = [];
            foreach ($pdf->getPages() as $page) {
                $pages[] = trim($page->getText());
            }

            return $pages;
        } catch (\Throwable $e) {
            throw new \RuntimeException('pdf unreadable');
        }
    }

    /** Scanned PDF: render each page with pdftoppm, then tesseract. Returns null when the tools are missing. */
    private function ocrPdf(string $file, int $maxPages): ?array
    {
        $o = config('ai_review.ocr');
        if (! $o['enabled'] || ! $this->has($o['pdftoppm']) || ! $this->has($o['tesseract'])) {
            $this->notes[] = 'Scanned document found but OCR tools (pdftoppm/tesseract) are not installed on the server.';

            return null;
        }
        $dir = sys_get_temp_dir().'/aiocr_'.bin2hex(random_bytes(5));
        mkdir($dir);
        try {
            $r = new Process([$o['pdftoppm'], '-r', '150', '-l', (string) $maxPages, '-png', $file, $dir.'/p'], null, null, null, $o['timeout']);
            $r->run();
            $pages = [];
            $images = glob($dir.'/p*.png') ?: [];
            sort($images);
            foreach ($images as $image) {
                $pages[] = $this->tesseract($image) ?? '';
            }

            return $pages;
        } finally {
            foreach (glob($dir.'/*') ?: [] as $f) @unlink($f);
            @rmdir($dir);
        }
    }

    private function image(string $file): array
    {
        $o = config('ai_review.ocr');
        if (! $o['enabled'] || ! $this->has($o['tesseract'])) {
            $this->notes[] = 'Image found but OCR (tesseract) is not installed on the server.';

            return ['status' => 'unreadable', 'error' => 'OCR is not available on this server.'];
        }
        $text = $this->tesseract($file) ?? '';

        return $this->pagesResult([$text], 'ocr', 1, false);
    }

    private function tesseract(string $image): ?string
    {
        $o = config('ai_review.ocr');
        $p = new Process([$o['tesseract'], $image, 'stdout', '-l', $o['languages']], null, null, null, $o['timeout']);
        $p->run();

        return $p->isSuccessful() ? trim($p->getOutput()) : null;
    }

    private function docx(string $file): array
    {
        $zip = $this->openZip($file);
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        $dom = $this->safeXml($xml ?: '');
        $text = '';
        foreach ($dom->getElementsByTagNameNS('*', 'p') as $p) {
            $line = '';
            foreach ($p->getElementsByTagNameNS('*', 't') as $t) $line .= $t->textContent;
            $text .= $line."\n";
        }

        return $this->pagesResult([trim($text)], 'local_text', 1, false);
    }

    private function xlsx(string $file): array
    {
        $zip = $this->openZip($file);
        $shared = [];
        if (($ss = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
            foreach ($this->safeXml($ss)->getElementsByTagNameNS('*', 'si') as $si) {
                $s = '';
                foreach ($si->getElementsByTagNameNS('*', 't') as $t) $s .= $t->textContent;
                $shared[] = $s;
            }
        }
        $names = [];
        if (($wb = $zip->getFromName('xl/workbook.xml')) !== false) {
            foreach ($this->safeXml($wb)->getElementsByTagNameNS('*', 'sheet') as $sheet) $names[] = $sheet->getAttribute('name');
        }
        $maxRows = (int) config('ai_review.max_sheet_rows');
        $pages = [];
        for ($i = 1; $i <= 30; $i++) {
            $xml = $zip->getFromName("xl/worksheets/sheet{$i}.xml");
            if ($xml === false) break;
            $rows = [];
            foreach ($this->safeXml($xml)->getElementsByTagNameNS('*', 'row') as $row) {
                if (count($rows) >= $maxRows) break;
                $cells = [];
                foreach ($row->getElementsByTagNameNS('*', 'c') as $c) {
                    $v = $c->getElementsByTagNameNS('*', 'v')->item(0)?->textContent;
                    if ($v === null) { $is = $c->getElementsByTagNameNS('*', 't')->item(0)?->textContent; $cells[] = (string) $is; continue; }
                    $cells[] = $c->getAttribute('t') === 's' ? ($shared[(int) $v] ?? '') : $v;
                }
                $line = trim(implode(' | ', $cells), ' |');
                if ($line !== '') $rows[] = $line;
            }
            $pages[] = 'Sheet '.($names[$i - 1] ?? $i).":\n".implode("\n", $rows);
        }
        $zip->close();

        return $this->pagesResult($pages, 'local_text', (int) config('ai_review.max_pages_per_document'), false);
    }

    private function pptx(string $file): array
    {
        $zip = $this->openZip($file);
        $pages = [];
        for ($i = 1; $i <= 200; $i++) {
            $xml = $zip->getFromName("ppt/slides/slide{$i}.xml");
            if ($xml === false) break;
            $t = '';
            foreach ($this->safeXml($xml)->getElementsByTagNameNS('*', 'p') as $p) {
                $line = '';
                foreach ($p->getElementsByTagNameNS('*', 't') as $n) $line .= $n->textContent;
                $t .= $line."\n";
            }
            $pages[] = trim($t);
        }
        $zip->close();

        return $this->pagesResult($pages, 'local_text', (int) config('ai_review.max_pages_per_document'), true);
    }

    private function plain(string $bytes): array
    {
        return $this->pagesResult([mb_convert_encoding(substr($bytes, 0, 2_000_000), 'UTF-8', 'UTF-8')], 'local_text', 1, false);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function pagesResult(array $pages, string $method, int $maxPages, bool $paged): array
    {
        $pages = array_values($pages);
        $total = count($pages);
        $truncated = $total > $maxPages;
        $pages = array_slice($pages, 0, $maxPages);

        $map = [];
        $info = [];
        foreach ($pages as $i => $text) {
            $map[$i + 1] = $text;
            $info[] = ['page' => $i + 1, 'chars' => mb_strlen($text)];
        }
        $all = trim(implode("\n\n", array_filter($map)));
        if ($all === '') {
            return ['status' => 'unreadable', 'error' => 'No readable text was found.', 'method' => $method];
        }
        if ($truncated) {
            $this->notes[] = "Only the first {$maxPages} of {$total} pages were read.";
        }

        return [
            'status' => $method === 'ocr' ? 'ocr_ok' : 'ok', 'method' => $method,
            // pages are separated by a form feed so page-level quotes can be checked later
            'text_content' => implode("\f", $map),
            'page_count' => $total, 'pages' => $info,
        ];
    }

    private function record(string $type, int $id, string $hash, array $data): ApplicationDocumentExtraction
    {
        $row = ApplicationDocumentExtraction::updateOrCreate(
            ['source_type' => $type, 'source_id' => $id, 'file_hash' => $hash],
            $data + ['expires_at' => now()->addDays((int) config('ai_review.extraction_retention_days'))],
        );
        return $row;
    }

    private function openZip(string $file): ZipArchive
    {
        $zip = new ZipArchive();
        if ($zip->open($file) !== true) throw new \RuntimeException('zip unreadable');
        for ($i = 0; $i < min($zip->numFiles, 2000); $i++) {
            $stat = $zip->statIndex($i);
            if ($stat && $stat['size'] > 50 * 1024 * 1024) {
                $zip->close();
                throw new \RuntimeException('zip entry too large');
            }
        }

        return $zip;
    }

    /** XXE-safe: DOCTYPE/ENTITY are refused, no external loading. */
    private function safeXml(string $xml): \DOMDocument
    {
        if ($xml === '' || stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            throw new \RuntimeException('xml refused');
        }
        $dom = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $ok = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (! $ok) throw new \RuntimeException('xml invalid');

        return $dom;
    }

    private function has(string $bin): bool
    {
        static $cache = [];
        if (! isset($cache[$bin])) {
            $p = new Process(['which', $bin]);
            $p->run();
            $cache[$bin] = $p->isSuccessful();
        }

        return $cache[$bin];
    }

    public function isExcluded(?string $value): bool
    {
        if (! $value) return false;
        $slug = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($value)), '_');
        foreach (config('ai_review.excluded_document_types', []) as $type) {
            $type = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($type)), '_');
            if ($type !== '' && ($slug === $type || str_contains($slug, $type))) return true;
        }

        return false;
    }
}
