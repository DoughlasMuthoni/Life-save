<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

class LogoutController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        Auth::logout();

        Session::invalidate();
        Session::regenerateToken();

        return redirect()->route('login');
    }
}
