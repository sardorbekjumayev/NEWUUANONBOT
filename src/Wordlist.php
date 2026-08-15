<?php

declare(strict_types=1);

namespace PUAnonymous;

final class Wordlist
{
    private string $filePath;
    /** @var array<string> */
    private array $words = [];

    public function __construct(?string $filePath = null)
    {
        $this->filePath = $filePath ?? dirname(__DIR__) . '/data/wordlist.json';
        $this->load();
    }

    private function load(): void
    {
        if (is_file($this->filePath)) {
            $raw = file_get_contents($this->filePath);
            $data = json_decode((string) $raw, true);
            if (is_array($data)) {
                $this->words = array_values(array_unique(array_filter(array_map(
                    static fn (mixed $item): string => mb_strtolower(trim((string) $item)),
                    $data
                ))));
                return;
            }
        }

        // Default initial wordlist
        $this->words = ['crypto', 'forex', 'betting', 'casino', 'promocode', 'airdrop'];
        $this->save();
    }

    private function save(): void
    {
        $dir = dirname($this->filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $this->words = array_values(array_unique(array_filter(array_map(
            static fn (string $w): string => mb_strtolower(trim($w)),
            $this->words
        ))));

        file_put_contents($this->filePath, json_encode($this->words, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    /**
     * @return array<string>
     */
    public function getAll(): array
    {
        return $this->words;
    }

    public function add(string $word): bool
    {
        $clean = mb_strtolower(trim($word));
        if ($clean === '') {
            return false;
        }

        if (in_array($clean, $this->words, true)) {
            return true;
        }

        $this->words[] = $clean;
        $this->save();
        return true;
    }

    public function update(string $oldWord, string $newWord): bool
    {
        $oldClean = mb_strtolower(trim($oldWord));
        $newClean = mb_strtolower(trim($newWord));

        if ($oldClean === '' || $newClean === '') {
            return false;
        }

        $key = array_search($oldClean, $this->words, true);
        if ($key === false) {
            return false;
        }

        $this->words[$key] = $newClean;
        $this->save();
        return true;
    }

    public function delete(string $word): bool
    {
        $clean = mb_strtolower(trim($word));
        if ($clean === '') {
            return false;
        }

        $key = array_search($clean, $this->words, true);
        if ($key === false) {
            return false;
        }

        unset($this->words[$key]);
        $this->save();
        return true;
    }

    /**
     * @return array<string> Matched words in text
     */
    public function findMatches(string $text): array
    {
        $textLower = mb_strtolower($text);
        $matched = [];

        foreach ($this->words as $word) {
            if ($word === '') {
                continue;
            }

            if (str_contains($textLower, $word)) {
                $matched[] = $word;
            }
        }

        return array_values(array_unique($matched));
    }

    /**
     * Extracts potential word candidates from text for moderation review
     * @return array<string>
     */
    public function candidates(string $text): array
    {
        $words = preg_split('~\s+~u', trim($text));
        if (!is_array($words)) {
            return [];
        }

        $cleanWords = [];
        foreach ($words as $w) {
            $wClean = trim(preg_replace('~[^\p{L}\p{N}_-]+~u', '', $w));
            if (mb_strlen($wClean) >= 3 && !in_array(mb_strtolower($wClean), $cleanWords, true)) {
                $cleanWords[] = mb_strtolower($wClean);
            }
        }

        return array_slice($cleanWords, 0, 10);
    }
}
