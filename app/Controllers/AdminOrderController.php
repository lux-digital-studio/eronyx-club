<?php

declare(strict_types=1);

namespace App\Controllers;

use RuntimeException;

final class AdminOrderController extends AdminBaseController
{
    public function index(): string
    {
        $result = $this->admin->orders($this->queryFilters());

        return $this->view('admin/orders/index.php', $result + [
            'activeNav' => 'orders',
            'indexUrl' => $this->url('/admin/orders'),
            'pageUrl' => fn (int $page): string => $this->pageUrl('/admin/orders', $result['filters'], $page),
        ]);
    }

    public function show(string $id): ?string
    {
        $orderId = $this->routeId($id);

        if ($orderId === null) {
            return $this->notFound();
        }

        try {
            $order = $this->admin->orderDetail($orderId);
        } catch (RuntimeException) {
            return $this->notFound();
        }

        return $this->view('admin/orders/show.php', [
            'order' => $order,
            'activeNav' => 'orders',
            'indexUrl' => $this->url('/admin/orders'),
            'buyerUrl' => $this->url('/admin/users/' . (int) $order['buyer_user_id']),
        ]);
    }
}
