<?php

namespace App\Controllers;

use App\Libraries\AppStore;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;
use RuntimeException;

class Home extends BaseController
{
    public function index(?string $category = null): ResponseInterface
    {
        $store = new AppStore();
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

        return $this->response->setHeader('Cache-Control', 'no-store')->setBody(view('welcome_message', [
            'apps'    => $apps,
            'running' => $running,
            'categories' => $categories,
            'selectedCategory' => $selectedCategory,
            'installationBlocks' => $store->installationBlocks($apps, $running),
            'canManage' => $canManage,
            'environments' => $environments,
        ]));
    }

    public function install(string $id)
    {
        return $this->runAction($id, 'install');
    }

    public function sidebar(): ResponseInterface
    {
        $store = new AppStore();
        $apps = $store->catalog();
        $running = $store->runningApps($apps);
        $links = [];

        foreach ($apps as $app) {
            if ($running[$app['id']] && $app['caddyPath'] !== null) {
                $links[] = ['name' => $app['name'], 'href' => $app['caddyPath']];
            }
        }

        return $this->response
            ->setHeader('Cache-Control', 'no-store')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setJSON($links);
    }

    public function uninstall(string $id)
    {
        return $this->runAction($id, 'uninstall');
    }

    public function logo(string $id): ResponseInterface
    {
        $store = new AppStore();

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
            $store = new AppStore();
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
}
