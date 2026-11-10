<?php

namespace App\Controllers;

use App\Libraries\ApplicationModel;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;
use RuntimeException;

class Home extends BaseController
{
    public function activity(): ResponseInterface
    {
        return $this->response->setHeader('Cache-Control', 'no-store')->setBody(view('activity', [
            'canManage' => session()->get('ldap_user')['isAdmin'],
        ]));
    }

    public function activityOutput(): ResponseInterface
    {
        session()->close();
        $this->response->setHeader('Cache-Control', 'no-store');
        try {
            return $this->response->setJSON(['applications' => (new ApplicationModel())->applicationActivity()]);
        } catch (RuntimeException | \JsonException $exception) {
            log_message('error', 'Could not read application activity: {message}', [
                'message' => $exception->getMessage(),
            ]);
            return $this->response->setStatusCode(500)->setJSON([
                'error' => 'Could not read application activity. Try again shortly.',
            ]);
        }
    }

    public function index(?string $category = null): ResponseInterface
    {
        $store = new ApplicationModel();
        $apps  = $store->catalog();
        $running = $store->runningApps($apps);
        $canManage = session()->get('ldap_user')['isAdmin'] === true;
        $environments = [];
        if ($canManage) {
            foreach ($apps as $app) {
                if (! $running[$app['id']]) {
                    $environments[$app['id']] = $store->environmentFields($app);
                }
            }
        }
        $categories = [];
        foreach ($apps as $app) {
            $categories[$store->category($app)][] = $app;
        }
        uksort($categories, static function (string $first, string $second): int {
            if ($first === 'files' || $second === 'files') {
                return ($second === 'files') <=> ($first === 'files');
            }

            return strnatcasecmp($first, $second);
        });
        $selectedCategory = $category === null
            ? array_key_first($categories)
            : implode(' / ', explode('/', $category));
        if ($selectedCategory !== null
            && (! is_string($selectedCategory) || ! array_key_exists($selectedCategory, $categories))) {
            throw PageNotFoundException::forPageNotFound('That category is not available.');
        }
        $query = $this->request->getPost('search');
        if ($query !== null && (! is_string($query) || strlen($query) > 200)) {
            throw PageNotFoundException::forPageNotFound('Invalid catalog search.');
        }
        $query = trim($query ?? '');
        $displayApps = $categories[$selectedCategory] ?? [];
        if ($this->request->getMethod() === 'POST') {
            $selectedCategory = null;
            $displayApps = array_values(array_filter($apps, fn (array $app): bool =>
                $query === '' || mb_stripos($app['name'] . ' ' . $store->category($app), $query) !== false));
        }

        return $this->response->setHeader('Cache-Control', 'no-store')->setBody(view('browser', [
            'apps'    => $apps,
            'running' => $running,
            'categories' => $categories,
            'selectedCategory' => $selectedCategory,
            'installationBlocks' => $store->installationBlocks($apps, $running),
            'canManage' => $canManage,
            'environments' => $environments,
            'searchQuery' => $query,
            'displayApps' => $displayApps,
        ]));
    }

    public function install(string $id)
    {
        return $this->runAction($id, 'install');
    }

    public function uploadCatalog()
    {
        $session = session();
        $session->close();
        try {
            $upload = $this->request->getFile('catalog');
            if ($upload === null || ! $upload->isValid()) {
                throw new RuntimeException($upload === null ? 'Select a catalog ZIP file.' : $upload->getErrorString());
            }
            if (strtolower($upload->getClientExtension()) !== 'zip') {
                throw new RuntimeException('Upload a ZIP archive.');
            }
            $count = (new ApplicationModel())->importCatalog($upload->getTempName());
            $level = 'success';
            $message = $count . ' catalog item(s) added.';
        } catch (RuntimeException $exception) {
            $level = 'error';
            $message = $exception->getMessage();
        }
        $session->start();
        $session->setFlashdata($level, $message);
        $session->close();

        return redirect()->to(site_url('/'))->withCookies();
    }

    public function uninstall(string $id)
    {
        return $this->runAction($id, 'uninstall');
    }

    public function logs(string $id): ResponseInterface
    {
        $store = new ApplicationModel();
        try {
            $app = $store->find($id);
        } catch (RuntimeException $exception) {
            throw PageNotFoundException::forPageNotFound($exception->getMessage());
        }

        return $this->response
            ->setHeader('Cache-Control', 'no-store')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setBody(view('app_logs', [
                'app' => $app,
                'category' => $store->category($app),
            ]));
    }

    public function logOutput(string $id): ResponseInterface
    {
        session()->close();
        $store = new ApplicationModel();
        try {
            $app = $store->find($id);
        } catch (RuntimeException $exception) {
            throw PageNotFoundException::forPageNotFound($exception->getMessage());
        }

        $this->response
            ->setContentType('text/plain', 'UTF-8')
            ->setHeader('Cache-Control', 'no-store')
            ->setHeader('X-Content-Type-Options', 'nosniff');
        try {
            return $this->response->setBody($this->plainTextLogs($store->logs($app)));
        } catch (RuntimeException $exception) {
            log_message('error', 'Could not read app logs: {message}', ['message' => $exception->getMessage()]);
            return $this->response->setStatusCode(500)
                ->setBody($this->plainTextLogs('Could not read logs: ' . $exception->getMessage()));
        }
    }

    private function plainTextLogs(string $logs): string
    {
        // Remove terminal strings, CSI commands, and two-byte escape commands, not Unicode bytes.
        $logs = preg_replace(
            '/\x1B(?:\][^\x07\x1B]*(?:\x07|\x1B\\\\)|[PX^_][\s\S]*?\x1B\\\\|\[[0-?]*[ -\/]*[@-~]|[ -\/]*[0-~])/',
            '',
            $logs,
        );
        if ($logs === null) {
            throw new RuntimeException('Could not normalize terminal log output.');
        }

        return str_replace(["\r\n", "\r"], "\n", $logs);
    }

    public function logo(string $id): ResponseInterface
    {
        $store = new ApplicationModel();

        try {
            $app = $store->find($id);
        } catch (RuntimeException $exception) {
            throw PageNotFoundException::forPageNotFound($exception->getMessage());
        }

        $header = $store->headerFile($app);
        $image  = file_get_contents($header ?? FCPATH . 'images/app-placeholder.svg');

        if ($image === false) {
            throw new RuntimeException('Could not read the app logo.');
        }

        return $this->response
            ->setContentType($header === null ? 'image/svg+xml' : 'image/png')
            ->setHeader('Cache-Control', 'no-store')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setBody($image);
    }

    private function runAction(string $id, string $action)
    {
        $session = session();
        $session->close();
        $category = null;

        try {
            $store = new ApplicationModel();
            $app   = $store->find($id);
            $category = $store->category($app);

            if ($action === 'install') {
                $environment = $this->request->getPost('environment');
                if ($environment === null && $this->request->getPost('environment_empty') === '1') {
                    $environment = [];
                }
                if ($environment !== null && ! is_array($environment)) {
                    throw new RuntimeException('Invalid app environment settings.');
                }
                if (! empty($store->environmentFields($app)) && $environment === null) {
                    throw new RuntimeException('Configure the app environment before starting it.');
                }
                $store->install($app, $environment);
                $message = $app['name'] . ' started.';
            } elseif ($action === 'refresh') {
                $store->refresh($app);
                $message = $app['name'] . ' refreshed.';
            } else {
                $store->uninstall($app);
                $message = $app['name'] . ' stopped.';
            }
            $level = 'success';
        } catch (RuntimeException $exception) {
            $level = 'error';
            $message = $exception->getMessage();
        }

        $session->start();
        $session->setFlashdata($level, $message);
        $session->close();

        $destination = site_url('/');
        if ($category !== null) {
            $destination = site_url('category/' . implode('/', array_map('rawurlencode', explode(' / ', $category))));
        }

        return redirect()->to($destination)->withCookies();
    }

    public function refresh(string $id)
    {
        return $this->runAction($id, 'refresh');
    }

    public function stopAll()
    {
        $session = session();
        $session->close();
        try {
            (new ApplicationModel())->stopAll();
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
}
