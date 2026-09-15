<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\Reports;
use App\Services\Settings;

/** The landing screen: money, compliance and anything needing attention. */
final class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        return $this->view('dashboard/index', [
            'title' => __('nav.dashboard'),
            'data' => Reports::dashboard(),
            // Nudge a fresh installation towards filling in the details a
            // printed invoice and a WPS file both need.
            'setup_needed' => !Settings::isCompanyConfigured(),
            'wps_needed' => !Settings::isWpsConfigured(),
        ]);
    }
}
