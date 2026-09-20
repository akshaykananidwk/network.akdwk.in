<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\DB;

final class Invoice extends Model
{
    protected static string $table = 'invoices';
    protected static bool $tenantScoped = true;
    protected static bool $softDeletes = true;
    protected static array $jsonColumns = ['lines_json'];
    protected static array $sortable = ['id', 'issued_at', 'amount', 'status'];
    protected static array $fillable = [
        'tenant_id', 'subscription_id', 'invoice_number', 'amount', 'tax_amount', 'currency',
        'status', 'gateway', 'gateway_payment_id', 'issued_at', 'due_at', 'paid_at', 'lines_json', 'pdf_path',
    ];

    /** Sequential per calendar year: INV-2026-000123. */
    public static function nextNumber(): string
    {
        $year = gmdate('Y');
        $prefix = 'INV-' . $year . '-';
        $last = DB::scalar(
            'SELECT invoice_number FROM ' . self::tableName() . '
             WHERE invoice_number LIKE :p ORDER BY id DESC LIMIT 1',
            ['p' => $prefix . '%']
        );

        $sequence = is_string($last) ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix . str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
    }
}
