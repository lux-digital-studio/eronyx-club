<?php

declare(strict_types=1);

namespace App\Core;

final class Layout
{
    public static function render(string $title, string $content, string $bodyClass = ''): void
    {
        $pageTitle = $title;
        $nav = Nav::context();

        require dirname(__DIR__) . '/Views/layouts/main.php';
    }

    public static function url(string $path = '/'): string
    {
        static $base = null;

        if ($base === null) {
            $config = require dirname(__DIR__, 2) . '/config/app.php';
            $base = rtrim((string) $config['url'], '/');
        }

        return $base . '/' . ltrim($path, '/');
    }

    public static function escape(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function formatPrice(mixed $amount, mixed $currency = 'EUR'): string
    {
        $raw = trim((string) $amount);

        if (preg_match('/\A(-?)(\d+)(?:\.(\d{1,2}))?\z/', $raw, $matches) === 1) {
            $formatted = $matches[1] . $matches[2] . ',' . str_pad($matches[3] ?? '00', 2, '0');
        } else {
            $formatted = str_replace('.', ',', $raw);
        }

        $code = strtoupper(trim((string) $currency));

        if ($code === 'EUR' || $code === '€') {
            return $formatted . ' €';
        }

        return $code === '' ? $formatted : $formatted . ' ' . $code;
    }

    public static function listingTypeLabel(string $type): string
    {
        return match ($type) {
            'physical_product' => 'Producto',
            'digital_content' => 'Digital',
            'service' => 'Servicio',
            'bundle' => 'Pack',
            default => $type,
        };
    }

    public static function visibilityLabel(string $visibility): string
    {
        return match ($visibility) {
            'public' => 'Público',
            'private' => 'Privado',
            'unlisted' => 'No listado',
            default => $visibility,
        };
    }

    public static function usageLabel(string $usage): string
    {
        return match ($usage) {
            'cover' => 'Portada',
            'gallery' => 'Galería',
            'preview' => 'Preview',
            'private_content' => 'Privado',
            default => $usage,
        };
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'draft' => 'Borrador',
            'pending_review' => 'En revisión',
            'published' => 'Publicado',
            'rejected' => 'Rechazado',
            'open' => 'Abierto',
            'in_review' => 'En revisión',
            'resolved' => 'Resuelto',
            'dismissed' => 'Descartado',
            'active' => 'Activo',
            'suspended' => 'Suspendido',
            'paid' => 'Pagado',
            'completed' => 'Completado',
            'pending' => 'Pendiente',
            'fulfilled' => 'Entregado',
            'removed' => 'Eliminado',
            'none' => 'Sin solicitud',
            default => $status,
        };
    }

    public static function statusBadgeClass(string $status): string
    {
        return match ($status) {
            'draft', 'none' => 'badge badge-draft',
            'pending_review', 'pending' => 'badge badge-pending',
            'published' => 'badge badge-published',
            'active' => 'badge badge-active',
            'rejected' => 'badge badge-rejected',
            'open' => 'badge badge-pending',
            'in_review' => 'badge badge-cover',
            'resolved' => 'badge badge-published',
            'dismissed' => 'badge badge-draft',
            'suspended' => 'badge badge-suspended',
            'paid' => 'badge badge-paid',
            'completed' => 'badge badge-completed',
            'fulfilled' => 'badge badge-fulfilled',
            'removed' => 'badge badge-removed',
            default => 'badge',
        };
    }

    public static function reportReasonLabel(string $reason): string
    {
        return match ($reason) {
            'spam' => 'Spam',
            'scam' => 'Estafa',
            'harassment' => 'Acoso',
            'illegal_content' => 'Contenido ilegal',
            'underage_concern' => 'Posible menor / preocupación sobre edad',
            'non_consensual_content' => 'Contenido no consentido',
            'misleading' => 'Engañoso',
            'prohibited_item' => 'Artículo prohibido',
            'other' => 'Otro',
            default => $reason,
        };
    }

    public static function reportTargetLabel(string $type): string
    {
        return match ($type) {
            'listing' => 'Publicación',
            'user' => 'Usuario',
            'message' => 'Mensaje',
            'report' => 'Reporte',
            default => $type,
        };
    }

    public static function moderationActionLabel(string $action): string
    {
        return match ($action) {
            'listing_suspend' => 'Suspensión de publicación',
            'listing_restore' => 'Restauración de publicación',
            'creator_suspend' => 'Suspensión de creator',
            'creator_restore' => 'Restauración de creator',
            'report_resolve' => 'Reporte resuelto',
            'report_dismiss' => 'Reporte descartado',
            'message_flag' => 'Mensaje señalado',
            default => $action,
        };
    }

    public static function auditEventLabel(string $event): string
    {
        return match ($event) {
            'report_created' => 'Reporte creado',
            'report_in_review' => 'Reporte en revisión',
            'report_resolved' => 'Reporte resuelto',
            'report_dismissed' => 'Reporte descartado',
            'listing_suspended' => 'Publicación suspendida',
            'listing_restored' => 'Publicación restaurada',
            'creator_suspended' => 'Creator suspendido',
            'creator_restored' => 'Creator restaurado',
            'moderator_action' => 'Acción de moderación',
            default => $event,
        };
    }

    public static function formatBytes(mixed $bytes): string
    {
        $size = max(0, (int) $bytes);

        if ($size < 1024) {
            return $size . ' B';
        }

        $kilobytes = intdiv($size, 1024);

        if ($kilobytes < 1024) {
            return $kilobytes . ' KB';
        }

        return intdiv($kilobytes, 1024) . ' MB';
    }
}
