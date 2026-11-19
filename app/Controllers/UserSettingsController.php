<?php
// do not touch

namespace App\Controllers;
use CodeIgniter\API\ResponseTrait;

helper('preferences');

class UserSettingsController extends BaseController
{
    use ResponseTrait;
    public function set_dark_mode()
    {
        $isDark = $this->request->getPost('dark_mode') === '1';
        preference('dark_mode', $isDark);

        return $this->setResponseFormat('json')->respond(["success" => true,"dark_mode" => $isDark],200);
    }
}
