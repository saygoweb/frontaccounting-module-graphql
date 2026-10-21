<?php

namespace FA\GraphQL\Fa;

/**
 * FrontAccounting's HTML escaping of stored text, undone for the API's reads.
 *
 * FrontAccounting escapes request input on arrival: session.inc runs html_cleanup()
 * over $_GET, $_POST and $_REQUEST, which is htmlspecialchars($s, ENT_QUOTES,
 * <the session language's encoding>) (html_specials_encode(), session_utils.inc).
 * And every write normalises again: db_escape() (includes/db/connect_db_mysqli.inc)
 * html_entity_decode()s its value and html_specials_encode()s it once more before
 * quoting it. So text in its tables, whoever wrote it, holds & < > " ' as &amp; &lt;
 * &gt; &quot; &#039; — "Clubs &amp; Charities" — and its screens print it as is,
 * for the browser to show "Clubs & Charities".
 *
 * The API serves the text, not HTML: decode() on the way out, with FrontAccounting's
 * flags and charset. On the way in, cleanInput() is html_cleanup() for a mutation's
 * input, so FrontAccounting's functions get what they get from a screen's POST: plain
 * "&" is stored as "&amp;" either way (db_escape() decodes before it escapes), but
 * text that spells an entity, a literal "&amp;", is kept rather than collapsed to "&"
 * — except where FrontAccounting collapses it on its own screens too: Cart::write()
 * decodes a sales document's comments and line descriptions before db_escape().
 * encode() is the stored form of a client's text, for matching it against stored
 * text (a list's selector).
 */
final class FaText
{
    /**
     * The charset html_specials_encode() uses: the session language's encoding, with
     * ISO-8859-2 as ISO-8859-1 (htmlspecialchars does not support it, and its special
     * characters sit where ISO-8859-1's do). UTF-8 before a session has a language.
     */
    public static function charset(): string
    {
        $language = $_SESSION['language'] ?? null;
        $encoding = is_object($language) && isset($language->encoding) ? (string) $language->encoding : '';
        if ($encoding === '') {
            return 'UTF-8';
        }

        return strtolower($encoding) === 'iso-8859-2' ? 'ISO-8859-1' : $encoding;
    }

    /** Stored text as the text it stands for: "Clubs &amp; Charities" is "Clubs & Charities". */
    public static function decode(string $text): string
    {
        if (strpos($text, '&') === false) {
            return $text;
        }

        // db_escape()'s own call: ENT_QUOTES, the language's charset. @ as there: an
        // encoding PHP does not know warns and falls back to UTF-8.
        return @html_entity_decode($text, ENT_QUOTES, self::charset());
    }

    /**
     * Text as db_escape() stores it: decoded, then html_specials_encode()d. "Clubs &
     * Charities" (or "Clubs &amp; Charities") is "Clubs &amp; Charities".
     */
    public static function encode(string $text): string
    {
        if (strpbrk($text, "&<>\"'") === false) {
            return $text;
        }

        return @htmlspecialchars(self::decode($text), ENT_QUOTES, self::charset());
    }

    /**
     * html_cleanup() of an input: every string in it html_specials_encode()d, as
     * session.inc does to $_POST; keys, numbers, booleans, nulls and objects (a
     * DateTimeImmutable) as they are.
     *
     * @param array<int|string, mixed> $input
     * @return array<int|string, mixed>
     */
    public static function cleanInput(array $input): array
    {
        foreach ($input as $key => $value) {
            if (is_string($value)) {
                $input[$key] = @htmlspecialchars($value, ENT_QUOTES, self::charset());
            } elseif (is_array($value)) {
                $input[$key] = self::cleanInput($value);
            }
        }

        return $input;
    }

    /**
     * A Mango selector (JSON) with each string value encode()d, so it matches the
     * stored text. Field names and operators are keys and stay as they are; a JSON
     * object stays an object and a list a list. Anything that is not a JSON object
     * is returned unchanged, for the list resolver to refuse.
     */
    public static function encodeSelector(string $selector): string
    {
        $decoded = json_decode($selector);
        if (!$decoded instanceof \stdClass) {
            return $selector;
        }
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        $encoded = json_encode(self::encodeValues($decoded), $flags);
        // Nothing to escape: the client's own JSON, byte for byte.
        if ($encoded === false || $encoded === json_encode($decoded, $flags)) {
            return $selector;
        }

        return $encoded;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function encodeValues($value)
    {
        if (is_string($value)) {
            return self::encode($value);
        }
        if (is_array($value)) {
            return array_map([self::class, 'encodeValues'], $value);
        }
        if ($value instanceof \stdClass) {
            // Through an array: a JSON key may be "", which no property access can set.
            return (object) array_map([self::class, 'encodeValues'], get_object_vars($value));
        }

        return $value;
    }
}
