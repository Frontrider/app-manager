<?php

namespace App\Controllers;

use App\Libraries\Catalog;
use App\Libraries\Deployment;
use App\Libraries\LoggingModel;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;
use RuntimeException;

class LoggingController extends BaseController
{
    public function logs(string $id): ResponseInterface
    {
        $catalog = new Catalog();
        try {
            $app = $catalog->find($id);
        } catch (RuntimeException $exception) {
            throw PageNotFoundException::forPageNotFound($exception->getMessage());
        }

        return $this->response
            ->setHeader('Cache-Control', 'no-store')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setBody(view('app_logs', [
                'app' => $app,
                'category' => $catalog->category($app),
                'logLines' => (new LoggingModel())->logLines('Loading logs...'),
            ]));
    }

    public function logOutput(string $id): ResponseInterface
    {
        session()->close();
        $catalog = new Catalog();
        $deployment = new Deployment($catalog);
        try {
            $app = $catalog->find($id);
        } catch (RuntimeException $exception) {
            throw PageNotFoundException::forPageNotFound($exception->getMessage());
        }

        $this->response
            ->setContentType('text/html', 'UTF-8')
            ->setHeader('Cache-Control', 'no-store')
            ->setHeader('X-Content-Type-Options', 'nosniff');
        $logging = new LoggingModel();
        try {
            $logs = $logging->plainTextLogs($deployment->logs($app));
        } catch (RuntimeException $exception) {
            log_message('error', 'Could not read app logs: {message}', ['message' => $exception->getMessage()]);
            return $this->response->setStatusCode(500)
                ->setBody(view('partials/log_output', [
                    'app' => $app,
                    'lines' => $logging->logLines('Could not read logs: ' . $exception->getMessage()),
                ]));
        }

        return $this->response->setBody(view('partials/log_output', [
            'app' => $app,
            'lines' => $logging->logLines($logs),
        ]));
    }
}
