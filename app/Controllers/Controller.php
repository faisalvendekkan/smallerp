<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Lang;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Support\Text;

/** Shared helpers for every controller. */
abstract class Controller
{
    /** Render a page inside the application shell. */
    protected function view(string $template, array $data = [], string $layout = 'layout/app'): Response
    {
        $data['currentPath'] ??= \App\Core\App::currentPath();
        $data['navAlerts'] ??= $this->navAlerts();

        return Response::html(View::render($template, $data, $layout));
    }

    /** Render a printable document. */
    protected function printView(string $template, array $data = []): Response
    {
        return Response::html(View::render($template, $data, 'layout/print'));
    }

    protected function redirect(string $path, string $message = '', string $type = 'success'): Response
    {
        if ($message !== '') {
            View::flash($message, $type);
        }

        return Response::redirect(url($path));
    }

    /** Fetch a record or raise a 404. */
    protected function findOr404(?array $record, string $what = 'record'): array
    {
        if ($record === null) {
            throw HttpException::notFound("That {$what} does not exist, or has been deleted.");
        }

        return $record;
    }

    /**
     * Badge counts shown against the sidebar links.
     *
     * Deliberately cheap -- a handful of indexed COUNT queries -- because it
     * runs on every page.
     */
    protected function navAlerts(): array
    {
        $today = date('Y-m-d');
        $alerts = [];

        if (Auth::can('sales.view')) {
            $overdue = (int) Database::value(
                "SELECT COUNT(*) FROM sales_invoices WHERE status IN ('posted','partial') AND due_date < ?",
                [$today],
                0
            );
            if ($overdue > 0) {
                $alerts['/invoices'] = $overdue;
            }
        }

        if (Auth::can('hr.view')) {
            $expiring = (int) Database::value(
                "SELECT COUNT(*) FROM employees
                 WHERE status <> 'terminated'
                   AND ((qid_expiry IS NOT NULL AND qid_expiry <> '' AND qid_expiry <= ?)
                     OR (visa_expiry IS NOT NULL AND visa_expiry <> '' AND visa_expiry <= ?))",
                [date('Y-m-d', strtotime('+30 days')), date('Y-m-d', strtotime('+30 days'))],
                0
            );
            if ($expiring > 0) {
                $alerts['/employees'] = $expiring;
            }
        }

        if (Auth::can('hr.view')) {
            $pendingLeave = (int) Database::value(
                "SELECT COUNT(*) FROM leave_requests WHERE status = 'pending'",
                [],
                0
            );
            if ($pendingLeave > 0) {
                $alerts['/leave'] = $pendingLeave;
            }
        }

        return $alerts;
    }

    /** Current page number from the query string. */
    protected function page(Request $request): int
    {
        return max(1, $request->int('page', 1));
    }

    /**
     * Build the paging links for a list screen.
     *
     * @return array{page:int,pages:int,total:int,per_page:int,from:int,to:int}
     */
    protected function paginate(int $total, int $page, int $perPage = 25): array
    {
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);

        return [
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
            'per_page' => $perPage,
            'from' => $total === 0 ? 0 : ($page - 1) * $perPage + 1,
            'to' => min($page * $perPage, $total),
        ];
    }

    /**
     * Send rows to the browser as a CSV download.
     *
     * @param array<int,string>              $headings
     * @param array<int,array<int,mixed>>    $rows
     */
    protected function csv(string $filename, array $headings, array $rows): Response
    {
        $handle = fopen('php://temp', 'r+');
        // A BOM makes Excel open UTF-8 correctly, which matters when half the
        // names in the file are Arabic.
        fwrite($handle, "\xEF\xBB\xBF");
        // The escape character is passed explicitly: PHP 8.4 deprecates
        // relying on the default, and "" is the behaviour Excel expects.
        fputcsv($handle, $headings, ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($handle, array_map([Text::class, 'csvCell'], $row), ',', '"', '');
        }
        rewind($handle);
        $body = (string) stream_get_contents($handle);
        fclose($handle);

        return Response::download($body, $filename);
    }

    /** The date range a report screen is being asked for. */
    protected function dateRange(Request $request): array
    {
        return [
            'from' => $request->date('from') ?? date('Y-m-01'),
            'to' => $request->date('to') ?? date('Y-m-d'),
        ];
    }

    /** Pick the right name column for the active language. */
    protected function name(?array $row, string $base = 'name'): string
    {
        return Lang::pick($row, $base);
    }
}
