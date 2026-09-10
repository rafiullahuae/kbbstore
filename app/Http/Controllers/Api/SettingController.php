<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\Setting;
class SettingController extends Controller
{
    /** GET /api/settings — flat { key: value } */
    public function index()
    {
        return response()->json(Setting::map());
    }
}
