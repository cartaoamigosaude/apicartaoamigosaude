<?php

namespace App\Jobs;
use Illuminate\Support\Facades\Http;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Helpers\CelCash;

class CelCashMigrarClienteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public $tries 			    = 0;
    public $timeout 		    = 86400;
	  public $failOnTimeout 	= false;
	  public $id;
	
    public function __construct($cliente)
    {
		    $this->cliente           = $cliente;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
		    $return               = CelCash::CelCashMigrarCliente($this->cliente);	
    }
}
