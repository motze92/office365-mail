<?php 
namespace Office365Mail; 
 
use Illuminate\Mail\MailServiceProvider; 
use Office365Mail\Transport\Office365AddedTransportManager;

class Office365MailServiceProvider extends MailServiceProvider 
{ 
    public function boot() 
    { 
    $this->publishes([
            __DIR__.'/config/office365mail.php' => config_path('office365mail.php')
        ], 'office365mail');
    }
 
    protected function registerSwiftTransport()
    {
        $this->app->singleton('swift.transport', function ($app) {
            return new Office365AddedTransportManager($app);
        });
    }
}