<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Lang;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;

/** Sign in, sign out and the language switcher. */
final class AuthController extends Controller
{
    public function showLogin(Request $request): Response
    {
        if (Auth::check()) {
            return Response::redirect(url('/'));
        }

        return Response::html(View::render('auth/login', [
            'title' => __('action.sign_in'),
        ], 'layout/blank'));
    }

    public function login(Request $request): Response
    {
        $username = $request->required('username', 'Username');
        $password = (string) $request->input('password', '');

        Auth::attempt($username, $password, $request);

        // Send the user back to whatever they were trying to reach.
        $intended = $_SESSION['intended_url'] ?? '/';
        unset($_SESSION['intended_url']);

        return $this->redirect($intended, 'Welcome back, ' . (Auth::user()['name'] ?? '') . '.');
    }

    public function logout(Request $request): Response
    {
        Auth::logout();

        return Response::redirect(url('/login'));
    }

    public function switchLocale(Request $request): Response
    {
        $locale = $request->string('locale') === 'ar' ? 'ar' : 'en';
        Lang::setLocale($locale);

        if (Auth::check()) {
            \App\Core\Database::update('users', ['locale' => $locale], ['id' => Auth::id()]);
        }

        return Response::redirect(url($request->backUrl('/')));
    }
}
