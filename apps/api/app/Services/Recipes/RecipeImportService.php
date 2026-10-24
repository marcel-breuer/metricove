<?php

namespace App\Services\Recipes;

use App\Enums\ModuleCapability;
use App\Models\User;
use App\Services\Modules\ModuleAccessService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

class RecipeImportService
{
    private const MAX_HTML_BYTES = 2_097_152;

    private const MAX_TEXT_CHARS = 100_000;

    private const MAX_PDF_PAGES = 20;

    public function __construct(private readonly ModuleAccessService $access) {}

    /** @return array{recipe: array<string, mixed>, warnings: list<string>} */
    public function preview(User $actor, string $visibility, ?string $url, ?UploadedFile $file): array
    {
        $capability = $visibility === 'household' ? ModuleCapability::Manage : ModuleCapability::Create;
        $this->access->authorize($actor, 'recipes', $capability);

        if (($url === null) === ($file === null)) {
            throw ValidationException::withMessages(['source' => 'Provide either a recipe URL or one PDF file.']);
        }

        if ($url !== null) {
            $candidate = $this->parseWebPage($url);
        } elseif ($file instanceof UploadedFile) {
            $candidate = $this->parsePdf($file);
        } else {
            throw ValidationException::withMessages(['source' => 'Provide either a recipe URL or one PDF file.']);
        }
        $warnings = [];
        if (trim((string) $candidate['title']) === '') {
            $warnings[] = 'No recipe title could be identified. Add one before saving.';
        }
        foreach (['ingredients', 'instructions'] as $requiredForSaving) {
            if ($candidate[$requiredForSaving] === []) {
                $warnings[] = $requiredForSaving === 'ingredients'
                    ? 'No ingredients could be identified. Add them before saving.'
                    : 'No instructions could be identified. Add them before saving.';
            }
        }

        return ['recipe' => $candidate, 'warnings' => $warnings];
    }

    /** @return array<string, mixed> */
    private function parseWebPage(string $url): array
    {
        [$host, $addresses] = $this->publicHttpsTarget($url);
        $resolve = array_map(
            static fn (string $address): string => $host.':443:'.(str_contains($address, ':') ? '['.$address.']' : $address),
            $addresses,
        );

        try {
            $response = Http::withOptions([
                'allow_redirects' => false,
                'connect_timeout' => 4,
                'timeout' => 12,
                'stream' => true,
                'proxy' => '',
                'curl' => [CURLOPT_RESOLVE => $resolve],
            ])->accept('text/html,application/xhtml+xml')
                ->withHeaders(['User-Agent' => 'Metricove recipe importer/1.0'])
                ->get($url);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['source_url' => 'The recipe page could not be reached safely. Check the URL and try again.']);
        }

        if (! $response->successful()) {
            throw ValidationException::withMessages(['source_url' => 'The recipe page could not be read. Redirects and non-success responses are not supported.']);
        }

        $contentType = strtolower((string) $response->header('Content-Type'));
        if (! str_contains($contentType, 'text/html') && ! str_contains($contentType, 'application/xhtml+xml')) {
            throw ValidationException::withMessages(['source_url' => 'The link must point to a recipe web page. Upload a PDF file separately.']);
        }

        $length = $response->header('Content-Length');
        if (is_string($length) && ctype_digit($length) && (int) $length > self::MAX_HTML_BYTES) {
            throw ValidationException::withMessages(['source_url' => 'The recipe page is too large to import.']);
        }

        $stream = $response->toPsrResponse()->getBody();
        $html = '';
        while (! $stream->eof()) {
            $chunk = $stream->read(8192);
            if ($chunk === '') {
                break;
            }
            $html .= $chunk;
            if (strlen($html) > self::MAX_HTML_BYTES) {
                throw ValidationException::withMessages(['source_url' => 'The recipe page is too large to import.']);
            }
        }

        return $this->parseHtml($html);
    }

    /** @return array{0: string, 1: list<string>} */
    private function publicHttpsTarget(string $url): array
    {
        $parts = parse_url($url);
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || isset($parts['user']) || isset($parts['pass'])
            || (isset($parts['port']) && $parts['port'] !== 443)) {
            throw ValidationException::withMessages(['source_url' => 'Use a public HTTPS recipe link without sign-in credentials.']);
        }

        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false
            || ! preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host)
            || str_ends_with($host, '.localhost') || $host === 'localhost') {
            throw ValidationException::withMessages(['source_url' => 'Use a public HTTPS recipe link.']);
        }

        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        $addresses = [];
        foreach (is_array($records) ? $records : [] as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($address)) {
                $addresses[] = $address;
            }
        }
        $addresses = array_values(array_unique($addresses));
        if ($addresses === []) {
            throw ValidationException::withMessages(['source_url' => 'The recipe page host could not be resolved.']);
        }

        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw ValidationException::withMessages(['source_url' => 'The recipe link must resolve only to public addresses.']);
            }
        }

        return [$host, $addresses];
    }

    /** @return array<string, mixed> */
    private function parseHtml(string $html): array
    {
        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $xpath = new \DOMXPath($document);
        $scripts = $xpath->query('//script[@type="application/ld+json"]');
        if ($scripts !== false) {
            foreach ($scripts as $script) {
                $json = json_decode($script->textContent, true);
                $recipe = $this->findRecipe($json);
                if ($recipe !== null) {
                    return $this->normalizeStructuredRecipe($recipe);
                }
            }
        }

        $title = trim((string) ($xpath->query('//meta[@property="og:title"]/@content')->item(0)?->nodeValue
            ?? $xpath->query('//h1')->item(0)?->textContent
            ?? $document->getElementsByTagName('title')->item(0)?->textContent
            ?? ''));
        throw ValidationException::withMessages([
            'source_url' => $title === ''
                ? 'This page does not contain a supported recipe. Try a page with structured recipe data or upload a PDF.'
                : 'This page does not expose a structured recipe. Try another recipe page or upload a PDF.',
        ]);
    }

    /** @return array<string, mixed>|null */
    private function findRecipe(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }
        $types = $value['@type'] ?? [];
        if (is_string($types)) {
            $types = [$types];
        }
        if (is_array($types) && in_array('Recipe', $types, true)) {
            return $value;
        }
        foreach ($value as $child) {
            $recipe = $this->findRecipe($child);
            if ($recipe !== null) {
                return $recipe;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $recipe
     * @return array<string, mixed>
     */
    private function normalizeStructuredRecipe(array $recipe): array
    {
        $instructions = $this->flattenInstructions($recipe['recipeInstructions'] ?? []);
        $ingredients = array_values(array_filter(array_map(
            static fn (mixed $item): string => is_string($item) ? trim(strip_tags($item)) : '',
            is_array($recipe['recipeIngredient'] ?? null) ? $recipe['recipeIngredient'] : [],
        )));
        $category = $recipe['recipeCategory'] ?? null;

        return [
            'title' => $this->scalarText($recipe['name'] ?? ''),
            'category' => is_array($category) ? $this->scalarText($category[0] ?? '') : $this->scalarText($category),
            'servings' => $this->firstNumber($recipe['recipeYield'] ?? null) ?? 2,
            'preparation_minutes' => $this->durationMinutes($recipe['prepTime'] ?? null),
            'ingredients' => $ingredients,
            'instructions' => $instructions,
        ];
    }

    /** @return list<string> */
    private function flattenInstructions(mixed $value): array
    {
        if (is_string($value)) {
            $text = trim(strip_tags($value));

            return $text === '' ? [] : [$text];
        }
        if (! is_array($value)) {
            return [];
        }

        $steps = [];
        foreach ($value as $item) {
            if (is_string($item)) {
                $steps = [...$steps, ...$this->flattenInstructions($item)];
            } elseif (is_array($item)) {
                if (isset($item['text'])) {
                    $steps = [...$steps, ...$this->flattenInstructions((string) $item['text'])];
                } else {
                    $steps = [...$steps, ...$this->flattenInstructions($item['itemListElement'] ?? [])];
                }
            }
        }

        return $steps;
    }

    private function scalarText(mixed $value): string
    {
        return is_string($value) || is_numeric($value) ? trim((string) $value) : '';
    }

    private function firstNumber(mixed $value): ?int
    {
        $text = is_array($value) ? $this->scalarText($value[0] ?? '') : $this->scalarText($value);
        if (preg_match('/\d{1,4}/', $text, $match) !== 1) {
            return null;
        }

        return max(1, min(1000, (int) $match[0]));
    }

    private function durationMinutes(mixed $value): ?int
    {
        if (! is_string($value) || preg_match('/^PT(?:(\d+)H)?(?:(\d+)M)?$/i', $value, $matches) !== 1) {
            return null;
        }

        return max(1, min(1440, ((int) ($matches[1] ?? 0) * 60) + (int) ($matches[2] ?? 0)));
    }

    /** @return array<string, mixed> */
    private function parsePdf(UploadedFile $file): array
    {
        $path = $file->getRealPath();
        if (! is_string($path) || file_get_contents($path, false, null, 0, 5) !== '%PDF-') {
            throw ValidationException::withMessages(['file' => 'Upload a valid PDF recipe.']);
        }

        $info = new Process(['pdfinfo', '-f', '1', '-l', (string) self::MAX_PDF_PAGES, $path]);
        $info->setTimeout(5);
        $info->run();
        if (! $info->isSuccessful() || preg_match('/^Pages:\s+(\d+)/mi', $info->getOutput(), $pageMatch) !== 1) {
            throw ValidationException::withMessages(['file' => 'The PDF could not be read. Check that it is not encrypted or damaged.']);
        }

        $pages = (int) $pageMatch[1];
        if ($pages < 1) {
            throw ValidationException::withMessages(['file' => 'The PDF does not contain readable pages.']);
        }
        $process = new Process(['pdftotext', '-f', '1', '-l', (string) min($pages, self::MAX_PDF_PAGES), '-layout', '-nopgbrk', $path, '-']);
        $process->setTimeout(10);
        $text = '';
        $tooMuchText = false;
        $process->run(function (string $type, string $buffer) use (&$text, &$tooMuchText, $process): void {
            if ($type !== Process::OUT) {
                return;
            }
            if (strlen($text) + strlen($buffer) > self::MAX_TEXT_CHARS) {
                $tooMuchText = true;
                $process->stop(0.1);

                return;
            }
            $text .= $buffer;
        });
        if ($tooMuchText) {
            throw ValidationException::withMessages(['file' => 'The extracted recipe text is too long to import.']);
        }
        if (! $process->isSuccessful()) {
            throw ValidationException::withMessages(['file' => 'Text could not be extracted from this PDF. Scanned PDFs are not supported yet.']);
        }
        $text = trim($text);
        if ($text === '') {
            throw ValidationException::withMessages(['file' => 'No selectable text was found. Scanned PDFs are not supported yet.']);
        }

        return $this->parsePlainText($text);
    }

    /** @return array<string, mixed> */
    private function parsePlainText(string $text): array
    {
        $lines = array_values(array_filter(array_map(
            static fn (string $line): string => trim(preg_replace('/\s+/u', ' ', $line) ?? ''),
            preg_split('/\R/u', str_replace("\f", "\n", $text)) ?: [],
        ), static fn (string $line): bool => $line !== ''));
        if ($lines === []) {
            throw ValidationException::withMessages(['file' => 'No readable recipe text was found in this PDF.']);
        }

        $ingredientStart = $this->findHeading($lines, ['ingredients', 'ingredient list', 'zutaten', 'zutatenliste', 'zutaten:']);
        $instructionStart = $this->findHeading($lines, ['instructions', 'directions', 'method', 'preparation', 'zubereitung', 'anleitung', 'schritte']);
        $ingredients = [];
        $instructions = [];
        if ($ingredientStart !== null) {
            $end = $instructionStart !== null && $instructionStart > $ingredientStart ? $instructionStart : count($lines);
            for ($i = $ingredientStart + 1; $i < $end; $i++) {
                if ($this->isHeading($lines[$i])) {
                    break;
                }
                $line = preg_replace('/^\s*(?:[-*•]|\d+[.)])\s*/u', '', $lines[$i]) ?? $lines[$i];
                if ($line !== '') {
                    $ingredients[] = $line;
                }
            }
        }
        if ($instructionStart !== null) {
            for ($i = $instructionStart + 1; $i < count($lines); $i++) {
                if ($this->isHeading($lines[$i])) {
                    break;
                }
                $line = preg_replace('/^\s*(?:[-*•]|\d+[.)])\s*/u', '', $lines[$i]) ?? $lines[$i];
                if ($line !== '') {
                    $instructions[] = $line;
                }
            }
        }

        $title = trim($lines[0], " \t\n\r\0\x0B#-•");
        if (mb_strlen($title) > 180) {
            $title = mb_substr($title, 0, 180);
        }

        return [
            'title' => $title,
            'category' => '',
            'servings' => $this->textServings(implode(' ', array_slice($lines, 0, min(8, count($lines))))) ?? 2,
            'preparation_minutes' => $this->textDuration(implode(' ', array_slice($lines, 0, min(8, count($lines))))),
            'ingredients' => array_slice($ingredients, 0, 100),
            'instructions' => $instructions,
        ];
    }

    /** @param list<string> $lines */
    private function findHeading(array $lines, array $headings): ?int
    {
        foreach ($lines as $index => $line) {
            $normalized = mb_strtolower(trim($line, " :\t"));
            if (in_array($normalized, $headings, true)) {
                return $index;
            }
        }

        return null;
    }

    private function isHeading(string $line): bool
    {
        return $this->findHeading([$line], ['ingredients', 'ingredient list', 'zutaten', 'zutatenliste', 'instructions', 'directions', 'method', 'preparation', 'zubereitung', 'anleitung', 'schritte']) !== null;
    }

    private function textDuration(string $text): ?int
    {
        if (preg_match('/(?:prep(?:aration)?\s*time|vorbereitungszeit|zubereitungszeit)\D{0,12}(\d{1,4})\s*(?:hours?|hrs?|stunden?|h)?\s*(\d{1,2})?\s*(?:minutes?|mins?|minuten?|min)?/iu', $text, $match) !== 1) {
            return null;
        }
        $hours = preg_match('/h(?:ours?|rs?)?/iu', $match[0]) === 1 ? (int) $match[1] : 0;
        $minutes = $hours > 0 ? (int) ($match[2] ?? 0) : (int) $match[1];

        return max(1, min(1440, $hours * 60 + $minutes));
    }

    private function textServings(string $text): ?int
    {
        if (preg_match('/(?:serves?|servings?|yield|portions?|portionen|ergibt)\D{0,12}(\d{1,4})/iu', $text, $match) !== 1) {
            return null;
        }

        return max(1, min(1000, (int) $match[1]));
    }
}
