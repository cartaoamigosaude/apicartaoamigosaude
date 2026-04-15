<?php

namespace App\Jobs;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;
use App\Helpers\CelCash;

class CelCashWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public $tries 						= 1;
    public $timeout 					= 86400;
	public $failOnTimeout 				= false;
	public $payload;
	
    public function __construct($payload)
    {
        $this->payload  				= $payload;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
		
		$response 					 = CelCash::celcashWebhook($this->payload);
    
		Log::info("CelCashWebhookJob", ['payload'	=> $this->payload,
									    'response'  => $response
									   ]);
    }
	
	public function failed(Throwable $exception)
    {
	}
}
