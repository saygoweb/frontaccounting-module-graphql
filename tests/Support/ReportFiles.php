<?php

namespace FA\GraphQL\Tests\Support;

/**
 * The PDFs FrontAccounting's reports leave in company/<n>/pdf_files. pdf_report.inc
 * End() writes one for every run and removes it only after sending it by email:
 * when there is no contact to send to, it stays, as it does after every print from
 * the web UI. A test snapshots them before it runs a report and deletes only the
 * new ones.
 */
final class ReportFiles
{
    /** @return string[] full paths */
    public static function files(string $faRoot, int $company = 0): array
    {
        $files = glob($faRoot . '/company/' . $company . '/pdf_files/*.pdf') ?: [];
        sort($files);
        return $files;
    }

    /** @param string[] $before */
    public static function deleteNew(string $faRoot, array $before, int $company = 0): void
    {
        foreach (array_diff(self::files($faRoot, $company), $before) as $file) {
            @unlink($file);
        }
    }
}
