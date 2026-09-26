<?php

namespace FA\GraphQL\Tests\Support;

/**
 * The stack's caught mail (docker/fa-mail-catcher). A test snapshots the files
 * before it sends, reads only the new ones, and deletes only those.
 */
final class MailCatcher
{
    public const DIR = '/var/mail-catcher';

    /** @return string[] full paths of every caught message */
    public static function files(): array
    {
        $files = glob(self::DIR . '/*.eml') ?: [];
        sort($files);
        return $files;
    }

    /**
     * @param string[] $before
     * @return string[]
     */
    public static function newSince(array $before): array
    {
        return array_values(array_diff(self::files(), $before));
    }

    /** @param string[] $files */
    public static function delete(array $files): void
    {
        foreach ($files as $file) {
            @unlink($file);
        }
    }

    public static function available(): bool
    {
        return is_dir(self::DIR) && ini_get('sendmail_path') === '/usr/local/bin/fa-mail-catcher';
    }
}
