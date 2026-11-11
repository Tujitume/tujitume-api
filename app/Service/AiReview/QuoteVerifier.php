<?php

namespace App\Service\AiReview;

/** Checks that every quote the AI cites really exists in the cited source's locally stored text. */
class QuoteVerifier
{
    public function __construct(private Redactor $redactor)
    {
    }

    public function normalize(string $text): string
    {
        $text = mb_strtolower($text);
        $text = strtr($text, ['’' => "'", '‘' => "'", '“' => '"', '”' => '"', '–' => '-', '—' => '-', "\u{00A0}" => ' ']);

        return preg_replace('/[^\p{L}\p{N}]+/u', '', $text) ?? '';
    }

    /** Looser form that forgives common OCR confusions. */
    public function ocrNormalize(string $text): string
    {
        $text = $this->normalize($text);

        return strtr(str_replace('rn', 'm', $text), ['0' => 'o', '1' => 'l', 'i' => 'l', '5' => 's', '8' => 'b']);
    }

    /**
     * @param array{type?:string,id?:mixed,page?:?int} $ref
     * @param array<string, array{text:string, pages?:array<int,string>|null, type:string, id:mixed}> $sources keyed by source key
     */
    public function verify(?string $quote, array $ref, array $sources): bool
    {
        $quote = trim((string) $quote);
        if ($quote === '') return false;

        $needle = $this->normalize($quote);
        $ocrNeedle = $this->ocrNormalize($quote);
        if (mb_strlen($needle) < 6) return false;

        $source = $this->find($ref, $sources);
        if (! $source) return false;

        $texts = [];
        $page = $ref['page'] ?? null;
        if ($page && ! empty($source['pages'][$page])) {
            $texts[] = $source['pages'][$page];
        }
        $texts[] = $source['text'];

        foreach ($texts as $text) {
            // The model saw redacted text, so compare against the same redaction
            $seen = $this->redactor->redact($text);
            if (str_contains($this->normalize($seen), $needle)) return true;
            if (str_contains($this->ocrNormalize($seen), $ocrNeedle)) return true;
        }

        return false;
    }

    public function find(array $ref, array $sources): ?array
    {
        $type = $ref['type'] ?? null;
        $id = $ref['id'] ?? null;
        foreach ($sources as $source) {
            if (($source['type'] ?? null) === $type && (string) ($source['id'] ?? '') === (string) $id) {
                return $source;
            }
        }

        return null;
    }
}
