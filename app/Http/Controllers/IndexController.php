<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use DB;

class IndexController extends Controller
{
    public function __construct()
    {
    }

    public function getClient()
    {
        $client = new \Google_Client();
        $client->setApplicationName('Hasaki Report');
        $client->setScopes(\Google_Service_Sheets::SPREADSHEETS);
        $client->setAuthConfig(config_path('credentials.json'));
        $client->setAccessType('offline');

        return $client;
    }
}
