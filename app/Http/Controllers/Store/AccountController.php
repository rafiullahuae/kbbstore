<?php

declare(strict_types=1);

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Services\AccountPanel;
use App\Services\HumanCheck;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * The account area.
 *
 * The header has linked here since the panel was added, but nothing served
 * these addresses — every one of them returned a 404. A guest gets the sign-in
 * and register forms; a customer gets their orders and details.
 */
class AccountController extends Controller
{
    public function __construct(
        private AccountPanel $panel,
        private HumanCheck $check,
    ) {}

    public function index(): View
    {
        if (auth()->guard()->check()) {
            return view('store.account.dashboard', [
                'customer' => auth()->guard()->user(),
                'orders' => $this->orderList(3),
                'panel' => $this->panel->all(),
            ]);
        }

        return view('store.account.login', [
            'panel' => $this->panel->all(),
            'hc' => $this->panel->get('sum_show') ? $this->check->issue() : null,
            'tab' => request()->query('tab') === 'register' ? 'register' : 'login',
        ]);
    }

    public function orders(): View
    {
        return view('store.account.orders', [
            'customer' => auth()->guard()->user(),
            'orders' => $this->orderList(50),
        ]);
    }

    public function addresses(): View
    {
        return view('store.account.addresses', ['customer' => auth()->guard()->user()]);
    }

    public function forgot(): View
    {
        return view('store.account.forgot');
    }

    public function track(): View
    {
        return view('store.account.track');
    }

    /**
     * Recent orders, or an empty list if the table is not there yet.
     *
     * The account page must render for a new shop as readily as an established
     * one, so a missing table is an empty list rather than an error.
     */
    private function orderList(int $limit): array
    {
        $id = auth()->guard()->id();

        if (! $id) {
            return [];
        }

        try {
            return \Illuminate\Support\Facades\DB::table('orders')
                ->where('customer_id', $id)
                ->orderByDesc('id')
                ->limit($limit)
                ->get()
                ->all();
        } catch (\Throwable $e) {
            return [];
        }
    }
}
