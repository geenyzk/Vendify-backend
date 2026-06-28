<?php

namespace App;

trait HasServers
{
    //
    public function getServersAttribute()
    {
        $servers = [
            'adex' => [],
            'spurs' => [],
            'msorg' => [],
        ];

        foreach (range(1, 5) as $i) {
            $servers['adex'][$i] = [
                "adex_server_$i" => $this->{"adex_server_$i"} ?? "0",
                "adex_server_{$i}_cost_price" => $this->{"adex_server_{$i}_cost_price"} ?? "0",
                // "adex_server_{$i}_margin_profit" => $this->{"adex_server_{$i}_margin_profit"} ?? "0",
            ];
            $servers['spurs'][$i] = [
                "spurs_server_$i" => $this->{"spurs_server_$i"} ?? "0",
                "spurs_server_{$i}_cost_price" => $this->{"spurs_server_{$i}_cost_price"} ?? "0",
                // "spurs_server_{$i}_margin_profit" => $this->{"spurs_server_{$i}_margin_profit"} ?? "0",
            ];
            $servers['msorg'][$i] = [
                "msorg_server_$i" => $this->{"msorg_server_$i"} ?? "0",
                "msorg_server_{$i}_cost_price" => $this->{"msorg_server_{$i}_cost_price"} ?? "0",
                // "msorg_server_{$i}_margin_profit" => $this->{"msorg_server_{$i}_margin_profit"} ?? "0",
            ];
        }

        $servers['payscribe'] = $this->payscribe ?? "0";
        $servers['vtpass']    = $this->vtpass ?? "0";

        return $servers;
    }
}
