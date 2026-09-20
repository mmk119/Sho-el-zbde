<?php

namespace App\Services\Analysis;

/**
 * Validates model output before any of it reaches the database.
 *
 * Deliberately strict about STRUCTURE - a missing key or a wrong type is a
 * rejection - and deliberately quiet about TASTE. "Three to five bullets" is an
 * instruction in the prompt, not a rule here: failing a genuinely short voice
 * note because it only warranted two bullets would turn a good digest into a
 * failed note.
 *
 * `notes` is required even when empty. An omitted notes key is indistinguishable
 * from a clean transcript, and the whole point of the field is to tell those two
 * situations apart.
 */
final class DigestSchema
{
    public const ENTITY_KEYS = ['dates', 'times', 'amounts', 'names', 'places'];

    public const URGENCIES = ['low', 'normal', 'high'];

    /** Upper bound only - a sanity rail against a runaway response. */
    private const MAX_LIST_ITEMS = 50;

    /** @return string[] list of problems; empty means valid */
    public static function errors(mixed $payload): array
    {
        if (! is_array($payload)) {
            return ['response was not a JSON object'];
        }

        $errors = [];

        foreach (['summary', 'questions', 'action_items', 'notes'] as $key) {
            $errors = array_merge($errors, self::checkStringList($payload, $key));
        }

        if (! array_key_exists('urgency', $payload)) {
            $errors[] = 'missing key: urgency';
        } elseif (! in_array($payload['urgency'], self::URGENCIES, true)) {
            $errors[] = 'urgency must be one of: '.implode(', ', self::URGENCIES);
        }

        if (! array_key_exists('entities', $payload)) {
            $errors[] = 'missing key: entities';
        } elseif (! is_array($payload['entities']) || array_is_list($payload['entities'])) {
            $errors[] = 'entities must be an object';
        } else {
            foreach (self::ENTITY_KEYS as $key) {
                $errors = array_merge($errors, self::checkStringList($payload['entities'], $key, 'entities.'));
            }
        }

        return $errors;
    }

    public static function isValid(mixed $payload): bool
    {
        return self::errors($payload) === [];
    }

    /** @return string[] */
    private static function checkStringList(array $haystack, string $key, string $prefix = ''): array
    {
        if (! array_key_exists($key, $haystack)) {
            return ["missing key: {$prefix}{$key}"];
        }

        $value = $haystack[$key];

        if (! is_array($value) || ! array_is_list($value)) {
            return ["{$prefix}{$key} must be an array of strings"];
        }

        if (count($value) > self::MAX_LIST_ITEMS) {
            return ["{$prefix}{$key} has more than ".self::MAX_LIST_ITEMS.' items'];
        }

        foreach ($value as $item) {
            if (! is_string($item)) {
                return ["{$prefix}{$key} must contain only strings"];
            }
        }

        return [];
    }

    /**
     * Normalise a validated payload into exactly the shape the digests table
     * expects, dropping anything the model invented beyond the schema.
     */
    public static function normalize(array $payload): array
    {
        $entities = [];

        foreach (self::ENTITY_KEYS as $key) {
            $entities[$key] = array_values($payload['entities'][$key]);
        }

        return [
            'summary' => array_values($payload['summary']),
            'questions' => array_values($payload['questions']),
            'entities' => $entities,
            'action_items' => array_values($payload['action_items']),
            'notes' => array_values($payload['notes']),
            'urgency' => $payload['urgency'],
        ];
    }
}
