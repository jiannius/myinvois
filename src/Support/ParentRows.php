<?php

namespace Jiannius\Myinvois\Support;

use Jiannius\Myinvois\Helpers\UBL;

/**
 * Which parent each myinvois_documents row of a batch belongs to.
 *
 * A row is one document, or one line item of a consolidated document. Parents are keyed by
 * the document number, which for a consolidated document covers every one of its rows. A
 * consolidated document without its own key may instead have each line keyed by its
 * description -- but only when that description names nothing else in the batch (another
 * document's number, or a line of another document resolved the same way), so two
 * documents sharing "Audit Services" are refused instead of getting each other's parent.
 */
class ParentRows
{
    /**
     * One entry per row (per line item for a consolidated document, duplicates included,
     * like the rows themselves). `key` / `parent` are null for a row with no parent given.
     *
     * @param  iterable<int, array>  $documents  the flat document contract
     * @param  array<string, mixed>  $parents  parent key => model or reference
     * @return array<int, array{document: ?string, row: ?string, key: ?string, parent: mixed}>
     *
     * @throws \InvalidArgumentException when a line key is ambiguous
     */
    public static function resolve(iterable $documents, array $parents) : array
    {
        $documents = collect($documents)->values();
        $numbers = $documents->map(fn ($document) => static::string(data_get($document, 'number')))->filter()->all();
        $has = fn ($key) => $key !== null && array_key_exists($key, $parents) && $parents[$key] !== null;

        $entries = [];
        $lineOwners = [];

        foreach ($documents as $index => $document) {
            $number = static::string(data_get($document, 'number'));

            if (! UBL::isConsolidated($document)) {
                $entries[] = ['document' => $number, 'row' => $number, 'key' => $has($number) ? $number : null];
                continue;
            }

            foreach (data_get($document, 'line_items', []) ?: [] as $line) {
                $row = static::string(data_get($line, 'description'));
                $key = $has($number) ? $number : ($has($row) ? $row : null);

                if ($key !== null && $key === $row) $lineOwners[$row][$index] = true;

                $entries[] = ['document' => $number, 'row' => $row, 'key' => $key];
            }
        }

        $ambiguous = collect($lineOwners)
            ->filter(fn ($owners, $row) => count($owners) > 1 || in_array((string) $row, $numbers, true))
            ->keys()
            ->map(fn ($row) => (string) $row);

        if ($ambiguous->isNotEmpty()) {
            throw new \InvalidArgumentException(
                'The line description '.$ambiguous->map(fn ($row) => "\"$row\"")->join(', ').' names more than one document in this batch. Key the parents of consolidated documents by their document number.'
            );
        }

        return array_map(fn ($entry) => [...$entry, 'parent' => $entry['key'] === null ? null : $parents[$entry['key']]], $entries);
    }

    /**
     * Row numbers (or document numbers) without a parent.
     *
     * @param  array<int, array{document: ?string, row: ?string, key: ?string, parent: mixed}>  $entries
     * @return array<int, string>
     */
    public static function missing(array $entries) : array
    {
        return collect($entries)
            ->filter(fn ($entry) => $entry['key'] === null)
            ->map(fn ($entry) => $entry['row'] ?? $entry['document'])
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    protected static function string($value) : ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }
}
