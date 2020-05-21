<?php

namespace Office365Mail\Transport;

use Illuminate\Mail\Transport\Transport;
use Swift_Mime_SimpleMessage;
use Microsoft\Graph\Graph;

class Office365MailTransport extends Transport
{

    public function __construct()
    {

    }

    public function send(Swift_Mime_SimpleMessage $message, &$failedRecipients = null)
    {

        $this->beforeSendPerformed($message);

        $graph = new Graph();

        $graph->setAccessToken($this->getAccessToken());

        $graph->createRequest("POST", "/users/".key($message->getFrom())."/sendmail")->attachBody($this->getBody($message))->execute();
        
        $this->sendPerformed($message);

        return $this->numberOfRecipients($message);
    }

    /**
     * Get body for the message.
     *
     * @param \Swift_Mime_SimpleMessage $message
     * @return array
     */

    protected function getBody(Swift_Mime_SimpleMessage $message)
    {
        return [
            'message' => 
                [
                    'from' => [
                        'emailAddress' => [
                            'address' => key($message->getFrom()),
                            'name' => current($message->getFrom())    
                        ]
                    ],
                    'toRecipients' => $this->getTo($message),
                    'subject' => $message->getSubject(),
                    'body' => [
                        'contentType' => $message->getBodyContentType() == "text/html" ? 'html' : 'text',
                        'content' => $message->getBody()
                    ]
                ]
        ];
    }

    /**
     * Get the "to" payload field for the API request.
     *
     * @param \Swift_Mime_SimpleMessage $message
     * @return string
     */
    protected function getTo(Swift_Mime_SimpleMessage $message)
    {
        return collect($this->allContacts($message))->map(function ($display, $address) {
            return $display ? [
                'emailAddress' => [
                    'address' => $address,
                    'name' => $display
                ]
            ] : [
                'emailAddress' => [
                    'address' => $address
                ]
            ];
        })->values()->toArray();
    }

    /**
     * Get all of the contacts for the message.
     *
     * @param \Swift_Mime_SimpleMessage $message
     * @return array
     */
    protected function allContacts(Swift_Mime_SimpleMessage $message)
    {
        return array_merge(
            (array) $message->getTo(),
            (array) $message->getCc(),
            (array) $message->getBcc()
        );
    }

    protected function getAccessToken()
    {
        $guzzle = new \GuzzleHttp\Client();
        $url = 'https://login.microsoftonline.com/' . config('office365mail.tenant') . '/oauth2/v2.0/token';
        $token = json_decode($guzzle->post($url, [
            'form_params' => [
                'client_id' => config('office365mail.client_id'),
                'client_secret' => config('office365mail.client_secret'),
                'scope' => 'https://graph.microsoft.com/.default',
                'grant_type' => 'client_credentials',
            ],
        ])->getBody()->getContents());
        
        return $token->access_token;
    }
}
