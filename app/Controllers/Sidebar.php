<?php

namespace App\Controllers;

use App\Libraries\ApplicationModel;
use CodeIgniter\HTTP\ResponseInterface;

class Sidebar extends BaseController
{
    public function index(): ResponseInterface
    {
        $store = new ApplicationModel();
        $apps = $store->catalog();
        $running = $store->runningApps($apps);
        $links = [['name' => 'Documentation', 'href' => '/docs/']];

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
}
