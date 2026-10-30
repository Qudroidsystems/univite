<?php

namespace App\Http\Controllers\Academics;

use App\Models\BillItem;
use App\Services\Payments\PaymentSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rule;

class BillItemController extends CrudController
{
    protected string $permission = 'bill items';
    protected string $title = 'Bill Items';
    protected string $singular = 'Bill item';
    protected string $icon = 'ri-price-tag-3-line';
    protected string $routeBase = 'bills.items';
    protected string $intro = 'The things a student can be billed for: tuition, acceptance fee, hostel, library, ICT, etc. Set the amounts under School Bills.';
    protected array $searchable = ['name', 'code'];

    protected function model(): string
    {
        return BillItem::class;
    }

    protected function query(Request $request): Builder
    {
        return parent::query($request)->reorder()->orderBy('name');
    }

    protected function rules(Request $request, ?Model $record): array
    {
        return [
            'name'          => ['required', 'string', 'max:255', Rule::unique('bill_items', 'name')->ignore($record?->id)],
            'code'          => ['required', 'string', 'max:20', Rule::unique('bill_items', 'code')->ignore($record?->id)],
            'description'   => ['nullable', 'string', 'max:1000'],
            'is_compulsory' => ['nullable', 'boolean'],
            'payment_mode'  => ['nullable', Rule::in(array_keys(PaymentSettings::PAYMENT_MODES))],
            'max_payments'  => ['nullable', 'integer', 'min:1', 'max:12'],
            'is_active'     => ['nullable', 'boolean'],
        ];
    }

    protected function prepare(array $data, Request $request, ?Model $record): array
    {
        $data['code'] = strtoupper($data['code']);
        $data['payment_mode'] = ($data['payment_mode'] ?? null) ?: null;
        $data['max_payments'] = ($data['max_payments'] ?? null) ?: null;

        return $data;
    }

    protected function fields(): array
    {
        return [
            ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'col' => 8, 'placeholder' => 'Tuition'],
            ['name' => 'code', 'label' => 'Code', 'type' => 'text', 'required' => true, 'col' => 4, 'placeholder' => 'TUI'],
            ['name' => 'description', 'label' => 'Description', 'type' => 'textarea', 'col' => 12],
            ['name' => 'payment_mode', 'label' => 'Paid', 'type' => 'select', 'options' => PaymentSettings::PAYMENT_MODES, 'blank' => 'School default (' . $this->defaultMode() . ')', 'col' => 7, 'help' => 'Separately gives this fee its own invoice, paid on its own.'],
            ['name' => 'max_payments', 'label' => 'Most payments allowed', 'type' => 'number', 'col' => 5, 'min' => 1, 'max' => 12, 'help' => '1 = paid at once. Blank = school default.'],
            ['name' => 'is_compulsory', 'label' => 'Compulsory for every student', 'type' => 'switch', 'col' => 12, 'default' => true],
            ['name' => 'is_active', 'label' => 'Active', 'type' => 'switch', 'col' => 12, 'default' => true],
        ];
    }

    private function defaultMode(): string
    {
        return strtolower(PaymentSettings::PAYMENT_MODES[BillItem::defaultPaymentMode()] ?? 'on the invoice');
    }

    protected function columns(): array
    {
        return [
            ['Code', fn ($r) => new HtmlString('<span class="badge bg-light text-body border">' . e($r->code) . '</span>')],
            ['Item', fn ($r) => new HtmlString('<span class="fw-semibold">' . e($r->name) . '</span>')],
            ['Type', fn ($r) => $r->is_compulsory ? 'Compulsory' : 'Optional'],
            ['Paid', fn ($r) => new HtmlString(e($r->paysSeparately() ? 'Separately' : 'On the invoice') . ($r->payment_mode ? '' : ' <span class="text-muted fs-12">(default)</span>'))],
            ['Payments', fn ($r) => ($n = $r->maxPayments()) ? ($n === 1 ? 'One payment' : "Up to {$n}") : 'No limit'],
            ['Status', fn ($r) => new HtmlString($r->is_active ? '<span class="badge bg-success-subtle text-success">Active</span>' : '<span class="badge bg-secondary-subtle text-secondary">Inactive</span>')],
        ];
    }
}
