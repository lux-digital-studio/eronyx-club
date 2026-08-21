<?php

declare(strict_types=1);

namespace App\Controllers;

use RuntimeException;

final class AdminListingController extends AdminBaseController
{
    public function index(): string
    {
        $result = $this->admin->listings($this->queryFilters());

        return $this->view('admin/listings/index.php', $result + [
            'activeNav' => 'listings',
            'indexUrl' => $this->url('/admin/listings'),
            'pageUrl' => fn (int $page): string => $this->pageUrl('/admin/listings', $result['filters'], $page),
        ]);
    }

    public function show(string $id): ?string
    {
        $listingId = $this->routeId($id);

        if ($listingId === null) {
            return $this->notFound();
        }

        try {
            $listing = $this->admin->listingDetail($listingId);
        } catch (RuntimeException) {
            return $this->notFound();
        }

        return $this->view('admin/listings/show.php', [
            'listing' => $listing,
            'activeNav' => 'listings',
            'indexUrl' => $this->url('/admin/listings'),
            'ownerUrl' => $this->url('/admin/users/' . (int) $listing['owner_user_id']),
            'moderatorUrl' => $this->url('/moderator/listings/' . $listingId),
        ]);
    }
}
