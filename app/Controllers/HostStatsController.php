<?php

namespace App\Controllers;

use App\Libraries\HostStats;
use CodeIgniter\HTTP\ResponseInterface;
use RuntimeException;

class HostStatsController extends BaseController
{
    public function index(): ResponseInterface
    {
        session()->close();

        try {
            $stats = (new HostStats())->snapshot();
        } catch (RuntimeException $exception) {
            log_message('error', 'Could not read host statistics: {message}', [
                'message' => $exception->getMessage(),
            ]);
            $stats = null;
        }

        return $this->response
            ->setHeader('Cache-Control', 'no-store')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setBody(view('partials/host_stats', ['stats' => $stats]));
    }
}
