<?php

namespace App\Service\Program;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Decides what kind of answer each short or long text question of an imported Google Form really asks for
 * (an email, a phone number, a website, an amount, a date...). Amari reads the question titles; if it is not
 * available or answers badly, the caller keeps its own keyword-based guess. Only public form titles are sent.
 */
class GoogleFormTypeGuesser
{
    private const SHORT = ['Short answer', 'Email', 'Phone number', 'Website link', 'Number', 'Currency / money', 'Percentage', 'Date', 'Location', 'Address', 'Yes / No'];
    private const LONG = ['Long answer', 'Address'];
    private const CHUNK = 80;
    private const MAX_CHUNKS = 5;

    /**
     * @param  array<int, array{title: string, long: bool}>  $items  keyed by any id
     * @return array<int, string> id => type label, only for the items Amari answered validly
     */
    public function guess(array $items): array
    {
        if (! config('services.amari.key') || ! $items) {
            return [];
        }

        $result = [];
        foreach (array_slice(array_chunk($items, self::CHUNK, true), 0, self::MAX_CHUNKS) as $chunk) {
            try {
                $result += $this->ask($chunk);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $result;
    }

    private function ask(array $chunk): array
    {
        $lines = [];
        foreach ($chunk as $id => $item) {
            $lines[] = $id . ' | ' . ($item['long'] ? 'LONG' : 'SHORT') . ' | ' . mb_substr(preg_replace('/\s+/', ' ', $item['title']), 0, 160);
        }

        $system = "You label the questions of an application form. For each line (id | SHORT or LONG | question title) choose the answer type the applicant is asked for.\n"
            . 'SHORT types: ' . implode(', ', self::SHORT) . ".\nLONG types: " . implode(', ', self::LONG) . ".\n"
            . "Rules: use Email only when an email address is asked; Phone number for phone or WhatsApp numbers; Website link for a website, URL or video link; Number for a plain count or quantity; Currency / money for an amount of money; Percentage for a percent; Date for a calendar date; Location for a country, city or region; Yes / No only when the title is clearly a yes-or-no question. Names, titles, descriptions and anything uncertain stay Short answer (or Long answer for LONG).\n"
            . 'Answer with JSON only: {"types":{"<id>":"<type>", ...}} covering every id.';

        $response = Http::withToken(config('services.amari.key'))->acceptJson()->timeout(25)
            ->post(config('services.amari.url'), [
                'model' => config('services.amari.model'),
                'temperature' => 0,
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => implode("\n", $lines)],
                ],
            ]);
        if (! $response->successful()) {
            return [];
        }

        $content = (string) data_get($response->json(), 'choices.0.message.content', '');
        $types = json_decode($content, true)['types'] ?? [];
        if (! is_array($types)) {
            return [];
        }

        $out = [];
        foreach ($chunk as $id => $item) {
            $label = $types[$id] ?? $types[(string) $id] ?? null;
            $allowed = $item['long'] ? self::LONG : self::SHORT;
            if (is_string($label) && in_array($label, $allowed, true)) {
                $out[$id] = $label;
            }
        }

        return $out;
    }
}
