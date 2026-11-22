<?php

namespace App\Services;

use App\Models\Component;
use App\Models\Material;
use App\Models\WastageLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class WastageService
{
    public function __construct(private Costing $costing, private StockService $stock) {}

    public function material(Material $material, float $qty, string $loggedOn, ?string $reason = null, ?string $notes = null): WastageLog
    {
        return DB::transaction(function () use ($material, $qty, $loggedOn, $reason, $notes) {
            $log = WastageLog::query()->create([
                'logged_on' => $loggedOn,
                'scope' => 'material',
                'material_id' => $material->id,
                'qty' => $qty,
                'cost' => round($qty * $material->unit_cost, 2),
                'usage' => [$material->id => $qty],
                'reason' => $reason,
                'notes' => $notes,
            ]);
            $this->stock->move($material->id, -$qty, 'wastage', $log, Carbon::parse($loggedOn));

            return $log;
        });
    }

    public function component(Component $component, float $qty, string $loggedOn, ?string $reason = null, ?string $notes = null): WastageLog
    {
        return DB::transaction(function () use ($component, $qty, $loggedOn, $reason, $notes) {
            $usage = $this->costing->componentStockUsage($component, $qty);
            $log = WastageLog::query()->create([
                'logged_on' => $loggedOn,
                'scope' => 'product',
                'component_id' => $component->id,
                'qty' => $qty,
                'cost' => round($this->costing->componentUnitCost($component) * $qty, 2),
                'usage' => $usage,
                'reason' => $reason,
                'notes' => $notes,
            ]);
            $this->stock->deduct($usage, 'wastage', $log, Carbon::parse($loggedOn));

            return $log;
        });
    }

    public function business(float $amount, string $loggedOn, ?string $reason = null, ?string $notes = null): WastageLog
    {
        return WastageLog::query()->create([
            'logged_on' => $loggedOn,
            'scope' => 'business',
            'cost' => round($amount, 2),
            'reason' => $reason,
            'notes' => $notes,
        ]);
    }

    public function delete(WastageLog $log): void
    {
        DB::transaction(function () use ($log) {
            $this->stock->restore($log->usage ?? [], 'adjustment', $log);
            $log->delete();
        });
    }
}
