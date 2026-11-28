<?php
// do not touch
namespace App\Controllers;

use App\Libraries\Catalog;
use App\Libraries\Deployment;
use CodeIgniter\HTTP\ResponseInterface;

class Sidebar extends BaseController
{
    public function index(): ResponseInterface
    {
        $catalog = new Catalog();
        $deployment = new Deployment($catalog);
        $apps = $catalog->catalog();
        $running = $deployment->runningApps($apps);
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
