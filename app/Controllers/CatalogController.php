<?php

namespace App\Controllers;

use App\Libraries\Management;
use CodeIgniter\HTTP\ResponseInterface;
use RuntimeException;

class CatalogController extends BaseController
{
    public function index(): ResponseInterface
    {
        return $this->response
            ->setHeader('Cache-Control', 'no-store')
            ->setBody(view('catalog_add'));
    }

    public function upload()
    {
        $session = session();
        $session->close();
        $destination = site_url('catalog/add');
        try {
            $upload = $this->request->getFile('catalog');
            if ($upload === null || ! $upload->isValid()) {
                throw new RuntimeException($upload === null ? 'Select a catalog ZIP file.' : $upload->getErrorString());
            }
            if (strtolower($upload->getClientExtension()) !== 'zip') {
                throw new RuntimeException('Upload a ZIP archive.');
            }
            $count = (new Management())->importCatalog($upload->getTempName());
            $level = 'success';
            $message = $count . ' catalog item(s) added.';
            $destination = site_url('/');
        } catch (RuntimeException $exception) {
            $level = 'error';
            $message = $exception->getMessage();
        }
        $session->start();
        $session->setFlashdata($level, $message);
        $session->close();

        return redirect()->to($destination)->withCookies();
    }

    public function create()
    {
        $session = session();
        $session->close();
        $destination = site_url('catalog/add');
        try {
            $name = $this->request->getPost('name');
            $subpath = $this->request->getPost('subpath');
            $compose = $this->request->getPost('compose');
            if (! is_string($name) || ! is_string($subpath) || ! is_string($compose)) {
                throw new RuntimeException('Enter an application name, subpath, and Compose file.');
            }

            (new Management())->importCompose($name, $subpath, $compose);
            $level = 'success';
            $message = trim($name) . ' added under Other.';
            $destination = site_url('category/other');
        } catch (RuntimeException $exception) {
            $level = 'error';
            $message = $exception->getMessage();
        }
        $session->start();
        $session->setFlashdata($level, $message);
        $session->close();

        return redirect()->to($destination)->withCookies();
    }
}
