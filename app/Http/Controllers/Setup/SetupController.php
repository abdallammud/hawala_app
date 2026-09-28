<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class SetupController extends Controller
{
    public function index(): View
    {
        return view('setup.index');
    }
}
