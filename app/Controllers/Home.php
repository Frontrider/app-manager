<?php
// do not touch
namespace App\Controllers;

use App\Libraries\Catalog;
use App\Libraries\Deployment;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;
use RuntimeException;

class Home extends BaseController
{
    public function index(?string $category = null): ResponseInterface
    {
        $catalog = new Catalog();
        $deployment = new Deployment($catalog);
        $apps  = $catalog->catalog();
        $running = $deployment->runningApps($apps);
        $canManage = session()->get('ldap_user')['isAdmin'] === true;
        $environments = [];
        if ($canManage) {
            foreach ($apps as $app) {
                if (! $running[$app['id']]) {
                    $environments[$app['id']] = $deployment->environmentFields($app);
                }
            }
        }
        $categories = [];
        foreach ($apps as $app) {
            $categories[$catalog->category($app)][] = $app;
        }
        uksort($categories, static function (string $first, string $second): int {
            $fileCategories = ['file-manager', 'files'];
            $firstIsFileCategory = in_array($first, $fileCategories, true);
            $secondIsFileCategory = in_array($second, $fileCategories, true);
            if ($firstIsFileCategory || $secondIsFileCategory) {
                return $secondIsFileCategory <=> $firstIsFileCategory;
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
                $query === '' || mb_stripos($app['name'] . ' ' . $catalog->category($app), $query) !== false));
        }

        return $this->response->setHeader('Cache-Control', 'no-store')->setBody(view('browser', [
            'apps'    => $apps,
            'running' => $running,
            'categories' => $categories,
            'selectedCategory' => $selectedCategory,
            'installationBlocks' => $catalog->installationBlocks($apps, $running),
            'canManage' => $canManage,            'environments' => $environments,
            'searchQuery' => $query,
            'displayApps' => $displayApps,
        ]));
    }

    public function logo(string $id): ResponseInterface
    {
        $catalog = new Catalog();

        try {
            $app = $catalog->find($id);
        } catch (RuntimeException $exception) {
            throw PageNotFoundException::forPageNotFound($exception->getMessage());
        }

        $header = $catalog->headerFile($app);
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
}
