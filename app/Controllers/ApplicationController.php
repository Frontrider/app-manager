<?php

namespace App\Controllers;

use App\Libraries\Catalog;
use App\Libraries\Deployment;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;
use RuntimeException;

class ApplicationController extends BaseController
{
    public function activity(): ResponseInterface
    {
        $user = session()->get('ldap_user');
        $canManage = is_array($user) && ($user['isAdmin'] ?? false) === true;
        session()->close();
        [$applications, $error] = $this->readActivity();

        return $this->response
            ->setHeader('Cache-Control', 'no-store')
            ->setBody(view('activity', [
                'applications' => $applications,
                'canManage' => $canManage,
                'error' => $error,
            ]));
    }

    public function activityOutput(): ResponseInterface
    {
        $user = session()->get('ldap_user');
        $canManage = is_array($user) && ($user['isAdmin'] ?? false) === true;
        session()->close();

        $this->response
            ->setContentType('text/html', 'UTF-8')
            ->setHeader('Cache-Control', 'no-store')
            ->setHeader('X-Content-Type-Options', 'nosniff');

        [$applications, $error] = $this->readActivity();

        if ($error !== null) {
            return $this->response->setStatusCode(500)->setBody(view('partials/activity_content', [
                'applications' => [],
                'canManage' => $canManage,
                'error' => $error,
            ]));
        }

        // htmx stops polling on 286. Running and Stopped only change after a
        // user action, so there is nothing more to poll for.
        if ($this->activityIsSettled($applications)) {
            $this->response->setStatusCode(286, 'No Further Polling');
        }

        return $this->response->setBody(view('partials/activity_content', [
            'applications' => $applications,
            'canManage' => $canManage,
            'error' => null,
        ]));
    }

    /**
     * @return array{0: list<array>, 1: ?string}
     */
    private function readActivity(): array
    {
        try {
            return [(new Deployment())->applicationActivity(), null];
        } catch (RuntimeException | \JsonException $exception) {
            log_message('error', 'Could not read application activity: {message}', [
                'message' => $exception->getMessage(),
            ]);

            return [[], 'Could not read application activity. Try again shortly.'];
        }
    }

    /**
     * @param list<array{status: string}> $applications
     */
    private function activityIsSettled(array $applications): bool
    {
        foreach ($applications as $application) {
            if (! in_array($application['status'], ['Running', 'Stopped'], true)) {
                return false;
            }
        }

        return true;
    }

    public function install(string $id)
    {
        return $this->runAction($id, 'install');
    }

    public function uninstall(string $id)
    {
        return $this->runAction($id, 'uninstall');
    }

    public function deleteData(string $id)
    {
        $session = session();
        $session->close();
        try {
            $catalog = new Catalog();
            $deployment = new Deployment($catalog);
            $app = $catalog->find($id);
            $deployment->deleteData($app);
            $level = 'success';
            $message = $app['name'] . ' stopped and its project volumes were deleted.';
        } catch (RuntimeException $exception) {
            $level = 'error';
            $message = $exception->getMessage();
        }
        $session->start();
        $session->setFlashdata($level, $message);
        $session->close();

        return redirect()->to(site_url('activity'))->withCookies();
    }

    public function confirmDeleteData(string $id): ResponseInterface
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
            ->setBody(view('partials/delete_data_modal', ['app' => $app]));
    }

    public function refresh(string $id)
    {
        return $this->runAction($id, 'refresh');
    }

    public function configure(string $id): ResponseInterface
    {
        session()->close();
        $catalog = new Catalog();
        $deployment = new Deployment($catalog);
        try {
            $app = $catalog->find($id);
        } catch (RuntimeException $exception) {
            throw PageNotFoundException::forPageNotFound($exception->getMessage());
        }

        $apps = $catalog->catalog();
        $running = $deployment->runningApps($apps);

        return $this->response
            ->setHeader('Cache-Control', 'no-store')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setBody(view('partials/deploy_modal', [
                'app' => $app,
                'environmentFields' => $deployment->environmentFields($app),
                'willBlock' => array_values($catalog->deploymentBlocks($app, $apps, $running)),
            ]));
    }

    public function stopAll()
    {        $session = session();
        $session->close();
        try {
            (new Deployment())->stopAll();
            $level = 'success';
            $message = 'All catalog apps stopped. Containers and data were preserved.';
        } catch (RuntimeException $exception) {
            $level = 'error';
            $message = $exception->getMessage();
        }
        $session->start();
        $session->setFlashdata($level, $message);
        $session->close();

        return redirect()->to(site_url('/'))->withCookies();
    }

    private function runAction(string $id, string $action)
    {
        $session = session();
        $session->close();
        $category = null;
        $deferred = null;

        try {
            $catalog = new Catalog();
            $deployment = new Deployment($catalog);
            $app   = $catalog->find($id);
            $category = $catalog->category($app);

            if ($action === 'install') {
                $environment = $this->request->getPost('environment');
                if ($environment === null && $this->request->getPost('environment_empty') === '1') {
                    $environment = [];
                }
                if ($environment !== null && ! is_array($environment)) {
                    throw new RuntimeException('Invalid app environment settings.');
                }
                if (! empty($deployment->environmentFields($app)) && $environment === null) {
                    throw new RuntimeException('Configure the app environment before starting it.');
                }
                $operation = static fn () => $deployment->install($app, $environment);
                $message = $app['name'] . ' started.';
                $pending = $app['name'] . ' is starting. This can take a while.';
            } elseif ($action === 'refresh') {
                $operation = static fn () => $deployment->refresh($app);
                $message = $app['name'] . ' refreshed.';
                $pending = $app['name'] . ' is refreshing. This can take a while.';
            } else {
                $deployment->uninstall($app);
                $message = $app['name'] . ' stopped.';
            }
            $level = 'success';

            if (isset($operation)) {
                if ($this->canFinishRequestEarly()) {
                    // Docker creates and removes interfaces while it builds and
                    // starts containers. Browsers and VPNs can abort a request
                    // that stays open for that long, so answer before the work.
                    $deferred = $operation;
                    $message = $pending;
                } else {
                    $operation();
                }
            }
        } catch (RuntimeException $exception) {
            $level = 'error';
            $message = $exception->getMessage();
        }

        $session->start();
        $session->setFlashdata($level, $message);
        $session->close();

        if ($deferred !== null) {
            $this->runAfterResponse($deferred);
        }

        $destination = site_url('/');
        if ($category !== null) {
            $destination = site_url('category/' . implode('/', array_map('rawurlencode', explode(' / ', $category))));
        }

        return redirect()->to($destination)->withCookies();
    }

    private function canFinishRequestEarly(): bool
    {
        return function_exists('fastcgi_finish_request');
    }

    private function runAfterResponse(callable $operation): void
    {
        ignore_user_abort(true);
        register_shutdown_function(static function () use ($operation): void {
            fastcgi_finish_request();
            try {
                $operation();
            } catch (\Throwable $exception) {
                log_message('error', 'Deferred app operation failed: {message}', [
                    'message' => $exception->getMessage(),
                ]);
            }
        });
    }
}
