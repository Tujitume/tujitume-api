<?php

namespace App\Modules\ProgramImport\Support;

use App\Modules\ProgramImport\Pipeline\BatchStager;
use Illuminate\Http\UploadedFile;

/** Optional ZIP of applicant files, matched by file name. Guarded against zip bombs, path tricks and odd file types. */
class AttachmentBag
{
    public const MAX_ENTRIES = 500;

    public const MAX_UNCOMPRESSED = 200 * 1024 * 1024;

    public const MAX_FILE = 20 * 1024 * 1024;

    private const ALLOWED = ['pdf', 'doc', 'docx', 'ppt', 'pptx'];

    private \ZipArchive $zip;

    private string $tmp;

    /** @var array<string,int> lowercase base name => entry index */
    private array $index = [];

    private array $extracted = [];

    public function __construct(string $diskPath)
    {
        $this->tmp = tempnam(sys_get_temp_dir(), 'pia');
        file_put_contents($this->tmp, BatchStager::disk()->get($diskPath));
        $this->zip = new \ZipArchive;
        if ($this->zip->open($this->tmp) !== true) {
            throw new \RuntimeException('The attachments file is not a valid ZIP.');
        }
        if ($this->zip->numFiles > self::MAX_ENTRIES) {
            throw new \RuntimeException('The attachments ZIP has too many files.');
        }
        $total = 0;
        for ($i = 0; $i < $this->zip->numFiles; $i++) {
            $st = $this->zip->statIndex($i);
            $total += $st['size'];
            $name = $st['name'];
            if (str_ends_with($name, '/') || str_contains($name, '..') || str_starts_with($name, '__MACOSX')) {
                continue;
            }
            $this->index[strtolower(basename($name))] = $i;
        }
        if ($total > self::MAX_UNCOMPRESSED) {
            throw new \RuntimeException('The attachments ZIP is too large when unpacked.');
        }
    }

    public function has(string $name): bool
    {
        return isset($this->index[strtolower($name)]);
    }

    /** @return ?string a problem, or null when the file is usable */
    public function problem(string $name): ?string
    {
        if (! $this->has($name)) {
            return "File \"$name\" is not in the attachments ZIP";
        }
        if (! in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::ALLOWED, true)) {
            return 'Only pdf, doc, docx, ppt and pptx files can be attached';
        }
        if ($this->zip->statIndex($this->index[strtolower($name)])['size'] > self::MAX_FILE) {
            return "File \"$name\" is larger than 20 MB";
        }

        return null;
    }

    public function uploaded(string $name): UploadedFile
    {
        $key = strtolower($name);
        if (! isset($this->extracted[$key])) {
            $path = tempnam(sys_get_temp_dir(), 'pif');
            file_put_contents($path, $this->zip->getFromIndex($this->index[$key]));
            $this->extracted[$key] = $path;
        }

        return new UploadedFile($this->extracted[$key], basename($name), null, null, true);
    }

    public function __destruct()
    {
        $this->zip->close();
        @unlink($this->tmp);
        foreach ($this->extracted as $p) {
            @unlink($p);
        }
    }
}
