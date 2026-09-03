<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\WorkspaceSummary;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\View\View;

/**
 * The home screen of Ai Ecommerce Studio.
 *
 * All of the gathering lives in WorkspaceSummary, because the AI Studio tab of
 * the management dashboard shows the same picture and two copies of it would
 * eventually disagree about the same numbers.
 */
class DashboardController extends Controller
{
    public function index(#[CurrentUser] User $user): View
    {
        return view('dashboard', WorkspaceSummary::for($user));
    }
}
